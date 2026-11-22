<?php
namespace TorrentMonitor\Data;

use TorrentMonitor\Http\ClearanceStore;
use TorrentMonitor\Storage\Files;

// Working state of the program (state.json): run time, passed Cloudflare challenges.
final class State implements ClearanceStore
{
    public static function lastRun(): string
    {
        return (string)Files::state()->read()['lastRun'];
    }

    public static function setLastRun(string $time): void
    {
        Files::state()->update(function (&$data) use ($time) { $data['lastRun'] = $time; });
    }

    public static function updateNotified(): bool
    {
        return ! empty(Files::state()->read()['updateNotified']);
    }

    public static function setUpdateNotified(bool $value): void
    {
        Files::state()->update(function (&$data) use ($value) {
            if ($data['updateNotified'] === $value)
                return FALSE;
            $data['updateNotified'] = $value;
            return TRUE;
        });
    }

    public function get(string $host): ?array
    {
        $state = Files::state()->read();
        if (empty($state['clearance'][$host]['cookie']) || empty($state['clearance'][$host]['userAgent']))
            return NULL;
        return array('cookie' => (string)$state['clearance'][$host]['cookie'], 'userAgent' => (string)$state['clearance'][$host]['userAgent']);
    }

    public function set(string $host, string $cookie, string $userAgent): void
    {
        Files::state()->update(function (&$data) use ($host, $cookie, $userAgent) {
            $data['clearance'][$host] = array('cookie' => $cookie, 'userAgent' => $userAgent, 'at' => date('Y-m-d H:i:s'));
        });
    }

    public function forget(string $host): void
    {
        Files::state()->update(function (&$data) use ($host) {
            if ( ! isset($data['clearance'][$host]))
                return FALSE;
            unset($data['clearance'][$host]);
            return TRUE;
        });
    }
}
