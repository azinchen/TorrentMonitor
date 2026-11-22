<?php
include_once dirname(__FILE__).'/Storage.class.php';

//Доступ к данным TorrentMonitor. Имя класса и методы остались от версий с базой данных,
//но данные теперь лежат в JSON-файлах (см. Storage): база данных не используется.
class Database
{
    //читает все файлы данных; StorageException, если какой-то из них повреждён или недоступен
    public static function checkStorage()
    {
        Storage::config()->read();
        Storage::torrents()->read();
        Storage::state()->read();
    }

    // ------------------------------------------------------------------ helpers

    private static function now()
    {
        return date('Y-m-d H:i:s');
    }

    //индекс элемента списка с заданным id или NULL
    private static function indexById(array $list, $id)
    {
        foreach ($list as $i => $item)
            if ($item['id'] == $id)
                return $i;
        return NULL;
    }

    private static function topic($id)
    {
        $data = Storage::torrents()->read();
        $i = self::indexById($data['torrents'], $id);
        return $i === NULL ? NULL : $data['torrents'][$i];
    }

    //изменяет поля темы; возвращает TRUE, если тема найдена
    private static function changeTopic($id, array $fields)
    {
        $found = FALSE;
        Storage::torrents()->update(function (&$data) use ($id, $fields, &$found) {
            $i = Database::indexById($data['torrents'], $id);
            if ($i === NULL)
                return FALSE;
            $found = TRUE;
            $before = $data['torrents'][$i];
            $data['torrents'][$i] = Database::withActivity($before, $fields);
            return $data['torrents'][$i] !== $before;
        });
        return $found;
    }

    //применяет изменения к теме; смена даты раздачи считается её обновлением
    private static function withActivity(array $topic, array $fields)
    {
        if (isset($fields['timestamp']) && $fields['timestamp'] != $topic['timestamp'])
            $fields['lastActivityAt'] = self::now();
        return array_merge($topic, $fields);
    }

    private static function newTopic(array $fields)
    {
        return $fields + array(
            'tracker'        => '',
            'name'           => '',
            'hd'             => 0,
            'path'           => '',
            'torrent_id'     => '',
            'ep'             => '',
            'timestamp'      => '0000-00-00 00:00:00',
            'auto_update'    => 0,
            'hash'           => '',
            'script'         => '',
            'pause'          => 0,
            'error'          => 0,
            'closed'         => 0,
            'category'       => '',
            'addedAt'        => self::now(),
            'lastActivityAt' => '',
        );
    }

    private static function addTopic(array $fields)
    {
        Storage::torrents()->update(function (&$data) use ($fields) {
            $data['torrents'][] = array('id' => $data['nextId']++) + Database::newTopic($fields);
        });
        return TRUE;
    }

    //части даты для вывода в интерфейсе
    private static function dateParts($timestamp, $timeFormat = 'H:i')
    {
        $time = (empty($timestamp) || strpos($timestamp, '0000') === 0) ? FALSE : strtotime($timestamp);
        if ($time === FALSE)
            return array('day' => NULL, 'month' => NULL, 'year' => NULL, 'time' => NULL);
        return array('day' => date('d', $time), 'month' => date('m', $time), 'year' => date('Y', $time), 'time' => date($timeFormat, $time));
    }

    //учётные данные трекера с учётом значений по умолчанию; NULL для неизвестного трекера
    private static function trackerAccount($tracker)
    {
        $catalog = Catalog::trackers();
        if ( ! isset($catalog[$tracker]))
            return NULL;
        $config = Storage::config()->read();
        $state = Storage::state()->read();
        $saved = isset($config['trackers'][$tracker]) ? $config['trackers'][$tracker] : array();
        //трекерам без авторизации исторически записывался пробел: так движок считает их настроенными
        $blank = $catalog[$tracker]['necessarily'] ? '' : ' ';
        return array(
            'id'          => $catalog[$tracker]['id'],
            'tracker'     => $tracker,
            'login'       => isset($saved['login']) ? $saved['login'] : $blank,
            'password'    => isset($saved['password']) ? $saved['password'] : $blank,
            'passkey'     => isset($saved['passkey']) ? $saved['passkey'] : '',
            'cookie'      => isset($state['cookies'][$tracker]) ? $state['cookies'][$tracker] : '',
            'necessarily' => $catalog[$tracker]['necessarily'],
            'type'        => $catalog[$tracker]['type'],
        );
    }

