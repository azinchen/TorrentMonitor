<?php
use TorrentMonitor\Data\Accounts;
use TorrentMonitor\Data\News;
use TorrentMonitor\Data\Pending;
use TorrentMonitor\Data\Settings;
use TorrentMonitor\Data\State;
use TorrentMonitor\Data\Topics;
use TorrentMonitor\Data\Users;
use TorrentMonitor\Data\Warnings;
use TorrentMonitor\Storage\Migrations;
use TorrentMonitor\Tracker\Registry;

//Данные в том виде, в каком их ждёт прежний веб-интерфейс (имена полей времён базы данных).
class Database
{
    // ------------------------------------------------------------------ helpers

    //качество по старому числу hd
    public static function quality($tracker, $hd)
    {
        return Migrations::quality((string)$tracker, $hd);
    }

    //старое число hd по качеству
    public static function hd($tracker, $quality)
    {
        if ($quality == 'sd')
            return 0;
        $lostfilm = ($tracker == 'lostfilm.tv' || $tracker == 'lostfilm-mirror');
        return ($quality == '1080') == $lostfilm ? 1 : 2;
    }

    private static function dateParts($timestamp)
    {
        $time = empty($timestamp) ? FALSE : strtotime($timestamp);
        if ($time === FALSE)
            return array('day' => NULL, 'month' => NULL, 'year' => NULL, 'time' => NULL);
        return array('day' => date('d', $time), 'month' => date('m', $time), 'year' => date('Y', $time), 'time' => date('H:i', $time));
    }

    private static function row(array $t)
    {
        return array(
            'id' => $t['id'], 'tracker' => $t['tracker'], 'name' => $t['name'], 'hd' => self::hd($t['tracker'], $t['quality']),
            'path' => $t['path'], 'torrent_id' => $t['topicId'], 'ep' => $t['episode'],
            'timestamp' => $t['updatedAt'] === '' ? '0000-00-00 00:00:00' : $t['updatedAt'],
            'auto_update' => (int)$t['autoRename'], 'hash' => $t['hash'], 'script' => $t['script'], 'pause' => (int)$t['paused'],
            'error' => (int)$t['error'], 'closed' => (int)$t['closed'], 'category' => $t['category'],
        );
    }

    private static function account($tracker)
    {
        $account = Accounts::get($tracker);
        $needs = Registry::needsAccount($tracker);
        return array(
            'id' => Registry::legacyId($tracker), 'tracker' => $tracker,
            //трекерам без авторизации исторически записывался пробел
            'login' => $account['login'] !== '' ? $account['login'] : ($needs ? '' : ' '),
            'password' => $account['password'] !== '' ? $account['password'] : ($needs ? '' : ' '),
            'passkey' => $account['passkey'], 'address' => $account['address'], 'cookie' => Accounts::cookie($tracker), 'necessarily' => (int)$needs,
        );
    }

    // ----------------------------------------------------------------- settings

    public static function getSetting($param)
    {
        return Settings::get((string)$param);
    }

    public static function getAllSetting()
    {
        $result = array();
        foreach (Settings::all() as $key => $val)
            $result[] = array($key => $val);
        return $result;
    }

    public static function updateSettings($setting, $val)
    {
        Settings::set((string)$setting, $val);
        return TRUE;
    }

    public static function updateCredentials($password)
    {
        Settings::set('password', $password);
        return TRUE;
    }

    public static function getTrackerProxy()
    {
        return Accounts::proxies();
    }

    public static function setTrackerProxy(array $proxy)
    {
        Accounts::setProxies($proxy);
        return TRUE;
    }

    //время последнего запуска в прежнем виде d-m-Y H:i:s
    public static function getLastStart()
    {
        $time = State::lastRun() === '' ? FALSE : strtotime(State::lastRun());
        return $time === FALSE ? '' : date('d-m-Y H:i:s', $time);
    }

    // ------------------------------------------------------------ notifications

    public static function getServiceList($type)
    {
        $result = array();
        foreach (Settings::notificationServices() as $id => $service)
            if ($service['type'] == $type)
                $result[] = array('id' => $id, 'service' => $service['service'], 'address' => Settings::notificationAddress($id));
        return $result;
    }

    public static function getService($type)
    {
        $target = Settings::notificationTarget((string)$type);
        return array('service' => $target['service'], 'address' => $target['service'] === NULL ? NULL : $target['address']);
    }

    public static function updateAddress($type, $service, $address)
    {
        $services = Settings::notificationServices();
        if (isset($services[$service]) && $services[$service]['type'] == $type)
            Settings::setNotificationAddress($service, (string)$address);
        return TRUE;
    }

    // ----------------------------------------------------- tracker credentials

    public static function getCredentials($tracker)
    {
        if ( ! Registry::has((string)$tracker))
            return NULL;
        $account = self::account($tracker);
        if ($account['login'] == '' || $account['password'] == '')
            return FALSE;
        return array('login' => $account['login'], 'password' => $account['password'], 'passkey' => $account['passkey']);
    }

    public static function getAllCredentials()
    {
        return array_map(array(__CLASS__, 'account'), Registry::names());
    }

    public static function setCredentials($id, $login, $password, $passkey, $address = NULL)
    {
        $tracker = Registry::byLegacyId($id);
        if ($tracker === NULL)
            return FALSE;
        $fields = array('login' => trim((string)$login), 'password' => trim((string)$password), 'passkey' => (string)$passkey);
        if ($address !== NULL)
            $fields['address'] = trim((string)$address);
        Accounts::set($tracker, $fields);
        return TRUE;
    }

