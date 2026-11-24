<?php
namespace TorrentMonitor\Data;

use TorrentMonitor\Storage\Files;

// Tracker credentials (config.json) and their session cookies (state.json).
final class Accounts
{
    /** @return array{login:string,password:string,passkey:string,address:string} */
    public static function get(string $tracker): array
    {
        $config = Files::config()->read();
        $saved = isset($config['trackers'][$tracker]) ? (array)$config['trackers'][$tracker] : array();
        return array(
            'login'    => isset($saved['login']) ? (string)$saved['login'] : '',
            'password' => isset($saved['password']) ? (string)$saved['password'] : '',
            'passkey'  => isset($saved['passkey']) ? (string)$saved['passkey'] : '',
            'address'  => isset($saved['address']) ? (string)$saved['address'] : '',
        );
    }

    // Time zone the tracker shows its dates in; '' - as in the general settings
    public static function timezone(string $tracker): string
    {
        $config = Files::config()->read();
        return isset($config['trackers'][$tracker]['timezone']) ? (string)$config['trackers'][$tracker]['timezone'] : '';
    }

    // Whether the login and password are set
    public static function filled(string $tracker): bool
    {
        $account = self::get($tracker);
        return trim($account['login']) !== '' && trim($account['password']) !== '';
    }

    // Saves the given fields (login, password, passkey, address, timezone), leaves the rest untouched
    public static function set(string $tracker, array $fields): void
    {
        $fields = array_intersect_key($fields, array('login' => 1, 'password' => 1, 'passkey' => 1, 'address' => 1, 'timezone' => 1));
        Files::config()->update(function (&$data) use ($tracker, $fields) {
            $before = isset($data['trackers'][$tracker]) ? (array)$data['trackers'][$tracker] : array();
            $after = array_merge($before, array_map('strval', $fields));
            //no own time zone is the usual case: it is not written at all
            if (isset($after['timezone']) && $after['timezone'] === '')
                unset($after['timezone']);
            if ($after === $before)
                return FALSE;
            $data['trackers'][$tracker] = $after;
            return TRUE;
        });
    }

    public static function cookie(string $tracker): string
    {
        $state = Files::state()->read();
        return isset($state['cookies'][$tracker]) ? (string)$state['cookies'][$tracker] : '';
    }

    public static function setCookie(string $tracker, string $cookie): void
    {
        Files::state()->update(function (&$data) use ($tracker, $cookie) {
            if (isset($data['cookies'][$tracker]) && $data['cookies'][$tracker] === $cookie)
                return FALSE;
            $data['cookies'][$tracker] = $cookie;
            return TRUE;
        });
    }

    // Proxies of individual trackers: tracker => array(use, address, type)
    public static function proxies(): array
    {
        $config = Files::config()->read();
        return is_array($config['trackerProxy']) ? $config['trackerProxy'] : array();
    }

    public static function setProxies(array $proxies): void
    {
        Files::config()->update(function (&$data) use ($proxies) {
            $data['trackerProxy'] = $proxies;
        });
    }
}