    // ----------------------------------------------------------------- settings

    public static function getSetting($param)
    {
        $state = Storage::state()->read();
        if (array_key_exists($param, Storage::stateSettingsDefaults()))
            return isset($state['settings'][$param]) ? (string)$state['settings'][$param] : Storage::stateSettingsDefaults()[$param];

        $config = Storage::config()->read();
        if (isset($config['settings'][$param]))
            return (string)$config['settings'][$param];
        $defaults = Storage::settingsDefaults();
        return isset($defaults[$param]) ? $defaults[$param] : NULL;
    }

    //список вида array(array(ключ => значение), ...), как его ждут torrent-клиенты и страница настроек
    public static function getAllSetting()
    {
        $config = Storage::config()->read();
        $state = Storage::state()->read();
        $all = array_merge(
            Storage::settingsDefaults(), $config['settings'],
            Storage::stateSettingsDefaults(), $state['settings']
        );
        $result = array();
        foreach ($all as $key => $val)
            $result[] = array($key => (string)$val);
        return $result;
    }

    public static function updateSettings($setting, $val)
    {
        $val = (string)$val;
        $store = array_key_exists($setting, Storage::stateSettingsDefaults()) ? Storage::state() : Storage::config();
        $store->update(function (&$data) use ($setting, $val) {
            if (isset($data['settings'][$setting]) && $data['settings'][$setting] === $val)
                return FALSE;
            $data['settings'][$setting] = $val;
            return TRUE;
        });
        return TRUE;
    }

    public static function setUpdateNotification($param)
    {
        return self::updateSettings('sentUpdateNotification', $param ? '1' : '0');
    }

    public static function getUpdateNotification()
    {
        return self::getSetting('sentUpdateNotification') == 1;
    }

    public static function getCfUserAgent()
    {
        return (string)self::getSetting('cf_userAgent');
    }

    public static function setCfUserAgent($useragent)
    {
        return self::updateSettings('cf_userAgent', $useragent);
    }

    public static function getProxy()
    {
        $result = array();
        foreach (array('proxy', 'proxyAddress', 'proxyType') as $key)
            $result[] = array('key' => $key, 'val' => self::getSetting($key));
        return $result;
    }

    //прокси для отдельных трекеров: трекер => array('use' => 'yes'|'no', 'address' => ..., 'type' => ...)
    public static function getTrackerProxy()
    {
        $config = Storage::config()->read();
        return is_array($config['trackerProxy']) ? $config['trackerProxy'] : array();
    }

    public static function setTrackerProxy(array $proxy)
    {
        Storage::config()->update(function (&$data) use ($proxy) {
            $data['trackerProxy'] = $proxy;
        });
        return TRUE;
    }

    //время последнего запуска движка
    public static function setLastStart($date)
    {
        Storage::state()->update(function (&$data) use ($date) {
            $data['lastStart'] = $date;
        });
    }

    public static function getLastStart()
    {
        $state = Storage::state()->read();
        return (string)$state['lastStart'];
    }

    //пароль на вход в веб-интерфейс
    public static function countCredentials($password)
    {
        return self::getSetting('password') === (string)$password;
    }

    public static function updateCredentials($password)
    {
        return self::updateSettings('password', $password);
    }

    // ------------------------------------------------------------ notifications

    public static function getServiceList($type)
    {
        $config = Storage::config()->read();
        $result = array();
        foreach (Catalog::notifications() as $id => $service)
        {
            if ($service['type'] != $type)
                continue;
            $result[] = array(
                'id'      => $id,
                'service' => $service['service'],
                'address' => isset($config['notifications'][$id]) ? $config['notifications'][$id] : '',
            );
        }
        return empty($result) ? NULL : $result;
    }

    //сервис, выбранный в настройке $type (sendUpdateService или sendWarningService)
    public static function getService($type)
    {
        $id = self::getSetting($type);
        $catalog = Catalog::notifications();
        if ( ! isset($catalog[$id]))
            return array('service' => NULL, 'address' => NULL);
        $config = Storage::config()->read();
        return array(
            'service' => $catalog[$id]['service'],
            'address' => isset($config['notifications'][$id]) ? $config['notifications'][$id] : '',
        );
    }

