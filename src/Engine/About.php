<?php
namespace TorrentMonitor\Engine;

use TorrentMonitor\App;
use TorrentMonitor\Client\Clients;
use TorrentMonitor\Data\Settings;
use TorrentMonitor\Data\State;
use TorrentMonitor\Data\Topics;
use TorrentMonitor\Http\Solver;
use TorrentMonitor\Update\Release;

// What TorrentMonitor runs with: its version, PHP, the torrent client, the Cloudflare solver, the
// proxy, the topics. It opens the log of every check run and is what a bug report should carry.
// Nothing here is asked over the network: the versions of the client and the solver are the ones
// they told when TorrentMonitor last talked to them (see State::sawService).
final class About
{
    /** @return string[] $started - how the run was started, '' outside a run */
    public static function lines(string $started = ''): array
    {
        $lines = array();
        $lines[] = 'TorrentMonitor '.App::versionLabel().', PHP '.PHP_VERSION.($started !== '' ? ', запуск '.$started : '');
        $lines[] = 'Часовой пояс сервера: '.date_default_timezone_get().'. Каталог данных: '.\TorrentMonitor\Privacy::folder(\TorrentMonitor\Storage\Files::dir());

        foreach (self::services() as $line)
            $lines[] = $line;

        $channels = array('stable' => 'стабильные версии', 'beta' => 'бета-версии', 'dev' => 'версия в разработке');
        $lines[] = 'Прокси: '.(Settings::on('proxy') ? Settings::get('proxyType').' '.\TorrentMonitor\Privacy::address(self::withoutLogin((string)Settings::get('proxyAddress'))) : 'выключен')
            .'. Обновления: '.(Settings::on('autoUpdate') ? 'автоматически, '.$channels[Release::channel()] : 'вручную');

        $counts = array();
        foreach (Topics::all() as $topic)
            $counts[$topic['tracker']] = (isset($counts[$topic['tracker']]) ? $counts[$topic['tracker']] : 0) + 1;
        arsort($counts);
        $parts = array();
        foreach ($counts as $tracker => $count)
            $parts[] = $tracker.' '.$count;
        $lines[] = 'Тем: '.array_sum($counts).(empty($counts) ? '' : ' на трекерах: '.implode(', ', $parts));
        return $lines;
    }

    /** @return string[] the torrent clients and the Cloudflare solver, one line each */
    public static function services(): array
    {
        $lines = array();
        if ( ! Clients::enabled())
            $lines[] = 'Torrent-клиент: не используется';
        else
        {
            $default = Clients::fallback();
            foreach (Clients::all() as $client)
                $lines[] = 'Torrent-клиент «'.$client['name'].'»'.(count(Clients::all()) > 1 && (int)$client['id'] === (int)$default['id'] ? ' (основной)' : '')
                    .': '.self::seen('client:'.$client['id'], $client['type'], (string)$client['address']);
        }
        $solver = (string)Settings::get('flaresolverrUrl');
        $lines[] = 'Решатель Cloudflare: '.($solver === '' ? 'не задан' : self::seen('solver', '', $solver));
        return $lines;
    }

    // A service as it was last seen, or just its name when it never was (or another one is set now).
    // Its address is private: it is written inside a mark (see Privacy), without the login it may carry.
    private static function seen(string $key, string $name, string $address): string
    {
        $address = self::withoutLogin($address);
        $shown = \TorrentMonitor\Privacy::address($address);
        $seen = State::service($key);
        if ($seen === NULL || self::withoutLogin((string)$seen['address']) != $address || ($name !== '' && $seen['name'] != $name))
            return trim($name.' '.$shown).' (версия неизвестна: связь ещё не проверялась)';
        $text = trim($seen['name'].' '.$seen['version']);
        return ($text !== '' ? $text : 'версию не сообщает').($seen['details'] !== '' ? ' ('.$seen['details'].')' : '')
            .', '.$shown.', проверено '.substr((string)$seen['at'], 0, 16);
    }

    // An address without the login and password it may carry: http://user:pass@host -> http://host
    public static function withoutLogin(string $address): string
    {
        return preg_replace('~^((?:[a-z0-9+.\-]+://)?)[^/@\s]*@~i', '$1', $address);
    }

    // Asks the torrent client what it is and remembers the answer. Called where TorrentMonitor
    // is talking to the client anyway. Never fails: a client that does not tell is not an error.
    public static function seeClient(array $client): void
    {
        try
        {
            $about = Clients::open($client)->about();
            State::sawService('client:'.$client['id'], $client['type'], (string)$about['version'], (string)$about['details'],
                self::withoutLogin((string)$client['address']), 'Torrent-клиент «'.$client['name'].'»');
        }
        catch (\Throwable $e)
        {
        }
    }

    // The same for the Cloudflare solver. Returns what it said, or NULL when it does not answer.
    public static function seeSolver(): ?array
    {
        $url = (string)Settings::get('flaresolverrUrl');
        if ($url === '')
            return NULL;
        $about = Solver::about(App::http(), $url);
        if ($about !== NULL)
            State::sawService('solver', $about['name'], $about['version'], '', self::withoutLogin($url));
        return $about;
    }
}
