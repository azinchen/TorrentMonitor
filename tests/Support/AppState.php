<?php
// The only place in the tests that knows how TorrentMonitor stores its data.
// When the storage changes (database -> JSON files), only this class changes;
// the tests keep asserting the same behaviour.
class AppState
{
    private static function db()
    {
        $pdo = new PDO('sqlite:/data/tm.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
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
        $pdo = self::db();
        foreach ($values as $key => $value)
        {
            $update = $pdo->prepare('UPDATE settings SET val = :val WHERE key = :key');
            $update->execute(array(':val' => (string)$value, ':key' => $key));
            if ($update->rowCount() == 0)
                $pdo->prepare('INSERT INTO settings (key, val) VALUES (:key, :val)')->execute(array(':val' => (string)$value, ':key' => $key));
        }
    }

    public static function setting($key)
    {
        $stmt = self::db()->prepare('SELECT val FROM settings WHERE key = :key');
        $stmt->execute(array(':key' => $key));
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function setCredentials($tracker, $login, $password)
    {
        self::db()->prepare("UPDATE credentials SET log = :login, pass = :pass, cookie = '' WHERE tracker = :tracker")
            ->execute(array(':login' => $login, ':pass' => $password, ':tracker' => $tracker));
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
        $stmt = self::db()->prepare('SELECT * FROM torrent WHERE id = :id');
        $stmt->execute(array(':id' => $id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public static function topics()
    {
        return self::db()->query('SELECT * FROM torrent ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    // list of array('where' => tracker or component, 'reason' => code, 't_id' => topic id)
    public static function warnings()
    {
        return self::db()->query('SELECT "where", reason, t_id FROM warning ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function warningReasons()
    {
        return array_map(function ($w) { return $w['where'].':'.$w['reason']; }, self::warnings());
    }

    public static function news()
    {
        return self::db()->query('SELECT id, text FROM news ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function version()
    {
        return json_decode(file_get_contents('/app/version.txt'), true)['system'];
    }
}
