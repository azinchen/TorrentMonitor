<?php
namespace TorrentMonitor\Engine;

use TorrentMonitor\Data\Topics;
use TorrentMonitor\Data\Warnings;
use TorrentMonitor\Notify\Notifications;
use TorrentMonitor\Tracker\Registry;

// Recording runtime errors: to the error list, to the topic and (first error of a source) to a notification.
final class Problems
{
    // $source - tracker, torrent client, api or system; $topic - the topic the error relates to
    public static function report(string $source, string $code, ?array $topic = NULL): void
    {
        $first = Warnings::add($source, $code, $topic === NULL ? NULL : $topic['id']);
        if ($topic !== NULL)
            Topics::update($topic['id'], array('error' => TRUE));
        if ( ! $first)
            return;
        $url = '';
        if ($topic !== NULL && $topic['topicId'] !== '' && Registry::has($topic['tracker']))
            $url = Registry::get($topic['tracker'])->topicUrl($topic['topicId']);
        Notifications::warning($source, $code, $url);
    }
}
