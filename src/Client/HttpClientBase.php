<?php
namespace TorrentMonitor\Client;

use TorrentMonitor\Http\Client;
use TorrentMonitor\Http\Request;
use TorrentMonitor\Http\Response;

// Common to torrent clients: address, login, password, requests.
abstract class HttpClientBase implements TorrentClient
{
    protected Client $http;
    protected string $address;
    protected string $login;
    protected string $password;

    public function __construct(Client $http, string $address, string $login, string $password)
    {
        $this->http = $http;
        $address = rtrim(trim($address), '/');
        if ($address !== '' && ! preg_match('~^https?://~i', $address))
            $address = 'http://'.$address;
        $this->address = $address;
        $this->login = $login;
        $this->password = $password;
    }

    // request to the client: no proxy and no Cloudflare bypass
    protected function send(Request $request): Response
    {
        $response = $this->http->send($request->plain()->timeout(30));
        if ($response->status == 0)
            throw new ClientException('connect_fail');
        return $response;
    }

    public function about(): array
    {
        return array('version' => '', 'details' => '');
    }

    protected static function read(string $file): string
    {
        $data = @file_get_contents($file);
        if ($data === FALSE || $data === '')
            throw new ClientException('torrent_file_fail');
        return $data;
    }
}
