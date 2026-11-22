<?php
namespace TorrentMonitor\Client;

use TorrentMonitor\Http\Request;

// TorrServer. Takes a link to the torrent file on the TorrentMonitor server.
// Deleting downloaded files is not supported.
final class TorrServer extends HttpClientBase
{
    private function authorized(Request $request): Request
    {
        if ($this->login !== '' || $this->password !== '')
            $request->auth($this->login, $this->password);
        return $request->follow();
    }

    public function add(string $file, array $options): string
    {
        if (empty($options['url']))
            throw new ClientException('no_response');
        $title = str_replace(' ', '.', isset($options['name']) ? (string)$options['name'] : '');
        $response = $this->send($this->authorized(Request::get($this->address.'/stream/fname?link='.$options['url'].'&save&title='.rawurlencode($title).'&stat')));
        if ( ! preg_match('/\"hash\":\"(.*)\"/U', $response->body, $m))
            throw new ClientException('add_fail');
        return $m[1];
    }

    public function remove(string $id, bool $deleteData): void
    {
        $this->send($this->authorized(Request::json($this->address.'/torrents/', array('action' => 'rem', 'hash' => $id))));
    }

    public function info(string $id): ?array
    {
        return NULL;
    }
}
