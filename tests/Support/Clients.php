<?php
// Direct access to the torrent clients of the test stack, to check what
// TorrentMonitor did to them.
class Qbt
{
    private static function jar()
    {
        static $jar;
        if ($jar === null)
        {
            $jar = tempnam(sys_get_temp_dir(), 'qbt');
            Http::post(Env::qbt().'/api/v2/auth/login', array('username' => Env::CLIENT_USER, 'password' => Env::CLIENT_PASS), array('jar' => $jar));
        }
        return $jar;
    }

    // hash => torrent info
    public static function torrents()
    {
        $list = (array)Http::get(Env::qbt().'/api/v2/torrents/info', array('jar' => self::jar()))['json'];
        $byHash = array();
        foreach ($list as $torrent)
            $byHash[strtolower($torrent['hash'])] = $torrent;
        return $byHash;
    }

    public static function has($hash)
    {
        return isset(self::torrents()[strtolower($hash)]);
    }

    // new torrents are added stopped: live tests must not download real content
    public static function keepStopped()
    {
        Http::post(Env::qbt().'/api/v2/app/setPreferences', array('json' => json_encode(array('add_stopped_enabled' => true, 'start_paused_enabled' => true))), array('jar' => self::jar()));
    }

    public static function removeAll()
    {
        Http::post(Env::qbt().'/api/v2/torrents/delete', array('hashes' => 'all', 'deleteFiles' => 'true'), array('jar' => self::jar()));
        Env::waitFor(function () { return count(Qbt::torrents()) == 0; }, 'qBittorrent to become empty');
    }

    // adds a .torrent directly (not through TorrentMonitor)
    public static function addFile($torrentBytes, $savePath, $category = '')
    {
        $boundary = 'tmtests'.md5($torrentBytes);
        $body = '';
        foreach (array('savepath' => $savePath, 'category' => $category, 'autoTMM' => 'false') as $name => $value)
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"torrents\"; filename=\"t.torrent\"\r\nContent-Type: application/x-bittorrent\r\n\r\n$torrentBytes\r\n--$boundary--\r\n";
        return Http::post(Env::qbt().'/api/v2/torrents/add', $body, array('jar' => self::jar(), 'headers' => array('Content-Type: multipart/form-data; boundary='.$boundary)));
    }
}

class Tr
{
    private static function rpc($method, $arguments = array())
    {
        static $session = '';
        for ($attempt = 0; $attempt < 2; $attempt++)
        {
            $response = Http::post(Env::TRANSMISSION.'/transmission/rpc', json_encode(array('method' => $method, 'arguments' => (object)$arguments)), array(
                'auth'    => Env::CLIENT_USER.':'.Env::CLIENT_PASS,
                'headers' => array('X-Transmission-Session-Id: '.$session, 'Content-Type: application/json'),
            ));
            if ($response['status'] == 409 && preg_match('/X-Transmission-Session-Id: (\S+)/i', $response['headers'], $m))
            {
                $session = $m[1];
                continue;
            }
            return $response['json'];
        }
        throw new RuntimeException('Transmission RPC failed: '.$method);
    }

    // hash => torrent info
    public static function torrents()
    {
        $result = self::rpc('torrent-get', array('fields' => array('id', 'hashString', 'name', 'downloadDir', 'percentDone')));
        $byHash = array();
        foreach ($result['arguments']['torrents'] as $torrent)
            $byHash[strtolower($torrent['hashString'])] = $torrent;
        return $byHash;
    }

    public static function has($hash)
    {
        return isset(self::torrents()[strtolower($hash)]);
    }

    public static function removeAll()
    {
        $ids = array_map(function ($t) { return $t['id']; }, array_values(self::torrents()));
        if ($ids)
            self::rpc('torrent-remove', array('ids' => $ids, 'delete-local-data' => true));
        Env::waitFor(function () { return count(Tr::torrents()) == 0; }, 'Transmission to become empty');
    }
}
