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

    public static function lastRunSummary(): string
    {
        $state = Files::state()->read();
        return isset($state['lastRunSummary']) ? (string)$state['lastRunSummary'] : '';
    }

    public static function setLastRunSummary(string $text): void
    {
        Files::state()->update(function (&$data) use ($text) { $data['lastRunSummary'] = $text; });
    }

    // What a service ('client' - the torrent client, 'solver' - the Cloudflare solver) said about
    // itself when it was last talked to: array(name, version, details, address, at) or NULL
    public static function service(string $key): ?array
    {
        $state = Files::state()->read();
        return isset($state['services'][$key]) && is_array($state['services'][$key]) ? $state['services'][$key] : NULL;
    }

    // Remembers what a service said about itself. A version that is new or different goes to the
    // journal: that is how an upgrade of the client shows up there.
    public static function sawService(string $key, string $name, string $version, string $details, string $address): void
    {
        $before = self::service($key);
        $now = array('name' => $name, 'version' => $version, 'details' => $details, 'address' => $address, 'at' => date('Y-m-d H:i:s'));
        Files::state()->update(function (&$data) use ($key, $now) {
            if ( ! isset($data['services']) || ! is_array($data['services']))
                $data['services'] = array();
            $data['services'][$key] = $now;
        });
        $title = $key == 'client' ? 'Torrent-клиент' : 'Решатель Cloudflare';
        $text = function (array $s) { return trim($s['name'].' '.$s['version']).($s['details'] !== '' ? ' ('.$s['details'].')' : ''); };
        if ($before === NULL || $before['name'] != $name || $before['address'] != $address)
            Journal::add('service', $title.': '.$text($now));
        elseif ($before['version'] != $version || $before['details'] != $details)
            Journal::add('service', $title.': '.$text($before).' → '.$text($now));
    }

    //a service that is not used any more is forgotten
    public static function forgetService(string $key): void
    {
        Files::state()->update(function (&$data) use ($key) {
            if ( ! isset($data['services'][$key]))
                return FALSE;
            unset($data['services'][$key]);
            return TRUE;
        });
    }

    // Notices that the program files are of another version than at the previous start, and says so
    // in the journal: an update the program did not install itself - a new Docker image, files
    // replaced by hand. With $quiet that line is left out (the updater writes its own). Either way the
    // torrent client and the Cloudflare solver the new version starts with are named after it.
    public static function sawVersion(bool $quiet = FALSE): void
    {
        $now = \TorrentMonitor\App::versionLabel();
        //an installation that is not set up yet has no files, and looking at it must not create one
        if ( ! Files::config()->exists())
            return;
        $state = Files::state()->read();
        if (isset($state['program']) && $state['program'] === $now)
            return;
        $before = '';
        Files::state()->update(function (&$data) use ($now, &$before) {
            $before = isset($data['program']) ? (string)$data['program'] : '';
            if ($before === $now)
                return FALSE;
            $data['program'] = $now;
            return TRUE;
        });
        //the first start of a version that remembers its number has nothing to compare with
        if ($before === '' || $before === $now)
            return;
        if ( ! $quiet)
            Journal::add('update', 'Версия программы изменилась: '.$before.' → '.$now
                .' (файлы заменены не самим TorrentMonitor: новый образ Docker или установка вручную)');
        //what the new version works with, as it was last seen: nothing is asked over the network here
        foreach (\TorrentMonitor\Engine\About::services() as $line)
            Journal::add('service', $line);
    }

    // The update channel was changed and its version is not installed yet: until then a lower
    // version of the new channel is installed too
    public static function channelChanged(): bool
    {
        return ! empty(Files::state()->read()['channelChanged']);
    }

    public static function setChannelChanged(bool $value): void
    {
        Files::state()->update(function (&$data) use ($value) {
            if ( ! empty($data['channelChanged']) === $value)
                return FALSE;
            $data['channelChanged'] = $value;
            return TRUE;
        });
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
