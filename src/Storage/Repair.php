<?php
namespace TorrentMonitor\Storage;

// What a data file holds is checked when it is read. A file can be valid JSON and still be wrong
// inside: a section of another type, a topic without its number, a list where text belongs - the
// trace of an edit by hand, a broken disk or another program. The rest of the code relies on the
// shape of the data, so it is put right here, once:
//   - what has an obvious value is repaired in memory (a missing field gets its default, a value
//     of a wrong type is replaced, a record that is not a record is left out); the file itself is
//     rewritten the next time the program saves it;
//   - a section that cannot be guessed (the settings, the list of topics) makes the file damaged:
//     Damaged is thrown and the program refuses the file instead of working on emptiness.
// Fields and sections this version does not know are kept as they are.
final class Repair
{
    public static function config(array $d): array
    {
        if ( ! isset($d['settings']) || ! is_array($d['settings']) || ( ! empty($d['settings']) && array_keys($d['settings']) === range(0, count($d['settings']) - 1)))
            throw new Damaged('раздел settings');
        foreach ($d['settings'] as $key => $value)
        {
            if ($value === NULL || is_scalar($value))
                $d['settings'][$key] = (string)$value;
            else
                unset($d['settings'][$key]);        //the default of the setting is used
        }
        $d['trackers'] = self::records(isset($d['trackers']) ? $d['trackers'] : array());
        foreach ($d['trackers'] as $name => $account)
            foreach ($account as $field => $value)
                if ($field != 'proxy' && ! is_scalar($value) && $value !== NULL)
                    $d['trackers'][$name][$field] = '';
                elseif ($field != 'proxy')
                    $d['trackers'][$name][$field] = (string)$value;
        $d['notifications'] = array_filter(self::section(isset($d['notifications']) ? $d['notifications'] : array()), 'is_scalar');
        $d['trackerProxy'] = self::records(isset($d['trackerProxy']) ? $d['trackerProxy'] : array());
        return $d;
    }

    public static function torrents(array $d): array
    {
        if ( ! isset($d['topics']) || ! is_array($d['topics']))
            throw new Damaged('раздел topics');

        $strings = array('tracker' => '', 'name' => '', 'topicId' => '', 'quality' => 'sd', 'episode' => '', 'updatedAt' => '', 'hash' => '',
            'path' => '', 'category' => '', 'script' => '', 'sonarrTitle' => '', 'addedAt' => '', 'lastActivityAt' => '');
        $topics = array();
        $ids = array();
        foreach ($d['topics'] as $topic)
        {
            if ( ! is_array($topic))
                continue;
            foreach ($strings as $field => $default)
                $topic[$field] = isset($topic[$field]) && is_scalar($topic[$field]) ? (string)$topic[$field] : $default;
            if ($topic['quality'] === '')
                $topic['quality'] = 'sd';
            foreach (array('autoRename', 'paused', 'error', 'closed') as $field)
                $topic[$field] = isset($topic[$field]) && self::truth($topic[$field]);
            foreach (array('stopAfterDays', 'sonarrSeries') as $field)
                $topic[$field] = isset($topic[$field]) && is_numeric($topic[$field]) ? (int)$topic[$field] : NULL;
            $topic['sonarrSeasons'] = isset($topic['sonarrSeasons']) && is_array($topic['sonarrSeasons'])
                ? array_values(array_map('intval', array_filter($topic['sonarrSeasons'], 'is_numeric'))) : array();
            //a number of its own; one that is missing or taken is given below
            $id = isset($topic['id']) && is_numeric($topic['id']) && (int)$topic['id'] > 0 && ! isset($ids[(int)$topic['id']]) ? (int)$topic['id'] : NULL;
            if ($id !== NULL)
                $ids[$id] = TRUE;
            $topic['id'] = $id;
            $topics[] = $topic;
        }
        $next = max(empty($ids) ? 0 : max(array_keys($ids)), isset($d['nextId']) && is_numeric($d['nextId']) ? (int)$d['nextId'] - 1 : 0) + 1;
        foreach ($topics as $i => $topic)
            if ($topic['id'] === NULL)
                $topics[$i]['id'] = $next++;
        $d['topics'] = $topics;
        $d['nextId'] = $next;

        $users = array();
        $next = 1;
        foreach (self::section(isset($d['users']) ? $d['users'] : array()) as $user)
        {
            if ( ! is_array($user) || ! isset($user['id']) || ! is_numeric($user['id']))
                continue;
            $user['id'] = (int)$user['id'];
            foreach (array('tracker', 'name') as $field)
                $user[$field] = isset($user[$field]) && is_scalar($user[$field]) ? (string)$user[$field] : '';
            $users[] = $user;
            $next = max($next, $user['id'] + 1);
        }
        $d['users'] = $users;
        $d['nextUserId'] = max($next, isset($d['nextUserId']) && is_numeric($d['nextUserId']) ? (int)$d['nextUserId'] : 1);
        return $d;
    }

