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

    // The errors of many topics in one write of the error list: each array(source, code, topic).
    // The topics are not marked here: the caller saves that mark together with its other changes.
    public static function reportTopics(array $items): void
    {
        $rows = array();
        foreach ($items as $item)
            $rows[] = array($item[0], $item[1], $item[2]['id']);
        $first = Warnings::addMany($rows);
        //the first error of a source is announced, as with a single report
        foreach ($first as $source)
            foreach ($items as $item)
            {
                if ($item[0] != $source)
                    continue;
                $topic = $item[2];
                $url = $topic['topicId'] !== '' && Registry::has($topic['tracker']) ? Registry::get($topic['tracker'])->topicUrl($topic['topicId']) : '';
                Notifications::warning($source, $item[1], $url);
                break;
            }
    }
}
