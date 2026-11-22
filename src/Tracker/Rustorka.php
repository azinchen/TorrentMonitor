<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Request;

final class Rustorka extends ForumTracker
{
    const ADDRESSES = array('http://rustorka.com');
    const CHARSET = 'windows-1251';

    protected function sessionAlive(string $cookie): bool
    {
        $response = $this->send($this->get('/forum/index.php', $cookie));
        return (bool)preg_match('/Вы зашли как: &nbsp;/', $response->body);
    }

    protected function signIn(): string
    {
        $body = 'login_username='.$this->encodedLogin().'&login_password='.rawurlencode($this->account['password']).'&login=%C2%F5%EE%E4';
        $response = $this->send($this->post('/forum/login.php', $body, '')->referer($this->url('/forum/index.php')));

        $wrong = (bool)preg_match('/profile\.php\?mode=register/', $response->headers.$response->body);
        $session = $response->cookie('bb_data');
        if ( ! $wrong && $session !== NULL && $session !== '' && $session != 'deleted')
            return 'bb_data='.$session;
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
        if ( ! preg_match('/<td>Зарегистрирован:<\/td>\r\n{1,2}\s{4}<td>(.*)<\/td>/', $page, $m))
            return NULL;
        //21-Ноя-26 10:00 или 21-Ноя-26 10:00:05
        if ( ! preg_match('/^(\d{1,2})-([^-\s]+)-(\d{2})\s+((\d{1,2}):(\d{2})(?::(\d{2}))?)/u', trim($m[1]), $d) || self::monthNumber($d[2]) === NULL)
            return NULL;
        return array(
            sprintf('20%s-%s-%02d %02d:%s:%s', $d[3], self::monthNumber($d[2]), $d[1], $d[5], $d[6], isset($d[7]) ? $d[7] : '00'),
            sprintf('%s %s 20%s %s', $d[1], $d[2], $d[3], $d[4]),
        );
    }

    protected function downloadRequest(string $topicId, string $page): ?Request
    {
        if ( ! preg_match('/<a href=\"download\.php\?id=(.*)\" class=\"(genmed|seedmed)\">/', $page, $link))
            return NULL;
        return $this->post('/forum/download.php?id='.$link[1])
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
}
