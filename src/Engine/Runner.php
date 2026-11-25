<?php
namespace TorrentMonitor\Engine;

use TorrentMonitor\App;
use TorrentMonitor\Data\Settings;
use TorrentMonitor\Data\State;
use TorrentMonitor\Data\Topics;
use TorrentMonitor\Data\Users;
use TorrentMonitor\Data\Warnings;
use TorrentMonitor\Http\Request;
use TorrentMonitor\Notify\Notifications;
use TorrentMonitor\Storage\Files;
use TorrentMonitor\Tracker\Registry;
use TorrentMonitor\Tracker\TrackerException;
use TorrentMonitor\Update\Release;
use TorrentMonitor\Update\Updater;

// A single TorrentMonitor run: checking topics on trackers, watched users,
// retrying the handover to the torrent client, checking for program updates.
final class Runner
{
    // Addresses for checking the internet connection (a response from any of them is enough)
    const CONNECTIVITY = array('https://www.google.com/generate_204', 'https://ya.ru/');

    /** @var callable function(string $line): void */
    private $out;
    private bool $debug;
    private bool $dryRun;
    private ?string $onlyTracker;
    private ?int $onlyTopic;
    private bool $fresh;
    //how the run was started, as the log and the journal say it
    private string $by;
    //what the run did, for its summary
    private array $count = array('checked' => 0, 'updated' => 0, 'failed' => 0, 'client' => 0, 'paused' => 0, 'stopped' => 0);
    /** @var int[] "source — what went wrong" => how many times */
    private array $errors = array();
    //names of the topics this run updated, for its line of the journal
    private array $updatedNames = array();
    //how many of them the journal names; the rest are counted
    const NAMED = 10;
    //the topic being checked, not written to the log yet: it is written when something happens to it
    private ?string $label = NULL;
    private bool $clientSeen = FALSE;
    private bool $offline = FALSE;

    // $options: tracker - check only this tracker; topic - only the topic with this id;
    // dryRun - download and change nothing, only show what would be done;
    // fresh - ask GitHub about a new version now instead of using the answer kept for six hours
    // (a run started by hand: the person expects an update that is out to be installed);
    // by - who started the run: manual (the interface), api; otherwise the schedule or the command line
    public function __construct(callable $out, array $options = array())
    {
        $this->out = $out;
        $this->debug = Settings::on('debug');
        $this->dryRun = ! empty($options['dryRun']);
        $this->onlyTracker = ! empty($options['tracker']) ? (string)$options['tracker'] : NULL;
        $this->onlyTopic = ! empty($options['topic']) ? (int)$options['topic'] : NULL;
        $this->fresh = ! empty($options['fresh']);
        $by = isset($options['by']) ? (string)$options['by'] : '';
        if ($by == 'manual')
            $this->by = 'вручную';
        elseif ($by == 'api')
            $this->by = 'через API';
        else
            $this->by = PHP_SAPI == 'cli' && function_exists('posix_isatty') && @posix_isatty(STDOUT) ? 'из командной строки' : 'по расписанию';
    }

    /** @var resource|null the detailed log of the run (data/engine.log), kept only while «Подробный журнал
     *  проверки» is on. What a usual run did is in the journal: one line with its result. */
    private $log = NULL;

    // A line of the log. The usual log holds what happened: topics updated, errors, the result.
    private function say(string $line): void
    {
        //the topic the line is about is named first
        if ($this->label !== NULL)
        {
            $label = $this->label;
            $this->label = NULL;
            call_user_func($this->out, $label);
        }
        call_user_func($this->out, $line);
    }

    // A line of the detailed log only («Подробный журнал проверки»): every topic checked, every step
    private function detail(string $line): void
    {
        if ($this->debug)
            $this->say($line);
    }

    public static function logFile(): string
    {
        return \TorrentMonitor\Storage\Files::dir().'/engine.log';
    }

