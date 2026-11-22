<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Request;
use TorrentMonitor\Http\Response;

// A tracker where a topic is watched: its page shows the release date; when the date changes,
// the torrent file is downloaded again.
abstract class ForumTracker extends Tracker
{
    // TRUE - download only if the date on the tracker is newer than the saved one; FALSE - on any difference
    const NEWER_ONLY = FALSE;

    // request for the topic page
    abstract protected function topicRequest(string $topicId): Request;

    // Release date from the topic page: array('Y-m-d H:i:s', 'date as shown on the tracker') or NULL.
    abstract protected function parseDate(string $page): ?array;

    // request for the torrent file (built from the topic page if the link is taken from it); NULL - link not found
    abstract protected function downloadRequest(string $topicId, string $page): ?Request;

    // topic title from the topic page
    protected function parseTitle(string $page): ?string
    {
        return self::pageTitle($page);
    }

    public function request(array $topic): ?Request
    {
        return $this->topicRequest($topic['topicId']);
    }

    public function check(array $topic, Response $response): array
    {
        $page = $response->body;
        if ($response->challenged)
            throw new TrackerException('cloudflare');
        if ($page === '')
            throw new TrackerException('cant_get_forum_page');

        $date = $this->parseDate($page);
        if ($date === NULL || empty($date[0]))
            throw new TrackerException('cant_find_date');

        $changed = static::NEWER_ONLY ? $date[0] > $topic['updatedAt'] : $date[0] != $topic['updatedAt'];
        if ( ! $changed)
            return array();

        $torrent = $this->fetchTorrent($topic['topicId'], $page);

        $name = $topic['name'];
        if ($topic['autoRename'] || $name === '')
        {
            $title = $this->parseTitle($page);
            if ( ! empty($title))
                $name = $title;
        }
        $found = new Found($torrent, $date[0], $date[1], $this->fileId($topic['topicId']), $name.' обновлён.');
        if ($name !== $topic['name'])
            $found->name = $name;
        return array($found);
    }

    protected function fetchTorrent(string $topicId, string $page): string
    {
        $request = $this->downloadRequest($topicId, $page);
        if ($request === NULL)
            throw new TrackerException('cant_find_dowload_link');
        return $this->torrent($request);
    }

    // part of the torrent file name
    protected function fileId(string $topicId): string
    {
        return $topicId;
    }

    public function title(string $topicId): ?string
    {
        $response = $this->send($this->topicRequest($topicId));
        return $response->body === '' ? NULL : $this->parseTitle($response->body);
    }

    public function download(string $topicId): string
    {
        return $this->fetchTorrent($topicId, '');
    }
}
