<?php
namespace TorrentMonitor\Client;

use TorrentMonitor\Http\Request;

// Transmission: RPC protocol (https://github.com/transmission/transmission/blob/main/docs/rpc-spec.md).
final class Transmission extends HttpClientBase
{
    private string $sessionId = '';

    private function rpc(string $method, array $arguments): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++)
        {
            $request = Request::json($this->address.'/transmission/rpc', array('method' => $method, 'arguments' => $arguments));
            if ($this->sessionId !== '')
                $request->header('X-Transmission-Session-Id: '.$this->sessionId);
            if ($this->login !== '' || $this->password !== '')
                $request->auth($this->login, $this->password);
            $response = $this->send($request);

            // the first 409 response gives the session id the request must be repeated with
            if ($response->status == 409 && $response->header('X-Transmission-Session-Id') !== NULL)
            {
                $this->sessionId = $response->header('X-Transmission-Session-Id');
                continue;
            }
            if ($response->status == 401 || $response->status == 403)
                throw new ClientException('log_passwd');
            $data = $response->json();
            if ($response->status != 200 || ! is_array($data))
                throw new ClientException('connect_fail');
            return $data;
        }
        throw new ClientException('connect_fail');
    }

    public function add(string $file, array $options): string
    {
        $arguments = array('metainfo' => base64_encode(self::read($file)), 'paused' => FALSE);
        if ( ! empty($options['path']))
            $arguments['download-dir'] = $options['path'];
        $data = $this->rpc('torrent-add', $arguments);

        foreach (array('torrent-added', 'torrent-duplicate') as $key)
            if ( ! empty($data['arguments'][$key]['hashString']))
                return $data['arguments'][$key]['hashString'];

        $result = isset($data['result']) ? (string)$data['result'] : '';
        if (preg_match('/invalid or corrupt torrent file/i', $result))
            throw new ClientException('torrent_file_fail');
        if (preg_match('/duplicate torrent/i', $result))
            throw new ClientException('duplicate_torrent');
        throw new ClientException('unknown');
    }

    public function remove(string $id, bool $deleteData): void
    {
        $this->rpc('torrent-remove', array('ids' => array($id), 'delete-local-data' => $deleteData));
    }

    public function info(string $id): ?array
    {
        $data = $this->rpc('torrent-get', array('ids' => array(strtolower($id)), 'fields' => array('comment', 'downloadDir', 'name')));
        if (empty($data['arguments']['torrents'][0]))
            return NULL;
        $torrent = $data['arguments']['torrents'][0];
        return array('comment' => isset($torrent['comment']) ? (string)$torrent['comment'] : '',
            'path' => isset($torrent['downloadDir']) ? (string)$torrent['downloadDir'] : '', 'category' => '',
            'content' => isset($torrent['downloadDir'], $torrent['name']) && $torrent['name'] !== '' ? rtrim((string)$torrent['downloadDir'], '/').'/'.$torrent['name'] : '');
    }
}
