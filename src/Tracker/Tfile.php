<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Request;

// No account needed. Forum pages are not re-encoded, the search (search.tfile.cc) is in windows-1251.
final class Tfile extends ForumTracker
{
    const ADDRESSES = array('http://tfile.cc');
    const NEEDS_ACCOUNT = FALSE;

    protected function topicRequest(string $topicId): Request
    {
        return $this->get('/forum/viewtopic.php?t='.rawurlencode($topicId));
    }

    protected function parseDate(string $page): ?array
    {
        if ( ! preg_match('/class=\"regDate\">(.+)<\/span>/', $page, $m))
            return NULL;
        //2026-11-21 10:00
        if ( ! preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2})/', trim($m[1]), $d) || self::monthName($d[2]) === '')
            return NULL;
        return array($d[1].'-'.$d[2].'-'.$d[3].' '.$d[4].':00', $d[3].' '.self::monthName($d[2]).' '.$d[1].' '.$d[4]);
    }

    // 15 bytes at the start and 25 at the end of the title are cut off (the site name)
    protected function parseTitle(string $page): ?string
    {
        $title = self::pageTitle($page);
        if ($title === NULL)
            return NULL;
        if (preg_match('/<title>(.*)<\/title>/', $page, $m) && strlen($m[1]) > 40)
        {
            $name = trim(html_entity_decode(substr($m[1], 15, -25), ENT_QUOTES, 'UTF-8'));
            if ($name !== '' && mb_check_encoding($name, 'UTF-8'))
                return $name;
        }
        return $title;
    }

    protected function downloadRequest(string $topicId, string $page): ?Request
    {
        // topic checking used to look for &ak=, downloading user topics - for &uk=
        if ( ! preg_match('/download\.php\?id=(\d+)&(?:ak|uk)=(\d+)/', $page, $link))
            return NULL;
        return $this->get('/forum/download.php?id='.$link[1].'&ak='.$link[2])
            ->referer($this->url('/forum/viewtopic.php?t='.rawurlencode($topicId)));
    }

    // the link to the torrent file is only on the topic page
    public function download(string $topicId): string
    {
        $response = $this->send($this->topicRequest($topicId));
        if ($response->body === '' || $response->challenged)
            throw new TrackerException('cant_get_forum_page');
        return $this->fetchTorrent($topicId, $response->body);
    }

    public function topicUrl(string $topicId): string
    {
        return $this->base().'/forum/viewtopic.php?t='.$topicId;
    }

    public function topicIdFromUrl(string $url): ?string
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        return isset($query['t']) && ctype_digit((string)$query['t']) ? (string)$query['t'] : NULL;
    }

    public function userTopics(string $user): ?array
    {
        $request = $this->get('http://search.tfile.cc/?q=&c=0&g=&act=&y=&ql=&a='.rawurlencode((string)@iconv('utf-8', 'windows-1251', $user))
            .'&d=&o=&size_min=0&size_max=0')->charset('windows-1251');
        $response = $this->send($request);
        $page = $response->body;
        if ($page === '' || $response->challenged)
            throw new TrackerException('cant_get_forum_page');

        // section: all forum links from the cell, comma-separated
        $sections = array();
        preg_match_all('/<td class=\"f\">\n\t\t\t\t\n\t\t\t\t\t(.*)\n\t\t\t\t<\/td>/', $page, $cells);
        foreach ($cells[1] as $cell)
            if (preg_match_all('/<a href=\"\/forum\/viewforum\.php\?f=\d{1,9}\">(.*)<\/a>/U', $cell, $m))
                $sections[] = implode(', ', $m[1]);
        preg_match_all('/<a href=\"http:\/\/tfile\.cc\/forum\/viewtopic\.php\?t=(\d{1,9})\">(.*)<\/a>/U', $page, $topic);
        preg_match_all('/<td class=\"ms\">\n\t\t\t\t(.*)\s.*<br\/>\n\t\t\t<\/td>/', $page, $dates);

        $result = array();
        if (count($sections) != count($topic[1]) || count($topic[1]) != count($dates[1]))
            return $result;
        foreach ($topic[1] as $i => $topicId)
        {
            //2026-11-21
            $date = preg_match('/\d{4}-\d{2}-\d{2}/', $dates[1][$i], $d) ? $d[0] : trim($dates[1][$i]);
            $result[] = array('section' => strip_tags($sections[$i]), 'topicId' => $topicId, 'title' => strip_tags($topic[2][$i]), 'date' => $date);
        }
        return $result;
    }
}