    public function run(): bool
    {
        if ( ! function_exists('curl_init'))
        {
            Problems::report('system', 'curl');
            $this->say(\TorrentMonitor\Messages::text('curl'));
            return FALSE;
        }
        if ( ! Lock::acquire())
        {
            $this->say('TorrentMonitor уже выполняется: этот запуск пропущен.');
            return FALSE;
        }
        //the news list of earlier versions is not used any more: it is dropped from the state file
        if (array_key_exists('news', Files::state()->read()))
            Files::state()->update(function (&$data) { unset($data['news']); });

        $started = microtime(TRUE);
        //errors that only last are not journalled again by this run
        Problems::remember();
        if ($this->debug)
        {
            $log = @fopen(self::logFile(), 'w');
            $this->log = $log === FALSE ? NULL : $log;
        }
        //a log left by an earlier detailed run would describe a run that is not the last one any more
        elseif ( ! $this->dryRun && is_file(self::logFile()))
            @unlink(self::logFile());
        // Delivery and script messages also go to the log
        $direct = $this->out;
        $this->out = function ($line) use ($direct) {
            if ($this->log !== NULL)
                @fwrite($this->log, '['.date('Y-m-d H:i:s').'] '.$line."\n");
            call_user_func($direct, $line);
        };
        try
        {
            foreach (About::lines($this->by.$this->scope()) as $line)
                $this->say($line);
            if ($this->debug)
                App::setLogger(function ($line) { $this->say('  '.$line); });
            $partial = $this->dryRun || $this->onlyTracker !== NULL || $this->onlyTopic !== NULL;
            if ( ! $this->dryRun)
                State::setLastRun(date('Y-m-d H:i:s'));

            $online = $this->online();
            if ($online)
            {
                Warnings::clear('system');
                $this->detail('Опрос новых раздач на трекерах:');
                $this->topics();
                if ( ! $partial)
                {
                    $this->detail('Опрос новых раздач пользователей на трекерах:');
                    $this->users();
                }
            }
            else
            {
                $this->offline = TRUE;
                $this->say(\TorrentMonitor\Messages::text('no_internet'));
                if (Warnings::count('system') == 0)
                    Problems::report('system', 'no_internet');
            }

            if ( ! $partial)
            {
                $this->detail('Передача в torrent-клиент отложенных раздач.');
                Delivery::retryPending(function ($line) { $this->say($line); });
                $this->detail('Удаление старых torrent-файлов.');
                Delivery::cleanFiles();
                if ($online)
                    $this->update();
            }
        }
        finally
        {
            $this->label = NULL;
            $this->finish(microtime(TRUE) - $started);
            App::setLogger(NULL);
            Lock::release();
            $this->out = $direct;
            if ($this->log !== NULL)
                fclose($this->log);
            $this->log = NULL;
        }
        return TRUE;
    }

    // Whether there is an internet connection. The check addresses can be set in config.php:
    // Config::write('connectivity.url', 'https://example.org/'); an empty string disables the check.
    private function online(): bool
    {
        $configured = \Config::read('connectivity.url');
        $urls = $configured === NULL ? self::CONNECTIVITY : array_filter((array)$configured);
        if (empty($urls))
            return TRUE;
        $requests = array();
        foreach ($urls as $url)
            $requests[] = Request::get($url)->timeout(8)->binary();
        $http = App::http();
        foreach ($http->sendAll($requests) as $response)
            if ($response->status != 0)
                return TRUE;
        return FALSE;
    }

    // -------------------------------------------------------------------- topics

