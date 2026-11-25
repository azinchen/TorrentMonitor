<?php
namespace TorrentMonitor;

// Private values in the logs. A log line may name where the user's services are, a folder of the
// server, a user of a tracker: useful to the owner, not to be shown to others. Such a value is
// written inside a mark, {{a:http://host:8080}}, so that it can be hidden later without guessing:
// the interface shows the real text or a stand-in ("адрес 1") as the reader chooses, and the
// output of a check run (cron, docker logs) is printed with the marks taken off.
// Kinds: a - an address of a service, d - a folder, u - a user of a tracker, i - an IP address,
// h - a host name inside an error text.
// Secrets (passwords, keys, cookies) are another matter: they are never written at all.
// Titles and addresses of topics and trackers are not private.
final class Privacy
{
    const NAMES = array('a' => 'адрес', 'd' => 'каталог', 'u' => 'пользователь', 'i' => 'IP-адрес', 'h' => 'узел');
    const PATTERN = '/\{\{([adiuh]):(.*?)\}\}/su';

    public static function mark(string $kind, string $value): string
    {
        if ($value === '' || ! isset(self::NAMES[$kind]))
            return $value;
        //a value cannot hold the end of a mark
        return '{{'.$kind.':'.str_replace('}}', '} }', $value).'}}';
    }

    public static function address(string $value): string { return self::mark('a', $value); }
    public static function folder(string $value): string { return self::mark('d', $value); }
    public static function user(string $value): string { return self::mark('u', $value); }
    public static function ip(string $value): string { return self::mark('i', $value); }
    public static function host(string $value): string { return self::mark('h', $value); }

    // The text as it was meant: the marks taken off
    public static function plain(string $text): string
    {
        return strpos($text, '{{') === FALSE ? $text : (string)preg_replace(self::PATTERN, '$2', $text);
    }

    // The text with every private value replaced by a stand-in. One value gets one number
    // throughout a log: pass the same $seen for all its lines.
    public static function hidden(string $text, array &$seen): string
    {
        if (strpos($text, '{{') === FALSE)
            return $text;
        return (string)preg_replace_callback(self::PATTERN, function ($m) use (&$seen) {
            if ( ! isset($seen[$m[1]][$m[2]]))
                $seen[$m[1]][$m[2]] = (isset($seen[$m[1]]) ? count($seen[$m[1]]) : 0) + 1;
            return self::NAMES[$m[1]].' '.$seen[$m[1]][$m[2]];
        }, $text);
    }
}