    public static function updateAddress($type, $service, $address)
    {
        $catalog = Catalog::notifications();
        if ( ! isset($catalog[$service]) || $catalog[$service]['type'] != $type)
            return TRUE;
        Storage::config()->update(function (&$data) use ($service, $address) {
            $data['notifications'][(string)$service] = (string)$address;
        });
        return TRUE;
    }

    // ----------------------------------------------------- tracker credentials

    public static function getCredentials($tracker)
    {
        $account = self::trackerAccount($tracker);
        if ($account === NULL)
            return NULL;
        if ($account['login'] == '' || $account['password'] == '')
            return FALSE;
        return array('login' => $account['login'], 'password' => $account['password'], 'passkey' => $account['passkey']);
    }

    public static function getAllCredentials()
    {
        $trackers = array_keys(Catalog::trackers());
        sort($trackers);
        $result = array();
        foreach ($trackers as $tracker)
        {
            $account = self::trackerAccount($tracker);
            unset($account['type']);
            $result[] = $account;
        }
        return $result;
    }

    public static function setCredentials($id, $login, $password, $passkey)
    {
        $tracker = Catalog::trackerById($id);
        if ($tracker === NULL)
            return FALSE;
        Storage::config()->update(function (&$data) use ($tracker, $login, $password, $passkey) {
            $data['trackers'][$tracker] = array('login' => (string)$login, 'password' => (string)$password, 'passkey' => (string)$passkey);
        });
        return TRUE;
    }

    public static function checkTrackersCredentialsExist($tracker)
    {
        $account = self::trackerAccount($tracker);
        if ($account === NULL)
            return NULL;
        return ( ! empty($account['login']) && ! empty($account['password'])) || ! empty($account['cookie']);
    }

    public static function getTrackersList()
    {
        $trackers = array_keys(Catalog::trackers());
        sort($trackers);
        return $trackers;
    }

    public static function getCookie($tracker)
    {
        $account = self::trackerAccount($tracker);
        return $account === NULL ? NULL : $account['cookie'];
    }

    public static function setCookie($tracker, $cookie)
    {
        $cookie = (string)$cookie;
        Storage::state()->update(function (&$data) use ($tracker, $cookie) {
            if (isset($data['cookies'][$tracker]) && $data['cookies'][$tracker] === $cookie)
                return FALSE;
            $data['cookies'][$tracker] = $cookie;
            return TRUE;
        });
        return TRUE;
    }

    // -------------------------------------------------------------------- topics

