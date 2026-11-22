<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Request;
use TorrentMonitor\Http\Response;

//Трекер, где отслеживается тема: на её странице есть дата раздачи; когда дата меняется,
//torrent-файл скачивается заново.
abstract class ForumTracker extends Tracker
{
    //TRUE - скачивать, только если дата на трекере новее сохранённой; FALSE - при любом отличии
    const NEWER_ONLY = FALSE;

    //запрос страницы темы
    abstract protected function topicRequest(string $topicId): Request;

    //Дата раздачи со страницы темы: array('Y-m-d H:i:s', 'дата как на трекере') или NULL.
    abstract protected function parseDate(string $page): ?array;

    //запрос torrent-файла (по странице темы, если ссылка берётся с неё); NULL - ссылка не найдена
    abstract protected function downloadRequest(string $topicId, string $page): ?Request;

    //название темы со страницы темы
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

    //часть имени torrent-файла
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
