<?php
namespace TorrentMonitor\Http;

// Response to an HTTP request. Page bodies are already converted to UTF-8.
final class Response
{
    public int $status = 0;
    public string $body = '';
    // Headers of all responses in the redirect chain, as received
    public string $headers = '';
    /** @var array<string,string> cookies set by the server (and by the Cloudflare solver) */
    public array $cookies = array();
    // Connection error (empty if a response was received)
    public string $error = '';
    // cURL error number of the connection error (0 if a response was received)
    public int $errno = 0;
    // Proxy the request went through (scheme://host:port), NULL - directly
    public ?string $proxy = NULL;
    // The site answered with a Cloudflare challenge that could not be bypassed
    public bool $challenged = FALSE;
    // The body was obtained through the Cloudflare solver (a browser), not directly
    public bool $solved = FALSE;
    // Cloudflare cookies the request was sent with (internal field of the client)
    public ?string $sentClearance = NULL;

    public function ok(): bool
    {
        return $this->error === '' && $this->status >= 200 && $this->status < 400 && ! $this->challenged;
    }

    // Why there is no response: 'proxy_fail' - the proxy itself is to blame (it does not answer,
    // refuses, or could not reach the site), 'tracker_unreachable' - the site is. NULL if a response was received.
    public function failure(): ?string
    {
        if ($this->status != 0)
            return NULL;
        // 5 - proxy name not resolved, 7 - no connection (with a proxy it is the proxy that is connected to), 97 - proxy handshake
        if ($this->proxy !== NULL && in_array($this->errno, array(5, 7, 97), TRUE))
            return 'proxy_fail';
        return 'tracker_unreachable';
    }

    // The same in words, for the status page
    public function failureText(): string
    {
        if ($this->status != 0)
            return '';
        $via = $this->proxy !== NULL ? ' через прокси '.$this->proxy : '';
        if ($this->failure() == 'proxy_fail')
            return $this->errno == 97 ? 'прокси '.$this->proxy.' не смог соединиться с сайтом или отказал' : 'прокси '.$this->proxy.' не отвечает';
        switch ($this->errno)
        {
            case 6:  return 'адрес сайта не найден (DNS)'.$via;
            case 7:  return 'сайт не принимает соединения';
            case 28: return 'нет ответа за отведённое время'.$via;
            case 35: case 51: case 58: case 59: case 60: return 'ошибка защищённого соединения (TLS)'.$via;
            case 52: case 56: return 'сайт оборвал соединение'.$via;
        }
        return 'нет ответа'.$via.($this->error !== '' ? ' ('.$this->error.')' : '');
    }

    public function cookie(string $name): ?string
    {
        return isset($this->cookies[$name]) ? $this->cookies[$name] : NULL;
    }

    // "a=1; b=2" from the cookies that were set; $names - only the listed ones
    public function cookieString(array $names = array()): string
    {
        $pairs = array();
        foreach ($this->cookies as $name => $value)
            if (empty($names) || in_array($name, $names, TRUE))
                $pairs[] = $name.'='.$value;
        return implode('; ', $pairs);
    }

    public function header(string $name): ?string
    {
        if (preg_match_all('/^'.preg_quote($name, '/').':\s*(.*?)\s*$/mi', $this->headers, $m))
            return end($m[1]);
        return NULL;
    }

    public function json()
    {
        return json_decode($this->body, TRUE);
    }
}
