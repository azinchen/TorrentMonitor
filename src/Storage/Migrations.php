<?php
namespace TorrentMonitor\Storage;

// Migrations of data files from old format versions to the next one.
final class Migrations
{
    // Series quality from the old numeric hd field: LostFilm had the values in a different order
    public static function quality(string $tracker, $hd): string
    {
        $hd = (int)$hd;
        if ($hd == 0)
            return 'sd';
        $lostfilm = ($tracker == 'lostfilm.tv' || $tracker == 'lostfilm-mirror');
        return ($hd == 1) == $lostfilm ? '1080' : '720';
    }

    private static function date($value): string
    {
        $value = (string)$value;
        return ($value === '' || strncmp($value, '0000', 4) === 0) ? '' : $value;
    }

    // torrents.json 1 -> 2: clear field names
    public static function torrents1(array $data): array
    {
        $topics = array();
        foreach (isset($data['torrents']) ? $data['torrents'] : array() as $t)
        {
            $topics[] = array(
                'id'             => (int)$t['id'],
                'tracker'        => (string)$t['tracker'],
                'name'           => (string)$t['name'],
                'topicId'        => (string)$t['torrent_id'],
                'quality'        => self::quality((string)$t['tracker'], isset($t['hd']) ? $t['hd'] : 0),
                'episode'        => (string)$t['ep'],
                'updatedAt'      => self::date($t['timestamp']),
                'autoRename'     => ! empty($t['auto_update']),
                'hash'           => (string)$t['hash'],
                'path'           => (string)$t['path'],
                'category'       => isset($t['category']) ? (string)$t['category'] : '',
                'script'         => (string)$t['script'],
                'paused'         => ! empty($t['pause']),
                'error'          => ! empty($t['error']),
                'closed'         => ! empty($t['closed']),
                'stopAfterDays'  => NULL,
                'sonarrSeries'   => NULL,
                'addedAt'        => isset($t['addedAt']) ? (string)$t['addedAt'] : '',
                'lastActivityAt' => isset($t['lastActivityAt']) ? (string)$t['lastActivityAt'] : '',
            );
        }
        unset($data['torrents']);
        $data['topics'] = $topics;
        return $data;
    }

    //state.json 1 -> 2
    public static function state1(array $data): array
    {
        $old = isset($data['settings']) ? $data['settings'] : array();
        $data['updateNotified'] = ! empty($old['sentUpdateNotification']);

        $lastStart = isset($data['lastStart']) ? (string)$data['lastStart'] : '';
        $time = $lastStart === '' ? FALSE : \DateTime::createFromFormat('d-m-Y H:i:s', $lastStart);
        $data['lastRun'] = $time ? $time->format('Y-m-d H:i:s') : '';

        $warnings = array();
        foreach (isset($data['warnings']) ? $data['warnings'] : array() as $w)
            $warnings[] = array('id' => (int)$w['id'], 'time' => (string)$w['time'], 'source' => (string)$w['where'],
                'code' => (string)$w['reason'], 'topicId' => isset($w['t_id']) ? $w['t_id'] : NULL);
        $data['warnings'] = $warnings;

        $news = array();
        foreach (isset($data['news']) ? $data['news'] : array() as $n)
            $news[] = array('id' => (string)$n['id'], 'text' => (string)$n['text'], 'unread' => ! empty($n['new']));
        $data['news'] = $news;

        $found = array();
        foreach (isset($data['buffer']) ? $data['buffer'] : array() as $b)
            $found[] = array('id' => (int)$b['id'], 'userId' => (int)$b['user_id'], 'tracker' => (string)$b['tracker'],
                'section' => (string)$b['section'], 'topicId' => (string)$b['threme_id'], 'title' => (string)$b['threme'],
                'date' => (string)$b['timestamp'], 'accepted' => ! empty($b['accept']), 'downloaded' => ! empty($b['downloaded']),
                'unread' => ! empty($b['new']));
        $data['found'] = $found;
        $data['nextFoundId'] = isset($data['nextBufferId']) ? (int)$data['nextBufferId'] : 1;

        $pending = array();
        foreach (isset($data['temp']) ? $data['temp'] : array() as $id => $t)
            $pending[(string)$id] = array('name' => (string)$t['name'], 'file' => basename((string)$t['path']),
                'tracker' => (string)$t['tracker'], 'date' => (string)$t['date']);
        $data['pending'] = $pending;

        unset($data['settings'], $data['lastStart'], $data['buffer'], $data['nextBufferId'], $data['temp']);
        return $data;
    }
}