    public static function checkTrackersCredentialsExist($tracker)
    {
        return Registry::ready((string)$tracker);
    }

    public static function getTrackersList()
    {
        return Registry::names();
    }

    public static function setCookie($tracker, $cookie)
    {
        Accounts::setCookie((string)$tracker, (string)$cookie);
        return TRUE;
    }

    // -------------------------------------------------------------------- topics

    public static function getTorrentsList($order, $dir = 'ASC')
    {
        $desc = strtoupper($dir) == 'DESC' ? -1 : 1;
        $list = array();
        foreach (Topics::all() as $topic)
        {
            $row = self::row($topic);
            $list[] = $row + self::dateParts($topic['updatedAt']) + array('type' => Registry::kind($topic['tracker']) == 'series' ? 'RSS' : 'forum');
        }
        usort($list, function ($a, $b) use ($order, $desc) {
            if ($order == 'date')
                return $desc * strcmp($a['timestamp'], $b['timestamp']) ?: $a['id'] - $b['id'];
            return strcmp($a['tracker'], $b['tracker'])
                ?: $desc * strcmp(mb_strtolower($a['name']), mb_strtolower($b['name']))
                ?: $a['hd'] - $b['hd']
                ?: $a['id'] - $b['id'];
        });
        return empty($list) ? NULL : $list;
    }

    public static function getTorrent($id)
    {
        $topic = Topics::get($id);
        return $topic === NULL ? NULL : array(self::row($topic));
    }

    public static function getDistinctCategories()
    {
        return Topics::categories();
    }

    public static function deletItem($id)
    {
        Topics::delete($id);
        return TRUE;
    }

    // ------------------------------------------------- watched users and buffer

    public static function getUserToWatch()
    {
        $users = Users::all();
        usort($users, function ($a, $b) { return strcmp($a['tracker'], $b['tracker']) ?: $a['id'] - $b['id']; });
        return empty($users) ? NULL : $users;
    }

    //TRUE, если такой пользователь ещё не отслеживается
    public static function checkUserExist($tracker, $name)
    {
        return ! Users::exists((string)$tracker, (string)$name);
    }

    public static function setUser($tracker, $name)
    {
        Users::add((string)$tracker, (string)$name);
        return TRUE;
    }

    public static function deletUser($id)
    {
        Users::delete($id);
        return TRUE;
    }

    public static function getThremesFromBuffer($user_id)
    {
        $items = Users::found(function ($f) use ($user_id) { return $f['userId'] == $user_id && $f['unread']; });
        usort($items, function ($a, $b) { return (int)$b['topicId'] - (int)$a['topicId']; });
        $result = array();
        foreach ($items as $f)
            $result[] = array('id' => $f['id'], 'section' => $f['section'], 'threme_id' => $f['topicId'], 'threme' => $f['title'],
                'timestamp' => $f['date'], 'tracker' => $f['tracker']);
        return empty($result) ? NULL : $result;
    }

    private static function byId($id)
    {
        return function ($f) use ($id) { return $f['id'] == $id; };
    }

    public static function transferFromBuffer($id)
    {
        $items = Users::found(self::byId($id));
        if (empty($items))
            return;
        if (Topics::find($items[0]['tracker'], $items[0]['topicId']) === NULL)
            Topics::add(array('tracker' => $items[0]['tracker'], 'name' => $items[0]['title'], 'topicId' => $items[0]['topicId']));
        self::deleteFromBuffer($id);
    }

    public static function deleteFromBuffer($id)
    {
        Users::changeFound(self::byId($id), array('unread' => FALSE));
        return TRUE;
    }

    public static function thremesClear($user_id)
    {
        Users::changeFound(function ($f) use ($user_id) { return $f['userId'] == $user_id; }, array('unread' => FALSE));
        return TRUE;
    }

    public static function updateThremesToDownload($id)
    {
        Users::changeFound(self::byId($id), array('accepted' => TRUE, 'unread' => FALSE));
        return TRUE;
    }

    // ----------------------------------------------------------------- warnings

    public static function getWarningsCount()
    {
        $result = array();
        foreach (Warnings::counts() as $where => $count)
            $result[] = array('where' => $where, 'count' => $count);
        return empty($result) ? NULL : $result;
    }

    public static function getWarningsCountSimple()
    {
        return array('count' => Warnings::count());
    }

    public static function getWarningsList($tracker)
    {
        $result = array();
        foreach (Warnings::all((string)$tracker) as $w)
        {
            $parts = self::dateParts($w['time']);
            $result[] = array('time' => $parts['time'], 'reason' => $w['code'], 'where' => $w['source'], 'id' => $w['topicId'],
                'day' => $parts['day'], 'month' => $parts['month'], 'year' => $parts['year']);
        }
        return empty($result) ? NULL : $result;
    }

    public static function clearWarnings($tracker)
    {
        Warnings::clear((string)$tracker);
        return TRUE;
    }

    // --------------------------------------------------------------- news, temp

    public static function getNews()
    {
        $result = array();
        foreach (News::all() as $n)
            $result[] = array('id' => $n['id'], 'text' => $n['text'], 'new' => (int)$n['unread']);
        return empty($result) ? NULL : $result;
    }

    public static function getNewsCount()
    {
        return array('count' => News::unreadCount());
    }

    public static function markNews($id)
    {
        News::markRead((string)$id);
        return TRUE;
    }

    public static function clearTemp()
    {
        Pending::clear();
        return TRUE;
    }
}
