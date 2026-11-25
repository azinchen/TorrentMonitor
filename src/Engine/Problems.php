<?php
namespace TorrentMonitor\Engine;

use TorrentMonitor\Data\Topics;
use TorrentMonitor\Data\Warnings;
use TorrentMonitor\Notify\Notifications;
use TorrentMonitor\Tracker\Registry;

// Recording runtime errors: to the journal of events, to the error list (it marks topics and
// trackers in the interface and is what the API returns), to the topic and (first error of
// a source) to a notification.
final class Problems
{
    // $source - tracker, torrent client, api or system; $topic - the topic the error relates to
    /** @var array<string,bool>|null errors that were there before the check run began (see remember()) */
    private static ?array $known = NULL;

    // A check run reports an error that lasts again every time. Called when a run begins: the errors
    // of the list as it is then are not written to the journal once more, only the new ones are.
    public static function remember(): void
    {
        self::$known = array();
        foreach (Warnings::all() as $w)
            self::$known[self::key((string)$w['source'], (string)$w['code'], $w['topicId'])] = TRUE;
    }

    private static function key(string $source, string $code, $topicId): string
    {
        return $source.'|'.$code.'|'.($topicId === NULL ? '' : (int)$topicId);
    }

    // Writes errors to the journal, one line per source and kind of error: each array(source, code, topic or NULL)
    private static function journal(array $items): void
    {
        $groups = array();
        foreach ($items as $item)
        {
            $key = self::key($item[0], $item[1], $item[2] === NULL ? NULL : $item[2]['id']);
            if (self::$known !== NULL)
            {
                if (isset(self::$known[$key]))
                    continue;
                self::$known[$key] = TRUE;
            }
            $group = $item[0].'|'.$item[1];
            if ( ! isset($groups[$group]))
                $groups[$group] = array($item[0], $item[1], array());
            if ($item[2] !== NULL)
                $groups[$group][2][] = $item[2]['name'] !== '' ? '«'.$item[2]['name'].'»' : 'тема '.$item[2]['topicId'];
        }
        foreach ($groups as $g)
        {
            $names = $g[2];
            $more = count($names) - 3;
            $about = empty($names) ? '' : (count($names) == 1 ? ' Тема '.$names[0].'.'
                : ' Тем: '.count($names).' — '.implode(', ', array_slice($names, 0, 3)).($more > 0 ? ' и ещё '.$more : '').'.');
            \TorrentMonitor\Data\Journal::add('error', $g[0].': '.\TorrentMonitor\Messages::text($g[1]).$about);
        }
    }

    public static function report(string $source, string $code, ?array $topic = NULL): void
    {
        self::journal(array(array($source, $code, $topic)));
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
        self::journal($items);
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
