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
