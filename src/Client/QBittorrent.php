<?php
namespace TorrentMonitor\Client;

use TorrentMonitor\Http\Request;

final class QBittorrent extends HttpClientBase
{
    private string $cookie = '';

    private function signIn(): void
    {
        if ($this->cookie !== '')
            return;
        $response = $this->send(Request::post($this->address.'/api/v2/auth/login', array('username' => $this->login, 'password' => $this->password))
            ->referer($this->address));
        foreach ($response->cookies as $name => $value)
            if (preg_match('/^(QBT_)?SID(_\d+)?$/', $name))
                $this->cookie = $name.'='.$value;
        // authentication may be disabled for the TorrentMonitor address: then there is no cookie, but requests work
        if ($this->cookie === '' && ($response->status != 200 || trim($response->body) != 'Ok.'))
            throw new ClientException('log_passwd');
    }

    private function call(Request $request)
    {
        $this->signIn();
        $response = $this->send($request->cookie($this->cookie)->referer($this->address));
        // qBittorrent drops old sessions when another program (Sonarr, for one) keeps logging in:
        // sign in again and repeat the request once
        if ($response->status == 403 && $this->cookie !== '')
        {
            $this->cookie = '';
            $this->signIn();
            $response = $this->send($request->cookie($this->cookie)->referer($this->address));
        }
        return $response;
    }

    public function about(): array
    {
        $version = ltrim(trim($this->call(Request::get($this->address.'/api/v2/app/version'))->body), 'vV');
        if ( ! preg_match('/^[0-9][0-9A-Za-z.\-+]*$/', $version))
            $version = '';
        $details = array();
        $build = $this->call(Request::get($this->address.'/api/v2/app/buildInfo'))->json();
        if (is_array($build) && ! empty($build['libtorrent']))
            $details[] = 'libtorrent '.$build['libtorrent'];
        $api = trim($this->call(Request::get($this->address.'/api/v2/app/webapiVersion'))->body);
        if (preg_match('/^[0-9.]+$/', $api))
            $details[] = 'web API '.$api;
        return array('version' => $version, 'details' => implode(', ', $details));
    }

    public function add(string $file, array $options): string
    {
        $torrent = self::read($file);
        $hash = Bencode::infoHash($torrent);
        if ($hash === NULL)
            throw new ClientException('torrent_file_fail');

        // autoTMM makes qBittorrent ignore savepath: turn it off if a directory is set
        $fields = array('autoTMM' => empty($options['path']) ? 'true' : 'false', 'root_folder' => 'true');
        if ( ! empty($options['path']))
            $fields['savepath'] = $options['path'];
        if ( ! empty($options['category']))
            $fields['category'] = $options['category'];

        $response = $this->call(Request::multipart($this->address.'/api/v2/torrents/add', $fields, array('torrents' => array(basename($file), $torrent))));
        if ($response->status == 401 || $response->status == 403)
            throw new ClientException('log_passwd');
        if ($response->status >= 200 && $response->status < 300 && stripos($response->body, 'Fails') === FALSE)
            return $hash;
        // the client already has this torrent: not an error
        if ($this->has($hash))
            return $hash;
        throw new ClientException($response->status == 415 ? 'torrent_file_fail' : 'add_fail');
    }

    private function has(string $hash): bool
    {
        $response = $this->call(Request::get($this->address.'/api/v2/torrents/info?hashes='.$hash));
        $list = $response->json();
        return is_array($list) && ! empty($list);
    }

    public function remove(string $id, bool $deleteData): void
    {
        $this->call(Request::post($this->address.'/api/v2/torrents/delete', array('hashes' => strtolower($id), 'deleteFiles' => $deleteData ? 'true' : 'false')));
    }

    // how qBittorrent names the states (4.x: paused..., 5.x: stopped...)
    const STATES = array(
        'error' => 'error', 'missingFiles' => 'error',
        'uploading' => 'seeding', 'stalledUP' => 'seeding', 'forcedUP' => 'seeding',
        'pausedUP' => 'stopped', 'stoppedUP' => 'stopped', 'pausedDL' => 'stopped', 'stoppedDL' => 'stopped',
        'queuedUP' => 'queued', 'queuedDL' => 'queued',
        'checkingUP' => 'checking', 'checkingDL' => 'checking', 'checkingResumeData' => 'checking', 'moving' => 'checking',
        'downloading' => 'downloading', 'stalledDL' => 'downloading', 'forcedDL' => 'downloading', 'metaDL' => 'downloading',
        'forcedMetaDL' => 'downloading', 'allocating' => 'downloading',
    );

    public function statuses(): ?array
    {
        $response = $this->call(Request::get($this->address.'/api/v2/torrents/info'));
        $list = $response->status == 200 ? $response->json() : NULL;
        if ( ! is_array($list))
            throw new ClientException($response->status == 403 ? 'log_passwd' : 'connect_fail');
        $found = array();
        foreach ($list as $t)
        {
            if ( ! is_array($t) || empty($t['hash']))
                continue;
            $state = isset($t['state']) ? (string)$t['state'] : '';
            $status = self::status(isset(self::STATES[$state]) ? self::STATES[$state] : 'downloading', (isset($t['progress']) ? (float)$t['progress'] : 0) * 100,
                isset($t['dlspeed']) ? $t['dlspeed'] : 0, isset($t['size']) ? $t['size'] : 0, isset($t['ratio']) ? $t['ratio'] : 0, $state == 'missingFiles' ? 'файлы не найдены' : '');
            $found[strtolower((string)$t['hash'])] = $status;
            //a torrent of both versions of the protocol is known to TorrentMonitor by its first one
            if ( ! empty($t['infohash_v1']))
                $found[strtolower((string)$t['infohash_v1'])] = $status;
        }
        return $found;
    }

    public function info(string $id): ?array
    {
        $hash = rawurlencode(strtolower($id));
        $response = $this->call(Request::get($this->address.'/api/v2/torrents/properties?hash='.$hash));
        $data = $response->status == 200 ? $response->json() : NULL;
        if ( ! is_array($data))
            return NULL;
        $list = $this->call(Request::get($this->address.'/api/v2/torrents/info?hashes='.$hash))->json();
        return array(
            'comment'  => isset($data['comment']) ? (string)$data['comment'] : '',
            'path'     => isset($data['save_path']) ? (string)$data['save_path'] : '',
            'category' => isset($list[0]['category']) ? (string)$list[0]['category'] : '',
            'content'  => isset($list[0]['content_path']) ? (string)$list[0]['content_path'] : '',
        );
    }
}
