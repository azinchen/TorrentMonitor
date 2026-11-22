<?php
namespace TorrentMonitor\Data;

use TorrentMonitor\Storage\Files;

// Watched topics and series (torrents.json).
// Topic fields: id, tracker, name, topicId (topic number or path on the tracker; empty for series),
// quality (sd|720|1080, for series), episode (S01E02), updatedAt (torrent date on the tracker),
// autoRename (take the name from the tracker), hash (the torrent in the torrent client), path, category, script,
// paused, error, closed, stopAfterDays (NULL - as in the settings), sonarrSeries (series number in Sonarr,
// if the topic was added by it), addedAt, lastActivityAt.
final class Topics
{
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private static function index(array $list, $id): ?int
    {
        foreach ($list as $i => $item)
            if ($item['id'] == $id)
                return $i;
        return NULL;
    }

    /** @return array[] */
    public static function all(): array
    {
        return Files::torrents()->read()['topics'];
    }

    public static function get($id): ?array
    {
        $topics = self::all();
        $i = self::index($topics, $id);
        return $i === NULL ? NULL : $topics[$i];
    }

    // A forum tracker topic by its number on the tracker
    public static function find(string $tracker, string $topicId): ?array
    {
        foreach (self::all() as $topic)
            if ($topic['tracker'] == $tracker && $topic['topicId'] !== '' && $topic['topicId'] == $topicId)
                return $topic;
        return NULL;
    }

    public static function findSeries(string $tracker, string $name, string $quality): ?array
    {
        foreach (self::all() as $topic)
            if ($topic['tracker'] == $tracker && $topic['topicId'] === '' && $topic['name'] == $name && $topic['quality'] == $quality)
                return $topic;
        return NULL;
    }

    public static function findByHash(string $hash): ?array
    {
        if ($hash === '')
            return NULL;
        foreach (self::all() as $topic)
            if (strcasecmp($topic['hash'], $hash) == 0)
                return $topic;
        return NULL;
    }

    // Adds a topic, returns its id
    public static function add(array $fields): int
    {
        $id = 0;
        Files::torrents()->update(function (&$data) use ($fields, &$id) {
            $id = $data['nextId']++;
            $data['topics'][] = array('id' => $id) + array_merge(self::blank(), array_intersect_key($fields, self::blank()));
        });
        return $id;
    }

    private static function blank(): array
    {
        return array(
            'tracker' => '', 'name' => '', 'topicId' => '', 'quality' => 'sd', 'episode' => '', 'updatedAt' => '',
            'autoRename' => FALSE, 'hash' => '', 'path' => '', 'category' => '', 'script' => '', 'paused' => FALSE,
            'error' => FALSE, 'closed' => FALSE, 'stopAfterDays' => NULL, 'sonarrSeries' => NULL, 'addedAt' => self::now(), 'lastActivityAt' => '',
        );
    }

    // Changes the topic's fields; FALSE if there is no such topic
    public static function update($id, array $fields): bool
    {
        return self::updateMany(array($id => $fields)) > 0;
    }

    // Changes several topics in a single file write: id => fields. Returns the number of topics found.
    public static function updateMany(array $updates): int
    {
        $found = 0;
        if (empty($updates))
            return 0;
        Files::torrents()->update(function (&$data) use ($updates, &$found) {
            $changed = FALSE;
            foreach ($updates as $id => $fields)
            {
                $i = self::index($data['topics'], $id);
                if ($i === NULL)
                    continue;
                $found++;
                $before = $data['topics'][$i];
                $fields = array_intersect_key($fields, $before);
                unset($fields['id']);
                // A change of the torrent date counts as a topic update
                if (isset($fields['updatedAt']) && $fields['updatedAt'] !== '' && $fields['updatedAt'] != $before['updatedAt'])
                    $fields['lastActivityAt'] = self::now();
                $data['topics'][$i] = array_merge($before, $fields);
                $changed = $changed || $data['topics'][$i] !== $before;
            }
            return $changed;
        });
        return $found;
    }

    public static function delete($id): bool
    {
        $found = FALSE;
        Files::torrents()->update(function (&$data) use ($id, &$found) {
            $i = self::index($data['topics'], $id);
            if ($i === NULL)
                return FALSE;
            array_splice($data['topics'], $i, 1);
            return $found = TRUE;
        });
        if ($found)
            Pending::remove($id);
        return $found;
    }

    /** @return string[] */
    public static function categories(): array
    {
        $categories = array_values(array_unique(array_filter(array_column(self::all(), 'category'), 'strlen')));
        sort($categories);
        return $categories;
    }

    // Whether checking of the topic has stopped: it has not been updated for a long time.
    // $globalDays - the stopAfterDays setting; a topic may have its own value (0 - always check).
    public static function expired(array $topic, int $globalDays, ?int $now = NULL): bool
    {
        $days = $topic['stopAfterDays'] === NULL ? $globalDays : (int)$topic['stopAfterDays'];
        if ($days <= 0)
            return FALSE;
        $last = $topic['lastActivityAt'] !== '' ? $topic['lastActivityAt'] : $topic['addedAt'];
        $time = $last === '' ? FALSE : strtotime($last);
        if ($time === FALSE)
            return FALSE;
        return ($now === NULL ? time() : $now) - $time > $days * 86400;
    }
}