    public static function getTorrentsList($order, $dir = 'ASC')
    {
        $data = Storage::torrents()->read();
        $catalog = Catalog::trackers();
        $desc = strtoupper($dir) == 'DESC' ? -1 : 1;

        $list = array();
        foreach ($data['torrents'] as $topic)
        {
            if ( ! isset($catalog[$topic['tracker']]))
                continue;
            $list[] = $topic + self::dateParts($topic['timestamp']) + array('type' => $catalog[$topic['tracker']]['type']);
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
        $topic = self::topic($id);
        return $topic === NULL ? NULL : array($topic);
    }

    public static function getTorrentFull($id)
    {
        $topic = self::topic($id);
        if ($topic === NULL)
            return NULL;
        return array(
            'id'          => (int)$topic['id'],
            'tracker'     => $topic['tracker'],
            'name'        => $topic['name'],
            'hd'          => (int)$topic['hd'],
            'path'        => $topic['path'],
            'torrent_id'  => $topic['torrent_id'],
            'ep'          => $topic['ep'],
            'timestamp'   => $topic['timestamp'],
            'auto_update' => (int)$topic['auto_update'],
            'script'      => $topic['script'],
            'pause'       => (int)$topic['pause'],
            'closed'      => (int)$topic['closed'],
            'hash'        => $topic['hash'],
            'error'       => (int)$topic['error'],
            'category'    => $topic['category'],
        );
    }

    public static function getPaths()
    {
        $data = Storage::torrents()->read();
        $result = array();
        foreach (array_unique(array_column($data['torrents'], 'path')) as $path)
            if ( ! empty($path))
                $result[] = array('path' => $path);
        return empty($result) ? NULL : $result;
    }

    public static function getTorrentDownloadPath($id)
    {
        $topic = self::topic($id);
        return $topic === NULL ? NULL : $topic['path'];
    }

    public static function getTorrentCategory($id)
    {
        $topic = self::topic($id);
        return $topic === NULL ? '' : (string)$topic['category'];
    }

    public static function getDistinctCategories()
    {
        $data = Storage::torrents()->read();
        $categories = array_values(array_unique(array_filter(array_column($data['torrents'], 'category'), 'strlen')));
        sort($categories);
        return $categories;
    }

    public static function getScript($id)
    {
        $topic = self::topic($id);
        return $topic === NULL ? NULL : array('script' => $topic['script']);
    }

    //TRUE, если такого сериала ещё нет
    public static function checkSerialExist($tracker, $name, $hd)
    {
        $data = Storage::torrents()->read();
        foreach ($data['torrents'] as $topic)
            if ($topic['tracker'] == $tracker && $topic['name'] == $name && $topic['hd'] == $hd)
                return FALSE;
        return TRUE;
    }

    //TRUE, если такой темы ещё нет
    public static function checkThremExist($tracker, $id)
    {
        $data = Storage::torrents()->read();
        foreach ($data['torrents'] as $topic)
            if ($topic['tracker'] == $tracker && $topic['torrent_id'] == $id)
                return FALSE;
        return TRUE;
    }

    public static function setSerial($tracker, $name, $path, $hd = FALSE, $category = '')
    {
        return self::addTopic(array('tracker' => $tracker, 'name' => (string)$name, 'path' => (string)$path, 'hd' => (int)$hd, 'category' => (string)$category));
    }

    public static function setThreme($tracker, $name, $path, $threme, $update_header, $category = '')
    {
        return self::addTopic(array(
            'tracker' => $tracker, 'name' => (string)$name, 'path' => (string)$path, 'torrent_id' => (string)$threme,
            'auto_update' => (int)$update_header, 'category' => (string)$category,
        ));
    }

    public static function setNewDate($id, $date)
    {
        return self::changeTopic($id, array('timestamp' => $date));
    }

    public static function setNewName($id, $name)
    {
        return self::changeTopic($id, array('name' => $name));
    }

    public static function setNewEpisode($id, $ep)
    {
        return self::changeTopic($id, array('ep' => $ep));
    }

    public static function updateSerial($id, $name, $path, $hd, $reset, $script, $pause)
    {
        $fields = array('name' => (string)$name, 'path' => (string)$path, 'hd' => (int)$hd, 'script' => (string)$script, 'pause' => (int)$pause);
        if ($reset)
            $fields += array('ep' => '', 'timestamp' => '2000-01-01 00:00:00', 'hash' => '');
        return self::changeTopic($id, $fields);
    }

    public static function updateThreme($id, $name, $path, $threme, $update, $reset, $script, $pause, $category = '')
    {
        $fields = array(
            'name' => (string)$name, 'path' => (string)$path, 'torrent_id' => (string)$threme, 'auto_update' => (int)$update,
            'script' => (string)$script, 'pause' => (int)$pause, 'category' => (string)$category,
        );
        if ($reset)
            $fields += array('timestamp' => '2000-01-01 00:00:00', 'hash' => '');
        return self::changeTopic($id, $fields);
    }

    public static function updateHash($id, $hash)
    {
        return self::changeTopic($id, array('hash' => (string)$hash));
    }

    public static function setErrorToThreme($id, $value)
    {
        return self::changeTopic($id, array('error' => (int)$value));
    }

    public static function setClosedThreme($id, $closed)
    {
        return self::changeTopic($id, array('closed' => (int)$closed));
    }

    //изменение нескольких тем одной записью файла
    //$updates = [ $id => ['timestamp'=>..., 'name'=>..., 'ep'=>..., 'error'=>0, 'closed'=>0], ... ]
    public static function batchUpdateTorrents(array $updates)
    {
        if (empty($updates))
            return;

        $allowed = array('timestamp' => 1, 'name' => 1, 'ep' => 1, 'error' => 1, 'closed' => 1);
        Storage::torrents()->update(function (&$data) use ($updates, $allowed) {
            $changed = FALSE;
            foreach ($updates as $id => $fields)
            {
                $i = Database::indexById($data['torrents'], $id);
                $fields = array_intersect_key((array)$fields, $allowed);
                if ($i === NULL || empty($fields))
                    continue;
                $before = $data['torrents'][$i];
                $data['torrents'][$i] = Database::withActivity($before, $fields);
                $changed = $changed || $data['torrents'][$i] !== $before;
            }
            return $changed;
        });
    }

    public static function deletItem($id)
    {
        Storage::torrents()->update(function (&$data) use ($id) {
            $i = Database::indexById($data['torrents'], $id);
            if ($i === NULL)
                return FALSE;
            array_splice($data['torrents'], $i, 1);
            return TRUE;
        });
        return TRUE;
    }

    // ------------------------------------------------- watched users and buffer

    public static function getUserToWatch()
    {
        $data = Storage::torrents()->read();
        $users = $data['users'];
        usort($users, function ($a, $b) { return strcmp($a['tracker'], $b['tracker']) ?: $a['id'] - $b['id']; });
        return empty($users) ? NULL : $users;
    }

    //TRUE, если такой пользователь ещё не отслеживается
    public static function checkUserExist($tracker, $name)
    {
        $data = Storage::torrents()->read();
        foreach ($data['users'] as $user)
            if ($user['tracker'] == $tracker && $user['name'] == $name)
                return FALSE;
        return TRUE;
    }

    public static function setUser($tracker, $name)
    {
        Storage::torrents()->update(function (&$data) use ($tracker, $name) {
            $data['users'][] = array('id' => $data['nextUserId']++, 'tracker' => $tracker, 'name' => (string)$name);
        });
        return TRUE;
    }

    public static function deletUser($id)
    {
        Storage::torrents()->update(function (&$data) use ($id) {
            $i = Database::indexById($data['users'], $id);
            if ($i === NULL)
                return FALSE;
            array_splice($data['users'], $i, 1);
            return TRUE;
        });
        Storage::state()->update(function (&$data) use ($id) {
            $before = count($data['buffer']);
            $data['buffer'] = array_values(array_filter($data['buffer'], function ($item) use ($id) { return $item['user_id'] != $id; }));
            return count($data['buffer']) != $before;
        });
        return TRUE;
    }

    public static function addThremeToBuffer($user_id, $section, $threme_id, $threme, $timestamp, $tracker)
    {
        //section/threme приходят регэкспом из сырого HTML страницы трекера и могут
        //содержать HTML-сущности (&#039; и т.п.) — раскодируем их здесь, до
        //htmlspecialchars() на выводе, иначе получим двойное кодирование
        $section = html_entity_decode($section, ENT_QUOTES, 'UTF-8');
        $threme = html_entity_decode(preg_replace('/<wbr>/', '', $threme), ENT_QUOTES, 'UTF-8');

        return Storage::state()->update(function (&$data) use ($user_id, $section, $threme_id, $threme, $timestamp, $tracker) {
            foreach ($data['buffer'] as $item)
                if ($item['user_id'] == $user_id && $item['threme_id'] == $threme_id)
                    return FALSE;
            $data['buffer'][] = array(
                'id' => $data['nextBufferId']++, 'user_id' => (int)$user_id, 'section' => $section, 'threme_id' => (int)$threme_id,
                'threme' => $threme, 'timestamp' => $timestamp, 'accept' => 0, 'downloaded' => 0, 'new' => 1, 'tracker' => $tracker,
            );
            return TRUE;
        }) ? TRUE : NULL;
    }

    //элементы буфера, прошедшие фильтр, или NULL
    private static function bufferItems($filter)
    {
        $state = Storage::state()->read();
        $items = array_values(array_filter($state['buffer'], $filter));
        return empty($items) ? NULL : $items;
    }

    //изменяет элементы буфера, прошедшие фильтр
    private static function changeBuffer($filter, array $fields)
    {
        Storage::state()->update(function (&$data) use ($filter, $fields) {
            $changed = FALSE;
            foreach ($data['buffer'] as $i => $item)
            {
                if ( ! $filter($item))
                    continue;
                $data['buffer'][$i] = array_merge($item, $fields);
                $changed = $changed || $data['buffer'][$i] !== $item;
            }
            return $changed;
        });
        return TRUE;
    }

    public static function getThremesFromBuffer($user_id)
    {
        $items = self::bufferItems(function ($item) use ($user_id) { return $item['user_id'] == $user_id && $item['new'] == 1; });
        if ($items === NULL)
            return NULL;
        usort($items, function ($a, $b) { return $b['threme_id'] - $a['threme_id']; });
        $result = array();
        foreach ($items as $item)
            $result[] = array(
                'id' => $item['id'], 'section' => $item['section'], 'threme_id' => $item['threme_id'],
                'threme' => $item['threme'], 'timestamp' => $item['timestamp'], 'tracker' => $item['tracker'],
            );
        return $result;
    }

    public static function transferFromBuffer($id)
    {
        $items = self::bufferItems(function ($item) use ($id) { return $item['id'] == $id; });
        if ($items === NULL)
            return;
        $users = Storage::torrents()->read()['users'];
        $u = self::indexById($users, $items[0]['user_id']);
        self::setThreme($u === NULL ? NULL : $users[$u]['tracker'], $items[0]['threme'], '', $items[0]['threme_id'], 0);
        self::deleteFromBuffer($id);
    }

    public static function deleteFromBuffer($id)
    {
        return self::changeBuffer(function ($item) use ($id) { return $item['id'] == $id; }, array('new' => 0));
    }

    public static function thremesClear($user_id)
    {
        return self::changeBuffer(function ($item) use ($user_id) { return $item['user_id'] == $user_id; }, array('new' => 0));
    }

    public static function selectAllFromBuffer()
    {
        $items = self::bufferItems(function ($item) { return $item['accept'] == 0 && $item['new'] == 1; });
        return $items === NULL ? NULL : array_map(function ($item) { return array('id' => $item['id']); }, $items);
    }

    public static function updateThremesToDownload($id)
    {
        return self::changeBuffer(function ($item) use ($id) { return $item['id'] == $id; }, array('accept' => 1, 'new' => 0));
    }

    public static function takeToDownload($tracker)
    {
        $items = self::bufferItems(function ($item) use ($tracker) {
            return $item['accept'] == 1 && $item['downloaded'] == 0 && $item['tracker'] == $tracker;
        });
        return $items === NULL ? NULL : array_map(function ($item) {
            return array('id' => $item['id'], 'threme_id' => $item['threme_id'], 'threme' => $item['threme']);
        }, $items);
    }

    public static function setDownloaded($id)
    {
        return self::changeBuffer(function ($item) use ($id) { return $item['id'] == $id; }, array('downloaded' => 1));
    }

    // ----------------------------------------------------------------- warnings

    //ошибки от новых к старым
    private static function warnings($filter = NULL)
    {
        $state = Storage::state()->read();
        $list = $filter === NULL ? $state['warnings'] : array_values(array_filter($state['warnings'], $filter));
        usort($list, function ($a, $b) { return strcmp($b['time'], $a['time']) ?: $b['id'] - $a['id']; });
        return $list;
    }

    public static function getWarningsCount()
    {
        $counts = array();
        foreach (Storage::state()->read()['warnings'] as $warning)
            $counts[$warning['where']] = (isset($counts[$warning['where']]) ? $counts[$warning['where']] : 0) + 1;
        ksort($counts);
        $result = array();
        foreach ($counts as $where => $count)
            $result[] = array('where' => $where, 'count' => $count);
        return empty($result) ? NULL : $result;
    }

    public static function getWarningsCountSimple()
    {
        return array('count' => count(Storage::state()->read()['warnings']));
    }

    public static function getWarningsList($tracker)
    {
        $result = array();
        foreach (self::warnings(function ($w) use ($tracker) { return $w['where'] == $tracker; }) as $w)
        {
            $parts = self::dateParts($w['time']);
            $result[] = array('time' => $parts['time'], 'reason' => $w['reason'], 'where' => $w['where'], 'id' => $w['t_id'],
                'day' => $parts['day'], 'month' => $parts['month'], 'year' => $parts['year']);
        }
        return empty($result) ? NULL : $result;
    }

    public static function getAllWarningsList()
    {
        return array_map(function ($w) {
            return array('time' => $w['time'], 'reason' => $w['reason'], 'tracker' => $w['where'], 'id' => $w['t_id']);
        }, self::warnings());
    }

    public static function getWarningsByTorrentId($torrentId)
    {
        return array_map(function ($w) {
            return array('time' => $w['time'], 'reason' => $w['reason'], 'tracker' => $w['where'], 'id' => $w['t_id']);
        }, self::warnings(function ($w) use ($torrentId) { return $w['t_id'] !== NULL && $w['t_id'] == $torrentId; }));
    }

    public static function setWarnings($date, $tracker, $message, $id)
    {
        Storage::state()->update(function (&$data) use ($date, $tracker, $message, $id) {
            $data['warnings'][] = array(
                'id' => $data['nextWarningId']++, 'time' => $date, 'where' => (string)$tracker,
                'reason' => (string)$message, 't_id' => $id === NULL ? NULL : (int)$id,
            );
        });
        return TRUE;
    }

    public static function clearWarnings($tracker)
    {
        Storage::state()->update(function (&$data) use ($tracker) {
            $before = count($data['warnings']);
            $data['warnings'] = array_values(array_filter($data['warnings'], function ($w) use ($tracker) { return $w['where'] != $tracker; }));
            return count($data['warnings']) != $before;
        });
        return TRUE;
    }

    // --------------------------------------------------------------------- news

    public static function getNews()
    {
        $news = Storage::state()->read()['news'];
        usort($news, function ($a, $b) { return strcmp($b['id'], $a['id']); });
        return empty($news) ? NULL : $news;
    }

    public static function getNewsCount()
    {
        $new = array_filter(Storage::state()->read()['news'], function ($item) { return $item['new'] == 1; });
        return array('count' => count($new));
    }

    public static function checkNewsExist($id)
    {
        foreach (Storage::state()->read()['news'] as $item)
            if ($item['id'] === (string)$id)
                return TRUE;
        return NULL;
    }

    public static function insertNews($id, $text)
    {
        $id = (string)$id;
        Storage::state()->update(function (&$data) use ($id, $text) {
            foreach ($data['news'] as $i => $item)
            {
                if ($item['id'] === $id)
                {
                    $data['news'][$i]['text'] = (string)$text;
                    return TRUE;
                }
            }
            $data['news'][] = array('id' => $id, 'text' => (string)$text, 'new' => 1);
            return TRUE;
        });
        return TRUE;
    }

    //Помечаем новость как прочитанную
    public static function markNews($id)
    {
        $id = (string)$id;
        Storage::state()->update(function (&$data) use ($id) {
            foreach ($data['news'] as $i => $item)
            {
                if ($item['id'] === $id && $item['new'] != 0)
                {
                    $data['news'][$i]['new'] = 0;
                    return TRUE;
                }
            }
            return FALSE;
        });
        return TRUE;
    }

    // ------------------------------------- torrents waiting for the torrent client

    public static function saveToTemp($id, $name, $path, $tracker, $date)
    {
        Storage::state()->update(function (&$data) use ($id, $name, $path, $tracker, $date) {
            $data['temp'][(string)$id] = array('name' => (string)$name, 'path' => (string)$path, 'tracker' => (string)$tracker, 'date' => (string)$date);
        });
        return TRUE;
    }

    public static function getAllFromTemp()
    {
        $result = array();
        foreach (Storage::state()->read()['temp'] as $id => $item)
            $result[] = array('id' => (int)$id, 'name' => $item['name'], 'path' => $item['path'], 'tracker' => $item['tracker'], 'date_str' => $item['date']);
        return empty($result) ? NULL : $result;
    }

    public static function deleteFromTemp($id)
    {
        Storage::state()->update(function (&$data) use ($id) {
            if ( ! isset($data['temp'][(string)$id]))
                return FALSE;
            unset($data['temp'][(string)$id]);
            return TRUE;
        });
        return TRUE;
    }

    public static function clearTemp()
    {
        Storage::state()->update(function (&$data) {
            if (empty($data['temp']))
                return FALSE;
            $data['temp'] = array();
            return TRUE;
        });
        return TRUE;
    }
}
?>
