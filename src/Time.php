<?php
namespace TorrentMonitor;

use TorrentMonitor\Data\Accounts;
use TorrentMonitor\Data\Settings;

// Time zones.
//
// Two kinds of time are stored, both as 'Y-m-d H:i:s' without a zone:
//   - the release date of a topic (updatedAt): the clock of the tracker, as its page shows it;
//   - everything the program records itself (last run, errors, added at): the clock of the server,
//     which is the time zone PHP is set up with.
// The stored values are never converted: the date of a topic is compared with what the tracker
// shows to see whether the topic changed. This class tells which zone a tracker lives in, turns
// "today" and "yesterday" of a tracker into a date, and converts both kinds of time to UTC for
// the interface, which shows them in the time zone of the browser.
final class Time
{
    // the trackers TorrentMonitor works with show Moscow time
    const DEFAULT_ZONE = 'Europe/Moscow';

    // The time zone of a tracker: its own setting, else the general one, else Moscow
    public static function zone(string $tracker = ''): \DateTimeZone
    {
        $names = array();
        if ($tracker !== '')
            $names[] = Accounts::timezone($tracker);
        $names[] = (string)Settings::get('trackerTimezone');
        foreach ($names as $name)
            if (self::valid($name))
                return new \DateTimeZone($name);
        return new \DateTimeZone(self::DEFAULT_ZONE);
    }

    public static function valid(string $name): bool
    {
        return $name !== '' && in_array($name, \DateTimeZone::listIdentifiers(), TRUE);
    }

    /** @return string[] every time zone name PHP knows, for the settings */
    public static function names(): array
    {
        return \DateTimeZone::listIdentifiers();
    }

    // The moment $now (unix time, default: this moment) on the clock of a zone
    public static function at(\DateTimeZone $zone, ?int $now = NULL): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.($now === NULL ? time() : $now)))->setTimezone($zone);
    }

    // A clock reading of a zone ('Y-m-d H:i:s', or any text PHP understands), or NULL if it is not a date
    public static function read(string $text, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        try
        {
            return trim($text) === '' ? NULL : new \DateTimeImmutable(trim($text), $zone);
        }
        catch (\Exception $e)
        {
            return NULL;
        }
    }

    // The same moment in UTC, as the interface and the API get it: 2026-11-23T11:23:00Z. '' if $wall is not a date.
    public static function utc(string $wall, \DateTimeZone $zone): string
    {
        $time = self::read($wall, $zone);
        return $time === NULL ? '' : $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    // UTC of the release date of a topic (the clock of its tracker)
    public static function trackerUtc(string $wall, string $tracker): string
    {
        return self::utc($wall, self::zone($tracker));
    }

    // UTC of a time the program recorded itself (the clock of the server)
    public static function serverUtc(string $wall): string
    {
        return self::utc($wall, new \DateTimeZone(date_default_timezone_get()));
    }

    // A release date for a notification or the log, where there is no browser to show it in the
    // reader's zone: on the clock of the server and, when that differs, on the clock of the tracker.
    // "23 ноя 2026, 06:23 (на трекере 14:23 МСК)"; '' if $wall is not a date.
    public static function both(string $wall, string $tracker): string
    {
        $zone = self::zone($tracker);
        $there = self::read($wall, $zone);
        if ($there === NULL)
            return '';
        $months = array('янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек');
        $day = function (\DateTimeImmutable $time) use ($months) { return $time->format('j').' '.$months[(int)$time->format('n') - 1]; };
        $here = $there->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $text = $day($here).' '.$here->format('Y, H:i');
        if ($here->format('Y-m-d H:i') == $there->format('Y-m-d H:i'))
            return $text;
        //the day is repeated only when it is another day on the tracker
        return $text.' (на трекере '.($here->format('Y-m-d') == $there->format('Y-m-d') ? '' : $day($there).', ').$there->format('H:i').' '.self::label($zone, $wall).')';
    }

    // Short name of a zone at a moment: МСК for Moscow, otherwise the abbreviation (EET) or the offset (UTC+5)
    public static function label(\DateTimeZone $zone, string $wall = ''): string
    {
        if ($zone->getName() == self::DEFAULT_ZONE)
            return 'МСК';
        $time = self::read($wall, $zone) ?: self::at($zone);
        $short = $time->format('T');
        if (preg_match('/^[A-Z]{2,5}$/', $short))
            return $short;
        $offset = $time->getOffset();
        $text = 'UTC'.($offset < 0 ? '-' : '+').intdiv(abs($offset), 3600);
        return abs($offset) % 3600 ? $text.':'.sprintf('%02d', intdiv(abs($offset) % 3600, 60)) : $text;
    }
}
