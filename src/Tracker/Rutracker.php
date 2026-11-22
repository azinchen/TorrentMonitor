<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Request;

final class Rutracker extends ForumTracker
{
    const ADDRESSES = array('https://rutracker.org');
    const CHARSET = 'windows-1251';

    protected function sessionAlive(string $cookie): bool
    {
        $response = $this->send($this->get('/forum/index.php', $cookie));
        return (bool)preg_match('/profile\.php\?mode=viewprofile/', $response->body);
    }

    protected function signIn(): string
    {
        $body = 'login_username='.$this->encodedLogin().'&login_password='.rawurlencode($this->account['password']).'&login=%C2%F5%EE%E4';
        $response = $this->send($this->post('/forum/login.php', $body, ''));

        $session = $response->cookie('bb_session');
        if ($session !== NULL && $session !== '' && $session != 'deleted')
            return 'bb_session='.$session;
        if ($response->body === '' && ($response->status == 0 || $response->status >= 500 || $response->challenged))
            throw new TrackerException('cant_get_auth_page');
        if (preg_match('/cap_sid|captcha/i', $response->body))
            throw new TrackerException('captcha');
        if (preg_match('/profile\.php\?mode=register/', $response->body))
            throw new TrackerException('credential_wrong');
        throw new TrackerException($response->body === '' ? 'cant_get_auth_page' : 'cant_find_cookie');
    }

    protected function topicRequest(string $topicId): Request
    {
        return $this->get('/forum/viewtopic.php?t='.rawurlencode($topicId));
    }

    protected function parseDate(string $page): ?array
    {
        if ( ! preg_match('/<td style=\"width: 70%; padding: 5px;\">\s*<ul class=\"inlined middot-separated\">\s*<li>(.*)<\/li>/U', $page, $m))
            return NULL;
        //21-Ноя-26 10:00
        if ( ! preg_match('/(\d{1,2})-(\S+)-(\d{2})\s+(\d{1,2}):(\d{2})/u', $m[1], $d) || self::monthNumber($d[2]) === NULL)
            return NULL;
        return array(
            sprintf('20%s-%s-%02d %02d:%s:00', $d[3], self::monthNumber($d[2]), $d[1], $d[4], $d[5]),
            sprintf('%s %s 20%s %s:%s', $d[1], $d[2], $d[3], $d[4], $d[5]),
        );
    }

    protected function parseTitle(string $page): ?string
    {
        $title = self::pageTitle($page);
        return $title === NULL ? NULL : preg_replace('/\s*::\s*RuTracker\.org$/u', '', $title);
    }

    protected function downloadRequest(string $topicId, string $page): ?Request
    {
        return $this->post('/forum/dl.php?t='.rawurlencode($topicId), '', $this->session.'; bb_dl='.$topicId)
            ->referer($this->url('/forum/viewtopic.php?t='.rawurlencode($topicId)));
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
        $body = 'prev_new=0&prev_oop=0&f%5B%5D=-1&o=1&s=2&tm=-1&pn='.rawurlencode((string)@iconv('utf-8', 'windows-1251', $user)).'&nm=';
        $response = $this->send($this->post('/forum/tracker.php', $body));
        $page = str_replace("\t", '', $response->body);
        if ($page === '' || $response->challenged)
            throw new TrackerException('cant_get_forum_page');

        preg_match_all('/<a class=\"gen f ts-text\" href=\"[^\"]*tracker\.php\?f=\d{1,9}[^\"]*\">(.*)<\/a>/', $page, $section);
        preg_match_all('/<a data-topic_id=\"\d{3,9}\" class=\"med tLink tt-text ts-text hl-tags bold[^\"]*\" href=\"viewtopic\.php\?t=(\d{3,9})\">(.*)<\/a>/', $page, $topic);
        preg_match_all('/<td class=\"row4 small nowrap\" style=\".*\" data-ts_text=\".*\">\n<p>(.*)<\/p>\n(<p>(.*)<\/p>)?\s?<\/td>/', $page, $dates);

        $result = array();
        if (count($section[1]) != count($topic[1]) || count($topic[1]) != count($dates[1]))
            return $result;
        foreach ($topic[1] as $i => $topicId)
        {
            //21-Ноя-26
            $d = explode('-', $dates[1][$i]);
            $date = count($d) == 3 ? sprintf('20%s-%s-%02d', $d[2], self::monthNumber($d[1]), $d[0]) : '';
            $result[] = array('section' => strip_tags($section[1][$i]), 'topicId' => $topicId, 'title' => strip_tags($topic[2][$i]), 'date' => $date);
        }
        return $result;
    }
}
