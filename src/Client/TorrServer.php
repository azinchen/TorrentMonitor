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

    public function about(): array
    {
        //"MatriX.151"
        $version = trim($this->send($this->authorized(Request::get($this->address.'/echo')))->body);
        return array('version' => preg_match('/^[\w.\-]{1,40}$/', $version) ? $version : '', 'details' => '');
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

    // nothing is known about a torrent beyond its hash; the request at least tells a wrong
    // address or password apart from a working connection
    public function info(string $id): ?array
    {
        $response = $this->send($this->authorized(Request::get($this->address.'/echo')));
        if ($response->status == 401 || $response->status == 403)
            throw new ClientException('log_passwd');
        if ($response->status != 200)
            throw new ClientException('connect_fail');
        return NULL;
    }
}
