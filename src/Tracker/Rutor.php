<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Request;

final class Rutor extends ForumTracker
{
    const ADDRESSES = array('https://rutor.is');
    const NEEDS_ACCOUNT = FALSE;

    protected function topicRequest(string $topicId): Request
    {
        return $this->get('/torrent/'.rawurlencode($topicId).'/')->follow();
    }

    protected function parseDate(string $page): ?array
    {
        if ( ! preg_match('/<td class=\"header\">Добавлен<\/td><td>(.+) \((.+) назад\)<\/td>/', $page, $m))
            return NULL;
        //21-11-2026 10:15:30
        $time = strtotime($m[1]);
        if ($time === FALSE)
            return NULL;
        $parts = preg_split('/\s/', str_replace('-', ' ', $m[1]));
        $text = count($parts) >= 4 ? $parts[0].' '.self::monthText($parts[1]).' '.$parts[2].' '.$parts[3] : $m[1];
        return array(date('Y-m-d H:i:s', $time), $text);
    }

    private static function monthText(string $month): string
    {
        return ctype_digit($month) && self::monthName($month) !== '' ? self::monthName($month) : $month;
    }

    protected function parseTitle(string $page): ?string
    {
        $title = self::pageTitle($page);
        //rutor.info :: Название
        return $title === NULL ? NULL : preg_replace('/^rutor\.\w+\s*::\s*/iu', '', $title);
    }

    //torrent-файлы лежат на отдельном адресе
    protected function downloadRequest(string $topicId, string $page): ?Request
    {
        return $this->get('https://d.rutor.info/download/'.rawurlencode($topicId))->follow();
    }

    public function topicUrl(string $topicId): string
    {
        return $this->base().'/torrent/'.$topicId;
    }

    public function topicIdFromUrl(string $url): ?string
    {
        return preg_match('/\d{4,8}/', (string)parse_url($url, PHP_URL_PATH), $m) ? $m[0] : NULL;
    }
}
