<?php
namespace TorrentMonitor\Http;

//Хранилище результатов прохождения проверки Cloudflare: cookie и User-Agent браузера по хостам.
interface ClearanceStore
{
    /** @return array{cookie:string,userAgent:string}|null */
    public function get(string $host): ?array;

    public function set(string $host, string $cookie, string $userAgent): void;

    public function forget(string $host): void;
}
