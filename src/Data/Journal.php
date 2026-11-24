<?php
namespace TorrentMonitor\Data;

use TorrentMonitor\Storage\Files;

// The journal of events (journal.log in the data directory): one line per event, the newest last.
//   [2026-11-24 12:00:00] run: Проверка (по расписанию), 20 с: ...
// Kinds: run - a check run and its result; update - a version installed or not; settings - settings
// changed; tracker - a tracker's account or address changed; topic - a topic added, removed or
// replaced; access - sign-in and keys; service - the torrent client or the Cloudflare solver seen;
// data - data converted. Nothing secret is written: passwords and keys are only said to have changed.
// The file keeps as many lines as the journalSize setting says (0 - no journal at all).
final class Journal
{
    const KINDS = array('run', 'update', 'settings', 'tracker', 'topic', 'access', 'service', 'data');

    //who makes the changes of this request, as the journal names it: «интерфейс», «API», «Sonarr»
    public static string $by = '';

    private static function by(): string
    {
        return self::$by !== '' ? ' ('.self::$by.')' : '';
    }

    //how settings are named in the journal
    const SETTINGS = array(
        'serverAddress' => 'адрес TorrentMonitor', 'auth' => 'защита паролем', 'rss' => 'RSS-лента', 'debug' => 'подробный журнал проверки',
        'autoUpdate' => 'автоматические обновления', 'updateChannel' => 'версии для установки', 'userAgent' => 'User-Agent',
        'httpTimeout' => 'время ожидания ответа', 'httpConcurrency' => 'запросов к одному трекеру', 'httpSites' => 'трекеров одновременно',
        'flaresolverrUrl' => 'решатель Cloudflare', 'proxy' => 'прокси', 'proxyAddress' => 'адрес прокси', 'proxyType' => 'тип прокси',
        'useTorrent' => 'передача в torrent-клиент', 'torrentClient' => 'torrent-клиент', 'torrentAddress' => 'адрес клиента',
        'torrentLogin' => 'логин клиента', 'torrentPassword' => 'пароль клиента', 'pathToDownload' => 'каталог загрузки', 'qbitCategory' => 'категория',
        'deleteDistribution' => 'удаление раздачи предыдущей серии', 'deleteOldFiles' => 'удаление файлов предыдущей серии',
        'deleteFilesOnRemove' => 'удаление файлов вместе с темой', 'stopAfterDays' => 'прекращать проверку через',
        'removeOnSeriesDelete' => 'удаление тем при удалении сериала в Sonarr', 'sonarrReplaceTopic' => 'замена темы сезона из Sonarr',
        'sonarrReplaceTorrent' => 'удаление раздачи заменённой темы', 'send' => 'уведомления', 'sendUpdate' => 'уведомления об обновлениях',
        'sendWarning' => 'уведомления об ошибках', 'sendUpdateService' => 'сервис уведомлений об обновлениях',
        'sendWarningService' => 'сервис уведомлений об ошибках', 'trackerTimezone' => 'часовой пояс трекеров',
        'dataFileMode' => 'права на файлы данных', 'journalSize' => 'длина журнала',
    );
    //settings that are on or off
    const SWITCHES = array('auth', 'rss', 'debug', 'autoUpdate', 'proxy', 'useTorrent', 'deleteDistribution', 'deleteOldFiles', 'deleteFilesOnRemove',
        'removeOnSeriesDelete', 'sonarrReplaceTopic', 'sonarrReplaceTorrent', 'send', 'sendUpdate', 'sendWarning');
    //settings whose value is not written: a password, or an address that may carry one
    const HIDDEN = array('torrentPassword', 'torrentLogin', 'proxyAddress', 'sendUpdateService', 'sendWarningService');

