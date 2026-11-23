<?php
namespace TorrentMonitor\Http;

// Handling of cookie strings like "a=1; b=2".
final class Cookies
{
    /** @return array<string,string> */
    public static function parse(string $cookie): array
    {
        $result = array();
        foreach (explode(';', $cookie) as $pair)
        {
            $pair = trim($pair);
            $pos = strpos($pair, '=');
            if ($pos === FALSE || $pos === 0)
                continue;
            $result[trim(substr($pair, 0, $pos))] = trim(substr($pair, $pos + 1));
        }
        return $result;
    }

    /** @param array<string,string> $cookies */
    public static function build(array $cookies): string
    {
        $pairs = array();
        foreach ($cookies as $name => $value)
            $pairs[] = $name.'='.$value;
        return implode('; ', $pairs);
    }

    // Merges cookie strings; values from later ones replace earlier ones
    public static function merge(string ...$cookies): string
    {
        $all = array();
        foreach ($cookies as $cookie)
            $all = array_merge($all, self::parse($cookie));
        return self::build($all);
    }

    // Cookies from the response headers (all Set-Cookie lines)
    public static function fromHeaders(string $headers): array
    {
        $result = array();
        if (preg_match_all('/^Set-Cookie:\s*([^=;\s]+)=([^;\r\n]*)/mi', $headers, $m, PREG_SET_ORDER))
            foreach ($m as $match)
                $result[$match[1]] = trim($match[2]);
        return $result;
    }

    // Whether the cookie is a Cloudflare service cookie
    public static function isCloudflare(string $name): bool
    {
        return strncmp($name, 'cf_', 3) === 0 || strncmp($name, '__cf', 4) === 0;
    }
}
