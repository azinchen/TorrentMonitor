<?php
namespace TorrentMonitor\Engine;

use TorrentMonitor\App;
use TorrentMonitor\Data\News;
use TorrentMonitor\Data\Settings;
use TorrentMonitor\Data\State;
use TorrentMonitor\Data\Topics;
use TorrentMonitor\Data\Users;
use TorrentMonitor\Data\Warnings;
use TorrentMonitor\Http\Request;
use TorrentMonitor\Notify\Notifications;
use TorrentMonitor\Tracker\Registry;
use TorrentMonitor\Tracker\TrackerException;
use TorrentMonitor\Update\Release;
use TorrentMonitor\Update\Updater;

//Один запуск TorrentMonitor: проверка тем на трекерах, отслеживаемых пользователей,
//повтор передачи в torrent-клиент, проверка обновлений программы.
final class Runner
{
    //адреса для проверки подключения к интернету (достаточно ответа от любого)
    const CONNECTIVITY = array('https://www.google.com/generate_204', 'https://ya.ru/');

    /** @var callable function(string $line): void */
    private $out;
    private bool $debug;
    private bool $dryRun;
    private ?string $onlyTracker;
    private ?int $onlyTopic;

    //$options: tracker - проверить только этот трекер; topic - только тему с этим id;
    //dryRun - ничего не скачивать и не менять, только показать, что было бы сделано
    public function __construct(callable $out, array $options = array())
    {
        $this->out = $out;
        $this->debug = Settings::on('debug');
        $this->dryRun = ! empty($options['dryRun']);
        $this->onlyTracker = ! empty($options['tracker']) ? (string)$options['tracker'] : NULL;
        $this->onlyTopic = ! empty($options['topic']) ? (int)$options['topic'] : NULL;
    }

    private function say(string $line): void
    {
        call_user_func($this->out, $line);
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

        $started = microtime(TRUE);
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
            App::setLogger(NULL);
            Lock::release();
        }
        if ($this->debug)
            $this->say(sprintf('Общее время работы: %.2f с', microtime(TRUE) - $started));
        return TRUE;
    }

    //есть ли подключение к интернету. Адреса проверки можно задать в config.php:
    //Config::write('connectivity.url', 'https://example.org/'); пустая строка отключает проверку.
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

    // -------------------------------------------------------------------- темы

    private function topics(): void
    {
        $stopAfter = (int)Settings::get('stopAfterDays');
        $topics = Topics::all();
        usort($topics, function ($a, $b) { return strcmp($a['tracker'], $b['tracker']) ?: strcmp($a['name'], $b['name']) ?: $a['id'] - $b['id']; });

        //отбор тем для проверки
        $byTracker = array();
        foreach ($topics as $topic)
        {
            if ($this->onlyTracker !== NULL && $topic['tracker'] != $this->onlyTracker)
                continue;
            if ($this->onlyTopic !== NULL && $topic['id'] != $this->onlyTopic)
                continue;
            $label = $topic['name'].' на трекере '.$topic['tracker'];
            if ($topic['paused'])
                $this->say($label.': наблюдение приостановлено.');
            elseif ($this->onlyTopic === NULL && Topics::expired($topic, $stopAfter))
                $this->say($label.': давно не обновлялась, проверка прекращена.');
            else
                $byTracker[$topic['tracker']][] = $topic;
        }

        //вход на трекеры и запросы страниц тем
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

        //страницы всех тем загружаются параллельно
        $responses = App::http()->sendAll($requests);

        foreach ($ready as $id => $topic)
        {
            $name = $topic['tracker'];
            $this->say($topic['name'].' на трекере '.$name);
            try
            {
                $found = Registry::get($name)->check($topic, $responses[$id]);
                $fields = array('error' => FALSE);
                foreach ($found as $release)
                {
                    if ($this->dryRun)
                    {
                        $this->say('Найдено обновление: '.$release->message.' ('.$release->dateText.')');
                        continue;
                    }
                    $changes = Delivery::deliver(array_merge($topic, $fields), $release, $this->out);
                    if ($changes === NULL)
                    {
                        $this->problem($name, 'save_file_fail', $topic);
                        $fields['error'] = TRUE;
                        break;
                    }
                    $fields = array_merge($fields, $changes);
                }
                if ( ! $this->dryRun)
                    Topics::update($id, $fields);
            }
            catch (TrackerException $e)
            {
                $this->problem($name, $e->getMessage(), $topic);
            }
            catch (\Throwable $e)
            {
                $this->say('Ошибка ('.$name.'): '.$e->getMessage());
                $this->problem($name, 'engine_error', $topic);
            }
        }
    }

    private function problem(string $source, string $code, ?array $topic = NULL): void
    {
        $this->say($source.': '.\TorrentMonitor\Messages::text($code));
        if ( ! $this->dryRun)
            Problems::report($source, $code, $topic);
    }

    // ------------------------------------------------------------ пользователи

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

                //темы, которые пользователь отметил для скачивания
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

    // -------------------------------------------------------------- обновление

    private function update(): void
    {
        $release = Release::latest();
        if ( ! Release::isNewer($release))
            return;
        if (Settings::on('autoUpdate'))
        {
            $this->say('Установка обновлений.');
            Updater::main();
            return;
        }
        if (State::updateNotified())
            return;
        $message = 'Выпущена новая версия ТМ '.$release['version'].', автоматическое обновление отключено, обновите систему самостоятельно.';
        if ( ! empty($release['notes']))
            $message .= "\n\n".$release['notes'];
        News::put('update_'.$release['version'], nl2br(htmlspecialchars($message)));
        Notifications::news($message);
        State::setUpdateNotified(TRUE);
    }
}
