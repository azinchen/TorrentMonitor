<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Request;

final class Pornolab extends ForumTracker
{
    const ADDRESSES = array('http://pornolab.net');
    const CHARSET = 'windows-1251';

    protected function sessionAlive(string $cookie): bool
    {
        $response = $this->send($this->get('/forum/index.php', $cookie));
        return (bool)preg_match('/Вы зашли как: &nbsp;/', $response->body);
    }

    protected function signIn(): string
    {
        $body = 'login_username='.$this->encodedLogin().'&login_password='.rawurlencode($this->account['password']).'&login=%C2%F5%EE%E4';
        $response = $this->send($this->post('/forum/login.php', $body, ''));

        $wrong = (bool)preg_match('/Вы ввели неверное\/неактивное имя пользователя или неверный пароль/', $response->body);
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
        if ( ! preg_match('/<span title=\"Зарегистрирован\">\[ (.+) \]<\/span>/', $page, $m))
            return NULL;
        return self::date(trim($m[1]));
    }

    // 21-Ноя-26 10:00 or 21-Ноя-26 10:00:05
    private static function date(string $text): ?array
    {
        if ( ! preg_match('/^(\d{1,2})-([^-\s]+)-(\d{2})\s+((\d{1,2}):(\d{2})(?::(\d{2}))?)/u', $text, $d) || self::monthNumber($d[2]) === NULL)
            return NULL;
        return array(
            sprintf('20%s-%s-%02d %02d:%s:%s', $d[3], self::monthNumber($d[2]), $d[1], $d[5], $d[6], isset($d[7]) ? $d[7] : '00'),
            sprintf('%s %s 20%s %s', $d[1], $d[2], $d[3], $d[4]),
        );
    }

    protected function parseTitle(string $page): ?string
    {
        $title = self::pageTitle($page);
        return $title === NULL ? NULL : preg_replace('/\s*::\s*PornoLab\.Net$/iu', '', $title);
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
        $body = 'prev_my=0&prev_new=0&prev_oop=0&f%5B%5D=-1&o=1&s=2&tm=-1&pn='
            .rawurlencode((string)@iconv('utf-8', 'windows-1251', $user)).'&nm=&submit=%CF%EE%E8%F1%EA';
        $response = $this->send($this->post('/forum/tracker.php', $body));
        $page = $response->body;
        if ($page === '' || $response->challenged)
            throw new TrackerException('cant_get_forum_page');

        preg_match_all('/<a class=\"gen f\" href=\"tracker\.php\?f=\d{1,9}\">(.*)<\/a>/', $page, $section);
        preg_match_all('/<a class=\"med tLink bold\" href=\"\.\/viewtopic.php\?t=(\d{3,9})\">(.*)<\/a>/', $page, $topic);
        preg_match_all('/<td class=\".*\" style=\".*\" title=\"Добавлен\">\n\t\t<u>.*<\/u>\n\t\t<p>.*<\/p>\n\t\t<p>(.*)<\/p>\n\t<\/td>/', $page, $dates);

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
