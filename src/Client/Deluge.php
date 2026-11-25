<?php
namespace TorrentMonitor\Client;

use TorrentMonitor\Http\Request;

// Deluge via deluge-web (JSON-RPC). No login is used, only the web interface password.
final class Deluge extends HttpClientBase
{
    private string $cookie = '';
    private bool $connected = FALSE;

    private function rpc(string $method, array $params = array())
    {
        $response = $this->send(Request::json($this->address.'/json', array('method' => $method, 'params' => $params, 'id' => 1))->cookie($this->cookie));
        if ($response->cookie('_session_id') !== NULL)
            $this->cookie = '_session_id='.$response->cookie('_session_id');
        $data = $response->json();
        if ($response->status != 200 || ! is_array($data) || ! empty($data['error']))
            return NULL;
        return isset($data['result']) ? $data['result'] : NULL;
    }

    private function connect(): void
    {
        if ($this->connected)
            return;
        if ($this->rpc('auth.login', array($this->password)) !== TRUE)
            throw new ClientException('log_passwd');
        // the web interface may not be connected to a daemon: connect to the first known one
        if ($this->rpc('web.connected') !== TRUE)
        {
            $hosts = $this->rpc('web.get_hosts');
            if (empty($hosts[0][0]) || $this->rpc('web.connect', array($hosts[0][0])) === NULL)
                throw new ClientException('connect_fail');
        }
        $this->connected = TRUE;
    }

    public function about(): array
    {
        $this->connect();
        $version = '';
        //the web interface knows the version of the daemon it is connected to: array(id, state, version)
        $hosts = $this->rpc('web.get_hosts');
        if ( ! empty($hosts[0][0]))
        {
            $status = $this->rpc('web.get_host_status', array($hosts[0][0]));
            if (is_array($status) && isset($status[2]) && is_string($status[2]))
                $version = $status[2];
        }
        $libtorrent = $this->rpc('core.get_libtorrent_version');
        return array('version' => $version, 'details' => is_string($libtorrent) && $libtorrent !== '' ? 'libtorrent '.$libtorrent : '');
    }

    public function add(string $file, array $options): string
    {
        $torrent = self::read($file);
        $this->connect();
        $settings = empty($options['path']) ? new \stdClass() : array('download_location' => $options['path']);
        $hash = $this->rpc('core.add_torrent_file', array(basename($file), base64_encode($torrent), $settings));
        if (empty($hash))
        {
            // the client already has the torrent: not an error
            $known = Bencode::infoHash($torrent);
            if ($known !== NULL && $this->rpc('web.get_torrent_status', array($known, array('hash'))))
                return $known;
            throw new ClientException('add_fail');
        }
        return (string)$hash;
    }

    public function remove(string $id, bool $deleteData): void
    {
        $this->connect();
        $this->rpc('core.remove_torrent', array($id, $deleteData));
    }

    public function statuses(): ?array
    {
        $this->connect();
        $list = $this->rpc('core.get_torrents_status', array(new \stdClass(), array('state', 'progress', 'download_payload_rate', 'total_size', 'ratio', 'message')));
        if ( ! is_array($list))
            throw new ClientException('connect_fail');
        $states = array('Downloading' => 'downloading', 'Allocating' => 'downloading', 'Seeding' => 'seeding', 'Paused' => 'stopped',
            'Queued' => 'queued', 'Checking' => 'checking', 'Moving' => 'checking', 'Error' => 'error');
        $found = array();
        foreach ($list as $hash => $t)
        {
            if ( ! is_array($t))
                continue;
            $state = isset($t['state']) ? (string)$t['state'] : '';
            $found[strtolower((string)$hash)] = self::status(isset($states[$state]) ? $states[$state] : 'downloading', isset($t['progress']) ? (float)$t['progress'] : 0,
                isset($t['download_payload_rate']) ? $t['download_payload_rate'] : 0, isset($t['total_size']) ? $t['total_size'] : 0,
                isset($t['ratio']) ? $t['ratio'] : 0, $state == 'Error' && isset($t['message']) ? (string)$t['message'] : '');
        }
        return $found;
    }

    public function info(string $id): ?array
    {
        $this->connect();
        $status = $this->rpc('web.get_torrent_status', array(strtolower($id), array('comment', 'download_location', 'name')));
        if (empty($status) || ! is_array($status))
            return NULL;
        return array('comment' => isset($status['comment']) ? (string)$status['comment'] : '',
            'path' => isset($status['download_location']) ? (string)$status['download_location'] : '', 'category' => '',
            'content' => isset($status['download_location'], $status['name']) && $status['name'] !== '' ? rtrim((string)$status['download_location'], '/').'/'.$status['name'] : '');
    }
}