    // The state is what the program can find out again: nothing in it makes the file damaged
    public static function state(array $d): array
    {
        foreach (array('updateNotified', 'channelChanged') as $key)
            $d[$key] = isset($d[$key]) && self::truth($d[$key]);
        foreach (array('program', 'lastRunSummary', 'lastRun') as $key)
            $d[$key] = isset($d[$key]) && is_scalar($d[$key]) ? (string)$d[$key] : '';

        $d['services'] = self::records(isset($d['services']) ? $d['services'] : array());
        foreach ($d['services'] as $key => $service)
            foreach (array('name', 'version', 'details', 'address', 'at') as $field)
                $d['services'][$key][$field] = isset($service[$field]) && is_scalar($service[$field]) ? (string)$service[$field] : '';
        $d['logins'] = array_filter(self::section(isset($d['logins']) ? $d['logins'] : array()), 'is_numeric');
        $d['cookies'] = array_map('strval', array_filter(self::section(isset($d['cookies']) ? $d['cookies'] : array()), 'is_scalar'));
        $d['clearance'] = self::records(isset($d['clearance']) ? $d['clearance'] : array());
        $d['pending'] = self::records(isset($d['pending']) ? $d['pending'] : array());

        $warnings = array();
        $next = 1;
        foreach (self::section(isset($d['warnings']) ? $d['warnings'] : array()) as $w)
        {
            if ( ! is_array($w) || ! isset($w['id'], $w['source'], $w['code']) || ! is_numeric($w['id']) || ! is_scalar($w['source']) || ! is_scalar($w['code']))
                continue;
            $w['id'] = (int)$w['id'];
            $w['source'] = (string)$w['source'];
            $w['code'] = (string)$w['code'];
            $w['time'] = isset($w['time']) && is_scalar($w['time']) ? (string)$w['time'] : '';
            $w['topicId'] = isset($w['topicId']) && is_numeric($w['topicId']) ? (int)$w['topicId'] : NULL;
            $warnings[] = $w;
            $next = max($next, $w['id'] + 1);
        }
        $d['warnings'] = $warnings;
        $d['nextWarningId'] = max($next, isset($d['nextWarningId']) && is_numeric($d['nextWarningId']) ? (int)$d['nextWarningId'] : 1);

        $found = array();
        $next = 1;
        foreach (self::section(isset($d['found']) ? $d['found'] : array()) as $item)
        {
            if ( ! is_array($item) || ! isset($item['id']) || ! is_numeric($item['id']))
                continue;
            $item['id'] = (int)$item['id'];
            foreach ($item as $field => $value)
                if (is_array($value))
                    $item[$field] = '';
            $found[] = $item;
            $next = max($next, $item['id'] + 1);
        }
        $d['found'] = $found;
        $d['nextFoundId'] = max($next, isset($d['nextFoundId']) && is_numeric($d['nextFoundId']) ? (int)$d['nextFoundId'] : 1);
        return $d;
    }

    //a section that must be a list or a map: anything else is an empty one
    private static function section($value): array
    {
        return is_array($value) ? $value : array();
    }

    //a map of records: what is not a record is left out
    private static function records($value): array
    {
        return array_filter(self::section($value), 'is_array');
    }

    //yes or no, however it was written
    private static function truth($value): bool
    {
        if (is_string($value))
            return ! in_array(strtolower(trim($value)), array('', '0', 'no', 'false', 'off', 'нет'), TRUE);
        return is_scalar($value) && (bool)$value;
    }
}