    private function topics(): void
    {
        $stopAfter = (int)Settings::get('stopAfterDays');
        $topics = Topics::all();
        usort($topics, function ($a, $b) { return strcmp($a['tracker'], $b['tracker']) ?: strcmp($a['name'], $b['name']) ?: $a['id'] - $b['id']; });

        // Selecting topics to check
        $byTracker = array();
        foreach ($topics as $topic)
        {
            if ($this->onlyTracker !== NULL && $topic['tracker'] != $this->onlyTracker)
                continue;
            if ($this->onlyTopic !== NULL && $topic['id'] != $this->onlyTopic)
                continue;
            $label = self::label($topic);
            if ($topic['paused'])
            {
                $this->count['paused']++;
                $this->detail($label.': наблюдение приостановлено.');
            }
            elseif ($this->onlyTopic === NULL && Topics::expired($topic, $stopAfter))
            {
                $this->count['stopped']++;
                $this->detail($label.': давно не обновлялась, проверка прекращена.');
            }
            else
                $byTracker[$topic['tracker']][] = $topic;
        }

        // Logging in to trackers and requesting topic pages
        $requests = array();
        $ready = array();
        foreach ($byTracker as $name => $list)
        {
            if ( ! $this->dryRun)
                Warnings::clear($name);
            if ( ! Registry::has($name))
            {
                $this->problem($name, 'unknown_tracker');
                continue;
            }
            try
            {
                $tracker = Registry::get($name);
                $tracker->probe = $this->dryRun;
                $tracker->login();
                foreach ($list as $topic)
                {
                    $request = $tracker->request($topic);
                    if ($request !== NULL)
                    {
                        $requests[$topic['id']] = $request;
                        $ready[$topic['id']] = $topic;
                    }
                }
            }
            catch (TrackerException $e)
            {
                $this->problem($name, $e->getMessage());
            }
            catch (\Throwable $e)
            {
                $this->say('Ошибка ('.$name.'): '.$e->getMessage());
                $this->problem($name, 'engine_error');
            }
        }

        // The pages are loaded in parallel, a part of the topics at a time: however many topics there
        // are, only the pages of one part are held in memory. What the run finds is saved once per
        // part (sooner if handling a part takes long), not topic by topic: every save rewrites the whole file.
        $this->savedAt = microtime(TRUE);
        try
        {
            foreach ($this->parts($ready) as $part)
            {
                $responses = App::http()->sendAll(array_intersect_key($requests, $part));
                foreach ($part as $id => $topic)
                {
                    $this->checkTopic($topic, $responses[$id]);
                    unset($responses[$id]);
                    $this->save();
                }
                //nothing waits unsaved while the next pages are being loaded
                $this->save(TRUE);
            }
        }
        finally
        {
            $this->save(TRUE);
        }
    }

    //how many topics are loaded, and then saved, at a time
    const PART = 100;
    //within a part, what is found is saved when it has waited this many seconds
    const SAVE_SECONDS = 3;

    /** @var array[] topic id => fields to save */
    private array $changes = array();
    /** @var array[] errors of topics to record: array(source, code, topic) */
    private array $failures = array();
    private float $savedAt = 0;

    // Splits the topics (id => topic) into parts loaded together. A part takes topics of every
    // tracker in turn: trackers are asked at the same time, each within its own limit of
    // simultaneous requests, so a part of one tracker only would wait for that one site.
    // The series of one tracker share a feed, which is requested once for all of them: they are
    // taken together and stay in one part.
    private function parts(array $topics): array
    {
        $queues = array();
        foreach ($topics as $id => $topic)
            $queues[$topic['tracker']][$id] = $topic;

        $parts = array();
        $part = array();
        while ( ! empty($queues))
        {
            foreach (array_keys($queues) as $tracker)
            {
                $take = Registry::kind($tracker) == 'series' ? count($queues[$tracker]) : 1;
                foreach (array_slice($queues[$tracker], 0, $take, TRUE) as $id => $topic)
                {
                    $part[$id] = $topic;
                    unset($queues[$tracker][$id]);
                }
                if (empty($queues[$tracker]))
                    unset($queues[$tracker]);
                if (count($part) >= self::PART)
                {
                    $parts[] = $part;
                    $part = array();
                }
            }
        }
        if ( ! empty($part))
            $parts[] = $part;
        return $parts;
    }

    private function checkTopic(array $topic, $response): void
    {
        $id = $topic['id'];
        $name = $topic['tracker'];
        $this->count['checked']++;
        //the detailed log names every topic; the usual one only those something happened to
        if ($this->debug)
            $this->say(self::label($topic));
        else
            $this->label = self::label($topic);
        $out = function ($line) { $this->say($line); };
        try
        {
            $found = Registry::get($name)->check($topic, $response);
            $fields = array('error' => FALSE);
            $delivered = FALSE;
            foreach ($found as $release)
            {
                if ($this->dryRun)
                {
                    $this->say('Найдено обновление: '.$release->message.' ('.(\TorrentMonitor\Time::both($release->date, $name) ?: $release->dateText).')');
                    continue;
                }
                $changes = Delivery::deliver(array_merge($topic, $fields), $release, $out);
                if ($changes === NULL)
                {
                    $this->topicProblem($name, 'save_file_fail', $topic);
                    $fields['error'] = TRUE;
                    break;
                }
                $delivered = TRUE;
                if (isset($changes['hash']))
                {
                    $this->count['client']++;
                    //the client has just been talked to: a good moment to note what it is
                    if ( ! $this->clientSeen)
                    {
                        $this->clientSeen = TRUE;
                        About::seeClient();
                    }
                }
                $fields = array_merge($fields, $changes);
            }
            if ($delivered && empty($fields['error']))
            {
                $this->count['updated']++;
                //a topic added a moment ago gets its name in this very run
                $known = isset($fields['name']) && $fields['name'] !== '' ? (string)$fields['name'] : (string)$topic['name'];
                $this->updatedNames[] = $known !== '' ? $known : 'тема '.$topic['topicId'].' ('.$topic['tracker'].')';
            }
            if ( ! $this->dryRun)
                $this->changes[$id] = $fields;
        }
        catch (TrackerException $e)
        {
            $this->topicProblem($name, $e->getMessage(), $topic);
        }
        catch (\Throwable $e)
        {
            $this->say('Ошибка ('.$name.'): '.$e->getMessage());
            $this->topicProblem($name, 'engine_error', $topic);
        }
        $this->label = NULL;
    }