    // What changed between two sets of settings, as text: "torrent-клиент: Transmission → qBittorrent;
    // пароль клиента изменён". '' when nothing did.
    public static function settingsText(array $before, array $after): string
    {
        $parts = array();
        foreach (self::SETTINGS as $key => $title)
        {
            $old = isset($before[$key]) ? (string)$before[$key] : '';
            $new = isset($after[$key]) ? (string)$after[$key] : '';
            if ($old === $new)
                continue;
            if (in_array($key, self::HIDDEN, TRUE))
                $parts[] = $title.($new === '' ? ' удалён' : ' изменён');
            elseif (in_array($key, self::SWITCHES, TRUE))
                $parts[] = $title.': '.($new === '1' ? 'включено' : 'выключено');
            else
            {
                $show = function ($value) { return $value === '' ? 'пусто' : \TorrentMonitor\Engine\About::withoutLogin($value); };
                $parts[] = $title.': '.$show($old).' → '.$show($new);
            }
        }
        return implode('; ', $parts);
    }

    public static function settings(array $before, array $after): void
    {
        $text = self::settingsText($before, $after);
        if ($text !== '')
            self::add('settings', 'Настройки'.self::by().': '.$text);
    }

    // A topic added, removed or replaced. $topic - its record.
    public static function topic(string $what, array $topic): void
    {
        $name = isset($topic['name']) && $topic['name'] !== '' ? '«'.$topic['name'].'»' : 'тема '.(isset($topic['topicId']) ? $topic['topicId'] : '');
        self::add('topic', $what.self::by().': '.$name.', '.(isset($topic['tracker']) ? $topic['tracker'] : ''));
    }

    public static function file(): string
    {
        return Files::dir().'/journal.log';
    }

    private static function size(): int
    {
        return max(0, (int)Settings::get('journalSize'));
    }

    public static function add(string $kind, string $text): void
    {
        $size = self::size();
        if ($size == 0 || ! in_array($kind, self::KINDS, TRUE))
            return;
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '')
            return;
        self::change(function (array $lines) use ($kind, $text) {
            $lines[] = '['.date('Y-m-d H:i:s').'] '.$kind.': '.$text;
            return $lines;
        });
    }

    // A wrong password at sign-in. Attempts from one address within ten minutes are counted in one
    // line, so that guessing a password cannot push everything else out of the journal.
    public static function failedSignIn(string $address): void
    {
        if (self::size() == 0)
            return;
        $address = preg_replace('/[^0-9a-fA-F.:]/', '', $address);
        $text = 'Неверный пароль при входе, адрес '.($address !== '' ? $address : 'неизвестен');
        self::change(function (array $lines) use ($text) {
            $last = end($lines);
            if ($last !== FALSE && preg_match('/^\[([^\]]+)\] access: '.preg_quote($text, '/').'(?: ×(\d+))?$/u', $last, $m)
                && time() - (int)strtotime($m[1]) < 600)
            {
                $lines[count($lines) - 1] = '['.$m[1].'] access: '.$text.' ×'.((isset($m[2]) ? (int)$m[2] : 1) + 1);
                return $lines;
            }
            $lines[] = '['.date('Y-m-d H:i:s').'] access: '.$text;
            return $lines;
        });
    }

    // The newest events first: array('time' => 'Y-m-d H:i:s' (the clock of the server), 'kind', 'text')
    public static function recent(int $limit = 200): array
    {
        $events = array();
        foreach (array_reverse(self::lines()) as $line)
        {
            if ( ! preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\] (\w+): (.*)$/u', $line, $m))
                continue;
            $events[] = array('time' => $m[1], 'kind' => $m[2], 'text' => $m[3]);
            if (count($events) >= $limit)
                break;
        }
        return $events;
    }

    private static function lines(): array
    {
        $text = (string)@file_get_contents(self::file());
        return $text === '' ? array() : explode("\n", rtrim($text, "\n"));
    }

    // Rewrites the journal under a lock: $change(array $lines): array
    private static function change(callable $change): void
    {
        if ( ! Files::prepare())
            return;
        $lock = @fopen(self::file().'.lock', 'c');
        if ($lock === FALSE || ! flock($lock, LOCK_EX))
            return;
        try
        {
            $lines = $change(self::lines());
            $size = self::size();
            if (count($lines) > $size)
                $lines = array_slice($lines, -$size);
            $tmp = self::file().'.tmp.'.getmypid();
            if (@file_put_contents($tmp, implode("\n", $lines)."\n") !== FALSE)
            {
                Files::protect($tmp);
                @rename($tmp, self::file());
            }
        }
        finally
        {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
