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

    // $options: tracker - check only this tracker; topic - only the topic with this id;
    // dryRun - download and change nothing, only show what would be done;
    // fresh - ask GitHub about a new version now instead of using the answer kept for six hours
    // (a run started by hand: the person expects an update that is out to be installed)
    public function __construct(callable $out, array $options = array())
    {
        $this->out = $out;
        $this->debug = Settings::on('debug');
        $this->dryRun = ! empty($options['dryRun']);
        $this->onlyTracker = ! empty($options['tracker']) ? (string)$options['tracker'] : NULL;
        $this->onlyTopic = ! empty($options['topic']) ? (int)$options['topic'] : NULL;
        $this->fresh = ! empty($options['fresh']);
    }

    /** @var resource|null run log (data/engine.log): it is shown by the "Status" page */
    private $log = NULL;

    private function say(string $line): void
    {
        call_user_func($this->out, $line);
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
        $log = @fopen(self::logFile(), 'w');
        $this->log = $log === FALSE ? NULL : $log;
        // Delivery and script messages also go to the log
        $direct = $this->out;
        $this->out = function ($line) use ($direct) {
            if ($this->log !== NULL)
                @fwrite($this->log, '['.date('Y-m-d H:i:s').'] '.$line."\n");
            call_user_func($direct, $line);
        };
        try
        {
            if ($this->debug)
                App::setLogger(function ($line) { $this->say('  '.$line); });
            $partial = $this->dryRun || $this->onlyTracker !== NULL || $this->onlyTopic !== NULL;
            if ( ! $this->dryRun)
                State::setLastRun(date('Y-m-d H:i:s'));

            $online = $this->online();
            if ($online)
            {
                Warnings::clear('system');
                $this->say('Опрос новых раздач на трекерах:');
                $this->topics();
                if ( ! $partial)
                {
                    $this->say('Опрос новых раздач пользователей на трекерах:');
                    $this->users();
                }
            }
            else
            {
                $this->say(\TorrentMonitor\Messages::text('no_internet'));
                if (Warnings::count('system') == 0)
                    Problems::report('system', 'no_internet');
            }

            if ( ! $partial)
            {
                $this->say('==================');
                $this->say('Выполнение служебных функций:');
                $this->say('Передача в torrent-клиент отложенных раздач.');
                Delivery::retryPending($this->out);
                $this->say('Удаление старых torrent-файлов.');
                Delivery::cleanFiles();
                if ($online)
                    $this->update();
            }
        }
        finally
        {
            $this->say(sprintf('Готово за %.1f с.', microtime(TRUE) - $started));
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
                $this->say($label.': наблюдение приостановлено.');
            elseif ($this->onlyTopic === NULL && Topics::expired($topic, $stopAfter))
                $this->say($label.': давно не обновлялась, проверка прекращена.');
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
        $this->say(self::label($topic));
        try
        {
            $found = Registry::get($name)->check($topic, $response);
            $fields = array('error' => FALSE);
            foreach ($found as $release)
            {
                if ($this->dryRun)
                {
                    $this->say('Найдено обновление: '.$release->message.' ('.(\TorrentMonitor\Time::both($release->date, $name) ?: $release->dateText).')');
                    continue;
                }
                $changes = Delivery::deliver(array_merge($topic, $fields), $release, $this->out);
                if ($changes === NULL)
                {
                    $this->topicProblem($name, 'save_file_fail', $topic);
                    $fields['error'] = TRUE;
                    break;
                }
                $fields = array_merge($fields, $changes);
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
    }

    //an error of one topic: recorded and marked on the topic with the next save
    private function topicProblem(string $source, string $code, array $topic): void
    {
        $this->say($source.': '.\TorrentMonitor\Messages::text($code));
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

    // How the topic is named in the log
    private static function label(array $topic): string
    {
        return ($topic['name'] !== '' ? $topic['name'] : 'Тема '.$topic['topicId']).' на трекере '.$topic['tracker'];
    }

    private function problem(string $source, string $code, ?array $topic = NULL): void
    {
        $this->say($source.': '.\TorrentMonitor\Messages::text($code));
        if ( ! $this->dryRun)
            Problems::report($source, $code, $topic);
    }

    // ------------------------------------------------------------ users

    private function users(): void
    {
        foreach (Users::all() as $user)
        {
            $name = $user['tracker'];
            $this->say('Пользователь '.$user['name'].' на трекере '.$name);
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
