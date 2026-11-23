<?php
namespace TorrentMonitor\Data;

use TorrentMonitor\Storage\Files;

// Downloaded torrent files that could not be handed to the torrent client (state.json).
// The handover is retried on every run. The key is the topic id; fields: name, file, tracker, date.
final class Pending
{
    /** @return array<string,array> */
    public static function all(): array
    {
        return Files::state()->read()['pending'];
    }

    public static function add($topicId, string $name, string $file, string $tracker, string $date): void
    {
        Files::state()->update(function (&$data) use ($topicId, $name, $file, $tracker, $date) {
            $data['pending'][(string)$topicId] = array('name' => $name, 'file' => $file, 'tracker' => $tracker, 'date' => $date);
        });
    }

    public static function remove($topicId): void
    {
        Files::state()->update(function (&$data) use ($topicId) {
            if ( ! isset($data['pending'][(string)$topicId]))
                return FALSE;
            unset($data['pending'][(string)$topicId]);
            return TRUE;
        });
    }

    public static function clear(): void
    {
        Files::state()->update(function (&$data) {
            if (empty($data['pending']))
                return FALSE;
            $data['pending'] = array();
            return TRUE;
        });
    }
}
