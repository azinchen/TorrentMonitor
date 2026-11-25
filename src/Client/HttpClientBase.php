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
    //seconds a request to the client may take
    protected int $timeout = 30;

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
        $response = $this->http->send($request->plain()->timeout($this->timeout));
        if ($response->status == 0)
            throw new ClientException('connect_fail');
        return $response;
    }

    public function about(): array
    {
        return array('version' => '', 'details' => '');
    }

    public function statuses(): ?array
    {
        return NULL;
    }

    // The page of topics asks about the torrents while it is being looked at: a client that is down
    // must not keep it waiting as long as a check run would wait
    public function hurry(int $seconds): void
    {
        $this->timeout = max(1, $seconds);
    }

    //one torrent as statuses() returns it
    protected static function status(string $state, float $progress, $speed = 0, $size = 0, $ratio = 0, string $message = ''): array
    {
        return array('state' => $state, 'progress' => round(max(0, min(100, $progress)), 1), 'speed' => max(0, (int)$speed),
            'size' => max(0, (int)$size), 'ratio' => round(max(0, (float)$ratio), 2), 'message' => $message);
    }

    protected static function read(string $file): string
    {
        $data = @file_get_contents($file);
        if ($data === FALSE || $data === '')
            throw new ClientException('torrent_file_fail');
        return $data;
    }
}
