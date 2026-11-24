<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Request;

final class Booktracker extends ForumTracker
{
    const ADDRESSES = array('https://booktracker.org');

    protected function sessionAlive(string $cookie): bool
    {
        $response = $this->send($this->get('/index.php', $cookie));
        return (bool)preg_match('/login\.php\?logout=true/', $response->body);
    }

    protected function signIn(): string
    {
        $body = 'login_username='.$this->encodedLogin().'&login_password='.rawurlencode($this->account['password']).'&login=%D0%92%D1%85%D0%BE%D0%B4';
        $response = $this->send($this->post('/login.php', $body, ''));

        // with a wrong password the site may set a cookie too, so check the error text first
        $wrong = (bool)preg_match('/profile\.php\?mode=register/', $response->headers.$response->body);
        // the session is the bb_data cookie; if the site renamed it, the first cookie of the response
        // (except Cloudflare's own and the ones the site resets with "deleted")
        $session = '';
        foreach ($response->cookies as $name => $value)
        {
            if ($value === '' || $value == 'deleted' || preg_match('/^(cf_|__cf)/', $name))
                continue;
            if ($session === '' || $name == 'bb_data')
                $session = $name.'='.$value;
        }
        if ( ! $wrong && $session !== '')
            return $session;
        if ($response->body === '' && ($response->status == 0 || $response->status >= 500 || $response->challenged))
            throw new TrackerException('cant_get_auth_page');
        if ($wrong)
            throw new TrackerException('credential_wrong');
        throw new TrackerException($response->body === '' && $response->status != 302 ? 'cant_get_auth_page' : 'cant_find_cookie');
    }

    protected function topicRequest(string $topicId): Request
    {
        return $this->get('/viewtopic.php?t='.rawurlencode($topicId));
    }

    protected function parseDate(string $page): ?array
    {
        if ( ! preg_match('/Зарегистрирован(?:&nbsp;|\x{A0}|\s)*\[\s*<span[^>]*>([^<]+)<\/span>/u', $page, $m))
            return NULL;
        //2026-11-21 10:00
        $time = \TorrentMonitor\Time::read($m[1], $this->zone());
        if ($time === NULL)
            return NULL;
        return array($time->format('Y-m-d H:i:s'), $time->format('Y').' '.self::monthName($time->format('n')).' '.$time->format('d H:i:s'));
    }

    protected function parseTitle(string $page): ?string
    {
        $title = self::pageTitle($page);
        if ($title === NULL)
            return NULL;
        $name = preg_replace('/\s*::\s*Книжный трекер$/u', '', $title, -1, $count);
        if ($count > 0)
            return $name;
        // a different ending: as before, cut off 31 bytes
        if (preg_match('/<title>(.*)<\/title>/', $page, $m) && strlen($m[1]) > 31)
        {
            $name = trim(html_entity_decode(substr($m[1], 0, -31), ENT_QUOTES, 'UTF-8'));
            if ($name !== '' && mb_check_encoding($name, 'UTF-8'))
                return $name;
        }
        return $title;
    }

    protected function downloadRequest(string $topicId, string $page): ?Request
    {
        if ( ! preg_match('/href=\"download.php\?id=(\d+)\"/', $page, $link))
            return NULL;
        return $this->get('/download.php?id='.$link[1])->referer($this->url('/viewtopic.php?t='.rawurlencode($topicId)));
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
        return $this->base().'/viewtopic.php?t='.$topicId;
    }

    public function topicIdFromUrl(string $url): ?string
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        return isset($query['t']) && ctype_digit((string)$query['t']) ? (string)$query['t'] : NULL;
    }

    public function userTopics(string $user): ?array
    {
        $body = 'nm=&to=1&allw=1&pn='.rawurlencode($user).'&f%5B%5D=0&tm=0&dm=0&s=0&o=1&submit=%C2%A0%C2%A0%D0%9F%D0%BE%D0%B8%D1%81%D0%BA%C2%A0%C2%A0';
        $response = $this->send($this->post('/search.php', $body));
        $page = $response->body;
        if ($page === '' || $response->challenged)
            throw new TrackerException('cant_get_forum_page');

        preg_match_all('/<a href=\"\.\/viewforum\.php\?f=\d{2,6}\" class=\"gen\">(.*)<\/a>/', $page, $section);
        preg_match_all('/<a href=\"\.\/viewtopic\.php\?t=(.*)\" class=\"topictitle\"><span id=\"tid_.*\">(.*)<\/span><\/a>/', $page, $topic);
        preg_match_all('/<td class=\".*\" style=\".*\">\n\t\t<p>(.*)<\/p>/', $page, $dates);

        $result = array();
        if (count($section[1]) != count($topic[1]) || count($topic[1]) != count($dates[1]))
            return $result;
        foreach ($topic[1] as $i => $topicId)
        {
            //2026-11-21 10:00
            $d = preg_split('/\s/', trim($dates[1][$i]));
            $result[] = array('section' => strip_tags($section[1][$i]), 'topicId' => $topicId, 'title' => strip_tags($topic[2][$i]),
                'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d[0]) ? $d[0] : '');
        }
        return $result;
    }
}
