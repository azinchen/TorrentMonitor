<?php
namespace TorrentMonitor\Http;

// Storage for the results of passed Cloudflare challenges: cookies and the browser User-Agent per host.
interface ClearanceStore
{
    /** @return array{cookie:string,userAgent:string}|null */
    public function get(string $host): ?array;

    public function set(string $host, string $cookie, string $userAgent): void;

    public function forget(string $host): void;
}
