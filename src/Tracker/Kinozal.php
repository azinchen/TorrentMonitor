<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Request;

// kinozal.tv and its mirrors kinozal.me, kinozal.guru: one site under different addresses.
final class Kinozal extends ForumTracker
{
    const CHARSET = 'windows-1251';
    const NEWER_ONLY = TRUE;

    protected function defaultAddresses(): array
    {
        return array('https://'.$this->name);
    }

    protected function sessionAlive(string $cookie): bool
    {
        $response = $this->send($this->get('/', $cookie));
        return (bool)preg_match('/<a href=\'\/userdetails\.php\?id=\d*\'>.*<\/a>/U', $response->body);
    }

    protected function signIn(): string
    {
        $body = 'username='.$this->encodedLogin().'&password='.rawurlencode($this->account['password']).'&returnto=';
        $response = $this->send($this->post('/takelogin.php', $body, ''));

        $uid = $response->cookie('uid');
        $pass = $response->cookie('pass');
        if ( ! empty($uid) && ! empty($pass) && $uid != 'deleted')
            return 'uid='.$uid.'; pass='.$pass;
        if ($response->body === '' && ($response->status == 0 || $response->status >= 500 || $response->challenged))
            throw new TrackerException('cant_get_auth_page');
        if (preg_match('/Не верно указан пароль/u', $response->body))
            throw new TrackerException('credential_wrong');
        if (preg_match('/Превышен лимит попыток входа/u', $response->body))
            throw new TrackerException('limit');
        throw new TrackerException($response->body === '' && $response->status != 302 ? 'cant_get_auth_page' : 'cant_find_cookie');
    }

    protected function topicRequest(string $topicId): Request
    {
        return $this->get('/details.php?id='.rawurlencode($topicId));
    }

    protected function parseDate(string $page): ?array
    {
        if ( ! preg_match('/<li>Обновлен<span class="floatright green n">(.*)<\/span><\/li>/U', $page, $m)
            && ! preg_match('/<li>Залит<span class="floatright green n">(.*)<\/span><\/li>/U', $page, $m))
            return NULL;
        return self::date(trim($m[1]));
    }

    //"сегодня в 10:15", "вчера в 23:00", "сейчас", "5 ноября 2026 в 10:15"
    public static function date(string $text, ?int $now = NULL): ?array
    {
        $now = $now === NULL ? time() : $now;
        if (strpos($text, 'сейчас') !== FALSE)
            $time = $now - $now % 60;
        elseif (preg_match('/^(сегодня|вчера)\s+в\s+(\d{1,2}):(\d{2})/u', $text, $m))
            $time = strtotime(date('Y-m-d', $m[1] == 'вчера' ? $now - 86400 : $now).sprintf(' %02d:%s:00', $m[2], $m[3]));
        elseif (preg_match('/^(\d{1,2})\s+(\S+)\s+(\d{4})\s+в\s+(\d{1,2}):(\d{2})/u', $text, $m) && self::monthNumber($m[2]) !== NULL)
            return array(sprintf('%s-%s-%02d %02d:%s:00', $m[3], self::monthNumber($m[2]), $m[1], $m[4], $m[5]), $text);
        else
            return NULL;
        return array(date('Y-m-d H:i:s', $time), date('d', $time).' '.self::monthName(date('n', $time)).' '.date('Y', $time).' в '.date('H:i', $time));
    }

    protected function parseTitle(string $page): ?string
    {
        $title = self::pageTitle($page);
        return $title === NULL ? NULL : preg_replace('~\s*(/|::)\s*Кинозал\.\S+$~u', '', $title);
    }

    protected function downloadRequest(string $topicId, string $page): ?Request
    {
        return $this->get('/download.php?id='.rawurlencode($topicId))->follow()
            ->referer($this->url('/details.php?id='.rawurlencode($topicId)));
    }

    protected function torrent(Request $request): string
    {
        if ($this->probe)
            return '';
        $response = $this->send($request->binary()->charset(NULL));
        if (strpos($response->body, 'pay_mode.php#tcounter') !== FALSE)
            throw new TrackerException('max_torrent');
        if ( ! self::isTorrent($response->body))
            throw new TrackerException('torrent_file_fail');
        return $response->body;
    }

    public function topicUrl(string $topicId): string
    {
        return $this->base().'/details.php?id='.$topicId;
    }

    public function topicIdFromUrl(string $url): ?string
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        return isset($query['id']) && ctype_digit((string)$query['id']) ? (string)$query['id'] : NULL;
    }
}