    //an error of one topic: recorded and marked on the topic with the next save
    private function topicProblem(string $source, string $code, array $topic): void
    {
        $this->say($source.': '.\TorrentMonitor\Messages::text($code));
        $this->count['failed']++;
        $this->noteError($source, $code);
        if ($this->dryRun)
            return;
        $this->failures[] = array($source, $code, $topic);
        $this->changes[$topic['id']] = array('error' => TRUE) + (isset($this->changes[$topic['id']]) ? $this->changes[$topic['id']] : array());
    }

    // Writes what was collected, when it has waited long enough; $now - in any case.
    // A run that is cut off loses at most that: those topics are checked again by the next run.
    private function save(bool $now = FALSE): void
    {
        if (empty($this->changes) && empty($this->failures))
        {
            $this->savedAt = microtime(TRUE);
            return;
        }
        if ( ! $now && microtime(TRUE) - $this->savedAt < self::SAVE_SECONDS)
            return;
        $changes = $this->changes;
        $failures = $this->failures;
        $this->changes = array();
        $this->failures = array();
        $this->savedAt = microtime(TRUE);
        Topics::updateMany($changes);
        if ( ! empty($failures))
            Problems::reportTopics($failures);
    }

    //counts what went wrong, by source and kind, for the result of the run
    private function noteError(string $source, string $code): void
    {
        $key = $source.' — '.rtrim(\TorrentMonitor\Messages::text($code), '.');
        $this->errors[$key] = (isset($this->errors[$key]) ? $this->errors[$key] : 0) + 1;
    }

    //what a run of a part of the topics is limited to, for the first line of the log
    private function scope(): string
    {
        if ($this->dryRun)
            return ', пробный (ничего не скачивается и не меняется)';
        if ($this->onlyTopic !== NULL)
            return ', только тема '.$this->onlyTopic;
        return $this->onlyTracker !== NULL ? ', только трекер '.$this->onlyTracker : '';
    }

    // The result of the run: the last lines of its log, the line above the log in the interface
    // and a line of the journal.
    private function finish(float $seconds): void
    {
        $c = $this->count;
        if ($this->offline)
            $result = 'Нет подключения к интернету: темы не проверялись.';
        else
        {
            $result = 'Проверено тем: '.$c['checked'].' — обновлено '.$c['updated'].', без изменений '.max(0, $c['checked'] - $c['updated'] - $c['failed'])
                .', с ошибками '.$c['failed'].'.';
            if (\TorrentMonitor\Client\Clients::currentName() !== NULL)
                $result .= ' Передано в torrent-клиент: '.$c['client'].'.';
            if ($c['paused'] + $c['stopped'] > 0)
                $result .= ' Пропущено: на паузе '.$c['paused'].', проверка прекращена '.$c['stopped'].'.';
        }
        //what went wrong, the most frequent first; the journal has room for three kinds
        arsort($this->errors);
        $errors = array();
        foreach ($this->errors as $what => $times)
            $errors[] = $what.($times > 1 ? ' ×'.$times : '');

        $this->say($result);
        if ( ! empty($errors))
            $this->say('Ошибки: '.implode('; ', $errors).'.');
        $this->say(sprintf('Готово за %.1f с.', $seconds));

        if ($this->dryRun)
            return;
        if (App::http()->solverUsed)
            About::seeSolver();
        State::setLastRunSummary($result.(empty($errors) ? '' : ' Ошибки: '.implode('; ', $errors).'.'));
        $short = array_slice($errors, 0, 3);
        if (count($errors) > 3)
            $short[] = 'и другие';
        //which topics were updated: the journal is where the user looks for it
        $names = '';
        if ( ! empty($this->updatedNames))
        {
            $more = count($this->updatedNames) - self::NAMED;
            $names = ' Обновлено: '.implode('; ', array_slice($this->updatedNames, 0, self::NAMED)).($more > 0 ? ' и ещё '.$more : '').'.';
        }
        \TorrentMonitor\Data\Journal::add('run', 'Проверка ('.$this->by.$this->scope().'), '.sprintf('%.0f', $seconds).' с. '.$result.$names
            .(empty($short) ? '' : ' Ошибки: '.implode('; ', $short).'.'));
    }

