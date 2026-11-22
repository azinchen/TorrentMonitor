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
        //авторизация может быть отключена для адреса TorrentMonitor: тогда cookie нет, но запросы проходят
        if ($this->cookie === '' && ($response->status != 200 || trim($response->body) != 'Ok.'))
            throw new ClientException('log_passwd');
    }

    private function call(Request $request)
    {
        $this->signIn();
        return $this->send($request->cookie($this->cookie)->referer($this->address));
    }

    public function add(string $file, array $options): string
    {
        $torrent = self::read($file);
        $hash = Bencode::infoHash($torrent);
        if ($hash === NULL)
            throw new ClientException('torrent_file_fail');

        //autoTMM заставляет qBittorrent игнорировать savepath: отключаем, если каталог задан
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
        //такая раздача уже есть в клиенте: это не ошибка
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
        );
    }
}
