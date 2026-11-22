<?php
use TorrentMonitor\App;
use TorrentMonitor\Data\Settings;
use TorrentMonitor\Engine\Delivery;
use TorrentMonitor\Http\Client;
use TorrentMonitor\Http\Request;
use TorrentMonitor\Messages;
use TorrentMonitor\Tracker\Registry;
use TorrentMonitor\Tracker\Tracker;
use TorrentMonitor\Update\Release;

//Вспомогательные функции прежнего веб-интерфейса.
class Sys
{
    const INTERNET_CHECK_URL = 'https://ya.ru/';

    public static function checkUrl($url)
    {
        return rtrim($url, '/');
    }

    public static function checkPath($path)
    {
        if (substr($path, -1) == '/' || Settings::get('torrentClient') == 'SynologyDS')
            return $path;
        return $path.'/';
    }

    public static function checkWriteToPath($path)
    {
        return is_writable($path);
    }

    //права доступа: 755 для каталогов, 644 для файлов
    public static function checkPermissions($root)
    {
        $root = rtrim($root, '/');
        $result = array('dirs' => array(), 'files' => array());
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $path => $info)
        {
            $relative = substr($path, strlen($root) + 1);
            //данные пользователя и служебные каталоги не проверяются
            if (preg_match('~^(\.git|data|torrents|tests|dist)(/|$)~', $relative))
                continue;
            $perms = fileperms($path) & 0777;
            if ($info->isDir() && $perms != 0755)
                $result['dirs'][$relative] = sprintf('%o', $perms);
            elseif ( ! $info->isDir() && $perms != 0644)
                $result['files'][$relative] = sprintf('%o', $perms);
        }
        return $result;
    }

    public static function version()
    {
        return array('system' => App::version(), 'database' => App::version());
    }

    public static function checkUpdate()
    {
        return Release::isNewer(Release::latest());
    }

    public static function formatBytes($bytes, $precision = 2)
    {
        if ($bytes <= 0)
            return '0 Б';
        $units = array('Б', 'КБ', 'МБ', 'ГБ', 'ТБ');
        $pow = min((int)floor(log($bytes, 1024)), count($units) - 1);
        return round($bytes / (1024 ** $pow), $precision).' '.$units[$pow];
    }

    //значение php.ini (например "128M") в байтах; -1 - без ограничения
    public static function iniToBytes($val)
    {
        $val = trim((string)$val);
        if ($val === '' || $val === '-1' || $val === '0')
            return -1;
        $num = (int)$val;
        switch (strtolower($val[strlen($val) - 1]))
        {
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
        }
        return $num;
    }

    public static function isCloudflarePage($body)
    {
        return Client::isChallengePage(403, '', (string)$body);
    }

    //Проверки доступности для страницы "Состояние": интернет, трекеры, torrent-клиент.
    //Возвращает ключ => array(http_code, body, error); ключи: internet, torrentclient, названия трекеров.
    public static function availability(array $trackers, $torrentAddress)
    {
        $requests = array('internet' => Request::get(self::INTERNET_CHECK_URL)->follow()->timeout(8));
        foreach ($trackers as $tracker)
            if (Registry::has($tracker))
                $requests[$tracker] = Request::get(Registry::get($tracker)->base().'/')->follow()->tracker($tracker)->timeout(10);
        if ( ! empty($torrentAddress))
        {
            if ( ! preg_match('~^https?://~i', $torrentAddress))
                $torrentAddress = 'http://'.$torrentAddress;
            $requests['torrentclient'] = Request::get(rtrim($torrentAddress, '/').'/')->plain()->timeout(5);
        }
        //обходить Cloudflare здесь не нужно: трекер за Cloudflare считается доступным
        $http = App::http();
        $http->solveChallenges = FALSE;
        $result = array();
        foreach ($http->sendAll($requests) as $key => $response)
            $result[$key] = array('http_code' => $response->status, 'body' => $response->body, 'error' => $response->error);
        $http->solveChallenges = TRUE;
        return $result;
    }

    //название темы по её адресу
    public static function getHeader($url)
    {
        $parsed = Registry::fromUrl($url);
        $name = NULL;
        if ($parsed !== NULL)
        {
            try
            {
                $tracker = Registry::get($parsed['tracker']);
                $tracker->login();
                $name = $tracker->title($parsed['topicId']);
            }
            catch (Throwable $e)
            {
            }
        }
        return empty($name) ? 'Неизвестный' : $name;
    }

    public static function removeFromClient($hash, $deleteData = NULL)
    {
        Delivery::removeFromClient((string)$hash, $deleteData);
    }

    public static function dateNumToString($date)
    {
        return preg_replace_callback('/\d+/', function ($m) { return Tracker::monthName($m[0]) ?: $m[0]; }, (string)$date, 1);
    }

    public static function strBoolToInt($value)
    {
        return ($value == 'true' || $value == 1) ? 1 : 0;
    }

    //авторизован ли пользователь (если авторизация включена)
    public static function checkAuth()
    {
        if (session_id() == '')
            session_start();
        if ( ! Settings::on('auth'))
            return TRUE;
        if (isset($_COOKIE['TM']))
            $_SESSION['TM'] = $_COOKIE['TM'];
        return ! empty($_SESSION['TM']) && hash_equals((string)Settings::get('password'), (string)$_SESSION['TM']);
    }
}

class Errors
{
    public static function getWarning($code)
    {
        return Messages::text((string)$code);
    }
}

class Url
{
    public static function create($tracker, $name, $torrent_id = FALSE, $hd = 0)
    {
        //на случай, если сохранено название с необработанной HTML-сущностью
        $name = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
        $safe = htmlspecialchars($name, ENT_QUOTES);
        $data = array('tracker' => $tracker, 'name' => $name, 'id' => $torrent_id, 'url' => $safe, 'quality' => FALSE);
        if ($torrent_id)
            $data['url'] = '<a href="'.htmlspecialchars(self::href($tracker, $torrent_id), ENT_QUOTES).'" target="_blank">'.$safe.'</a>';
        elseif (Registry::kind($tracker) == 'series')
        {
            $data['quality'] = Database::quality($tracker, $hd);
            if ($tracker == 'lostfilm.tv')
                $data['url'] = '<a href="'.htmlspecialchars(Registry::get($tracker)->searchUrl($name), ENT_QUOTES).'" target="_blank">'.$safe.'</a>';
        }
        return $data;
    }

    public static function href($tracker, $torrent_id)
    {
        return Registry::has($tracker) ? Registry::get($tracker)->topicUrl((string)$torrent_id) : '';
    }
}