    // How the topic is named in the log
    private static function label(array $topic): string
    {
        return ($topic['name'] !== '' ? $topic['name'] : 'Тема '.$topic['topicId']).' на трекере '.$topic['tracker'];
    }

    private function problem(string $source, string $code, ?array $topic = NULL): void
    {
        $this->say($source.': '.\TorrentMonitor\Messages::text($code));
        $this->noteError($source, $code);
        if ( ! $this->dryRun)
            Problems::report($source, $code, $topic);
    }

    // ------------------------------------------------------------ users

    private function users(): void
    {
        foreach (Users::all() as $user)
        {
            $name = $user['tracker'];
            $this->detail('Пользователь '.$user['name'].' на трекере '.$name);
            if ( ! Registry::has($name))
            {
                $this->problem($name, 'unknown_tracker');
                continue;
            }
            try
            {
                $tracker = Registry::get($name);
                $tracker->login();
                $topics = $tracker->userTopics($user['name']);
                foreach ($topics === NULL ? array() : $topics as $t)
                    Users::addFound($user['id'], $name, $t['section'], (string)$t['topicId'], $t['title'], $t['date']);

                // Topics the user marked for download
                $accepted = Users::found(function ($f) use ($name) { return $f['tracker'] == $name && $f['accepted'] && ! $f['downloaded']; });
                foreach ($accepted as $item)
                {
                    $torrent = $tracker->download($item['topicId']);
                    $file = Delivery::save($name, $item['topicId'], $torrent);
                    if ($file === NULL)
                        throw new TrackerException('save_file_fail');
                    $message = $item['title'].' добавлена для скачивания.';
                    $topic = array('id' => 0, 'tracker' => $name, 'topicId' => $item['topicId'], 'hash' => '', 'path' => '', 'category' => '', 'script' => '');
                    if (\TorrentMonitor\Client\Clients::currentName() !== NULL)
                        $message .= Delivery::toClient($topic, $item['title'], $file, date('d M Y H:i')) !== NULL
                            ? ' И добавлена в torrent-клиент.' : ' Но не добавлена в torrent-клиент и сохранена.';
                    Notifications::update($name, $message, date('d M Y H:i'), $tracker->topicUrl($item['topicId']));
                    Users::changeFound(function ($f) use ($item) { return $f['id'] == $item['id']; }, array('downloaded' => TRUE));
                    $this->say($message);
                }
            }
            catch (TrackerException $e)
            {
                $this->problem($name, $e->getMessage());
            }
            catch (\Throwable $e)
            {
                $this->say('Ошибка ('.$name.'): '.$e->getMessage());
                $this->problem($name, 'engine_error');
            }
        }
    }

    // -------------------------------------------------------------- update

    private function update(): void
    {
        $release = Release::latest($this->fresh);
        if ( ! Release::isNewer($release))
        {
            //nothing to install on a newly chosen channel: the change of the channel is complete
            if (is_array($release))
                State::setChannelChanged(FALSE);
            return;
        }
        if (Settings::on('autoUpdate'))
        {
            $this->say('Установка обновлений.');
            Updater::main();
            return;
        }
        if (State::updateNotified())
            return;
        $message = 'Выпущена новая версия ТМ '.$release['version'].', автоматическое обновление отключено, обновите систему самостоятельно.';
        //a notification cannot show the formatting of the description: its first sentence and the address
        $message .= self::about($release);
        Notifications::version($message);
        State::setUpdateNotified(TRUE);
    }

    //what a notification says about a version: the first sentence of its description and where to read the rest
    public static function about(array $release): string
    {
        $summary = Release::summary(isset($release['notes']) ? $release['notes'] : '');
        return ($summary !== '' ? "\n\n".$summary : '').( ! empty($release['url']) ? "\n\n".$release['url'] : '');
    }
}
