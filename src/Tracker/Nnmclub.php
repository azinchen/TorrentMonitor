<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Request;

final class Nnmclub extends ForumTracker
{
    // topics and torrent files are open to guests; an account only makes downloads count on the tracker
    const ACCOUNT_OPTIONAL = TRUE;
    const ADDRESSES = array('https://nnmclub.to');
    const CHARSET = 'windows-1251';

    protected function sessionAlive(string $cookie): bool
    {
        $response = $this->send($this->get('/forum/index.php', $cookie));
        return (bool)preg_match('/login\.php\?logout=true/', $response->body);
    }

    protected function signIn(): string
    {
        $body = 'username='.$this->encodedLogin().'&password='.rawurlencode($this->account['password']).'&login=%C2%F5%EE%E4';
        $response = $this->send($this->post('/forum/login.php', $body, ''));

        // with a wrong password the site sets a cookie too (a guest session), so check the error text first
        $wrong = (bool)preg_match('/login\.php\?redirect=/', $response->headers.$response->body);
        // the session is all forum cookies (their names depend on the site settings) except Cloudflare's own
        $pairs = array();
        foreach ($response->cookies as $name => $value)
            if ($value !== '' && $value != 'deleted' && ! preg_match('/^(cf_|__cf)/', $name))
                $pairs[] = $name.'='.$value;
        if ( ! $wrong && ! empty($pairs))
            return implode('; ', $pairs);
        if ($response->body === '' && ($response->status == 0 || $response->status >= 500 || $response->challenged))
            throw new TrackerException('cant_get_auth_page');
        if ($wrong)
            throw new TrackerException('credential_wrong');
        throw new TrackerException($response->body === '' && $response->status != 302 ? 'cant_get_auth_page' : 'cant_find_cookie');
    }

    protected function topicRequest(string $topicId): Request
    {
        return $this->get('/forum/viewtopic.php?t='.rawurlencode($topicId));
    }

    protected function parseDate(string $page): ?array
    {
        // 21 Ноя 2026 10:00:05 (\D{6} - three Russian letters in UTF-8)
        // the cell after the "Зарегистрирован:" label; a solver returns the page with real no-break spaces
        $gap = '(?:&nbsp;|\x{A0}|\s)*';
        if ( ! preg_match('/Зарегистрирован:'.$gap.'<\/td>\s*<td[^>]*>'.$gap.'(\d{2}\s\S+\s\d{4}\s\d{2}:\d{2}:\d{2})/u', $page, $m)
            && ! preg_match('/<td class=\"genmed\">'.$gap.'(\d{2}\s\S+\s\d{4}\s\d{2}:\d{2}:\d{2})'.$gap.'<\/td>/u', $page, $m))
            return NULL;
        if ( ! preg_match('/^(\d{2})\s(\S+)\s(\d{4})\s(\d{2}:\d{2}:\d{2})$/u', $m[1], $d) || self::monthNumber($d[2]) === NULL)
            return NULL;
        return array(sprintf('%s-%s-%s %s', $d[3], self::monthNumber($d[2]), $d[1], $d[4]), $m[1]);
    }

    protected function parseTitle(string $page): ?string
    {
        $title = self::pageTitle($page);
        return $title === NULL ? NULL : preg_replace('/\s*::\s*NNM-Club$/u', '', $title);
    }

    protected function downloadRequest(string $topicId, string $page): ?Request
    {
        if ( ! preg_match('/download\.php\?id=(\d{6,8})/', $page, $link))
            return NULL;
        return $this->downloadById($topicId, $link[1]);
    }

    private function downloadById(string $topicId, string $downloadId): Request
    {
        return $this->get('/forum/download.php?id='.$downloadId)->follow()
            ->referer($this->url('/forum/viewtopic.php?t='.rawurlencode($topicId)));
    }

    // the link to the torrent file is only on the topic page
    public function download(string $topicId): string
    {
        $response = $this->send($this->topicRequest($topicId));
        if ($response->body === '' || $response->challenged)
            throw new TrackerException('cant_get_forum_page');
        if ( ! preg_match('/download\.php\?id=(\d{2,8})/', $response->body, $link))
            throw new TrackerException('cant_find_dowload_link');
        return $this->torrent($this->downloadById($topicId, $link[1]));
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
        $body = 'prev_sd=0&prev_a=0&prev_my=0&prev_n=0&prev_shc=0&prev_shf=1&prev_sha=1&prev_shs=0&prev_shr=0&prev_sht=0'
            .'&f%5B%5D=-1&o=1&s=2&tm=-1&shf=1&sha=1&ta=-1&sns=-1&sds=-1&nm=&pn='
            .rawurlencode(\TorrentMonitor\Text::fromUtf8($user, 'windows-1251')).'&submit=%CF%EE%E8%F1%EA';
        $response = $this->send($this->post('/forum/tracker.php', $body));
        $page = $response->body;
        if ($page === '' || $response->challenged)
            throw new TrackerException('cant_get_forum_page');

        preg_match_all('/<a class=\"gen\" href=\"tracker\.php\?f=\d{1,6}\">(.*)<\/a>/', $page, $section);
        preg_match_all('/<a class=\"(genmed|leechmed|seedmed) (topicpremod|topictitle)\" href=\"viewtopic\.php\?t=(\d{3,9})\"><b>(.*)<\/b><\/a>/', $page, $topic);
        preg_match_all('/<td align=\"center\" nowrap=\"nowrap\" title=\"Добавлено\" class=\"gensmall\"><u>.*<\/u> (\d{2}-\d{2}-\d{4})<br>.*<\/td>/', $page, $dates);

        $result = array();
        if (count($section[1]) != count($topic[3]) || count($topic[3]) != count($dates[1]))
            return $result;
        foreach ($topic[3] as $i => $topicId)
        {
            //21-11-2026
            $d = explode('-', $dates[1][$i]);
            $result[] = array('section' => strip_tags($section[1][$i]), 'topicId' => $topicId, 'title' => strip_tags($topic[4][$i]),
                'date' => $d[2].'-'.$d[1].'-'.$d[0]);
        }
        return $result;
    }
}
