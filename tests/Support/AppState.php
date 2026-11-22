<?php
// The only place in the tests that knows how TorrentMonitor stores its data.
// When the storage changes, only this class changes; the tests keep asserting
// the same behaviour.
//
// Data is JSON files in TM_DATA_DIR; they are read and written through the
// application's own storage classes, so that locking and file format stay correct.
class AppState
{
    private static function storage()
    {
        require_once TM_ROOT.'/src/bootstrap.php';
    }

    private static function read($store)
    {
        self::storage();
        return \TorrentMonitor\Storage\Files::$store()->read();
    }

    // settings every integration test starts from
    public static function baseline()
    {
        self::setSettings(array(
            'auth'          => '0',
            'serverAddress' => Env::APP_FROM_NET,
            'ApiKey'        => Env::API_TOKEN,
            'httpTimeout'   => '15',
        ));
    }

    public static function setSettings($values)
    {
        self::storage();
        \TorrentMonitor\Storage\Files::config()->update(function (&$data) use ($values) {
            foreach ($values as $key => $value)
                $data['settings'][$key] = (string)$value;
        });
    }

    public static function setting($key)
    {
        $config = self::read('config');
        return isset($config['settings'][$key]) ? $config['settings'][$key] : null;
    }

    public static function setCredentials($tracker, $login, $password)
    {
        self::storage();
        \TorrentMonitor\Storage\Files::config()->update(function (&$data) use ($tracker, $login, $password) {
            $data['trackers'][$tracker] = array('login' => $login, 'password' => $password, 'passkey' => '');
        });
        \TorrentMonitor\Storage\Files::state()->update(function (&$data) use ($tracker) {
            unset($data['cookies'][$tracker]);
        });
    }

    public static function useQbittorrent($extra = array())
    {
        self::setSettings($extra + array(
            'useTorrent'      => '1',
            'torrentClient'   => 'qBittorrent',
            'torrentAddress'  => Env::QBT,
            'torrentLogin'    => Env::CLIENT_USER,
            'torrentPassword' => Env::CLIENT_PASS,
            'pathToDownload'  => Env::DOWNLOADS.'/qbt/',
        ));
    }

    public static function useTransmission($extra = array())
    {
        self::setSettings($extra + array(
            'useTorrent'      => '1',
            'torrentClient'   => 'Transmission',
            'torrentAddress'  => Env::TRANSMISSION,
            'torrentLogin'    => Env::CLIENT_USER,
            'torrentPassword' => Env::CLIENT_PASS,
            'pathToDownload'  => Env::DOWNLOADS.'/transmission/',
        ));
    }

    // topic as stored, or null
    public static function topic($id)
    {
        foreach (self::topics() as $topic)
            if ($topic['id'] == $id)
                return $topic;
        return null;
    }

    public static function topics()
    {
        // new field names, plus the names the tests have always used
        return array_map(function ($t) {
            return $t + array('torrent_id' => $t['topicId'], 'timestamp' => $t['updatedAt'], 'ep' => $t['episode'], 'pause' => (int)$t['paused']);
        }, self::read('torrents')['topics']);
    }

    // list of array('where' => tracker or component, 'reason' => code, 't_id' => topic id)
    public static function warnings()
    {
        return array_map(function ($w) {
            return array('where' => $w['source'], 'reason' => $w['code'], 't_id' => $w['topicId']);
        }, self::read('state')['warnings']);
    }

    public static function warningReasons()
    {
        return array_map(function ($w) { return $w['where'].':'.$w['reason']; }, self::warnings());
    }

    public static function news()
    {
        return array_map(function ($n) { return array('id' => $n['id'], 'text' => $n['text']); }, self::read('state')['news']);
    }

    public static function version()
    {
        return json_decode(file_get_contents('/app/version.txt'), true)['system'];
    }

    // raw contents of a data file, for tests of the storage itself
    public static function file($name)
    {
        return (string)@file_get_contents(getenv('TM_DATA_DIR').'/'.$name);
    }
}
