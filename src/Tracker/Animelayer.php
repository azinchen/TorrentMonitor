<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Request;
use TorrentMonitor\Http\Response;

final class Animelayer extends ForumTracker
{
    const ADDRESSES = array('https://animelayer.ru');
    const CHARSET = 'windows-1251';

    protected function sessionAlive(string $cookie): bool
    {
        $response = $this->send($this->get('/', $cookie));
        return (bool)preg_match('/<span class=\"iblock vtop pd10 username\">.*<\/span>/U', $response->body);
    }

    protected function signIn(): string
    {
        $body = 'login='.$this->encodedLogin().'&password='.rawurlencode($this->account['password']);
        $response = $this->send($this->post('/auth/login/', $body, ''));

        $id = $response->cookie('layer_id');
        $hash = $response->cookie('layer_hash');
        if ( ! empty($id) && ! empty($hash) && $id != 'deleted' && $hash != 'deleted')
            return self::cookies($response);
        if ($response->body === '' && ($response->status == 0 || $response->status >= 500 || $response->challenged))
            throw new TrackerException('cant_get_auth_page');
        if (preg_match('/Имя пользователя или пароль неверны/u', $response->body))
            throw new TrackerException('credential_wrong');
        // the session is all the cookies the site issued at login
        $cookie = self::cookies($response);
        if ($cookie !== '')
            return $cookie;
        throw new TrackerException($response->body === '' && $response->status != 302 ? 'cant_get_auth_page' : 'cant_find_cookie');
    }

    private static function cookies(Response $response): string
    {
        $pairs = array();
        foreach ($response->cookies as $name => $value)
            if ($value !== '' && $value != 'deleted')
                $pairs[] = $name.'='.$value;
        return implode('; ', $pairs);
    }

    protected function topicRequest(string $topicId): Request
    {
        return $this->get('/torrent/'.rawurlencode($topicId).'/');
    }

    protected function parseDate(string $page): ?array
    {
        if ( ! preg_match('/<span class=\"(date-updated|date-created)\">(.*)<\/span>/U', $page, $m))
            return NULL;
        // "5 ноября в 9:15" (current year) or "5 ноября 2025 в 9:15"
        $text = str_replace('&nbsp;', ' ', $m[2]);
        $pieces = explode(' ', $text);
        if (count($pieces) != 4 && count($pieces) != 5)
            return NULL;
        $month = self::monthNumber($pieces[1]);
        if ($month === NULL || ! preg_match('/(\d{1,2}):(\d{2})/', $text, $time))
            return NULL;
        $year = count($pieces) == 4 ? \TorrentMonitor\Time::at($this->zone())->format('Y') : $pieces[2];
        $day = strlen($pieces[0]) == 1 ? '0'.$pieces[0] : $pieces[0];
        $hour = strlen($time[1]) == 1 ? '0'.$time[1] : $time[1];
        return array(
            $year.'-'.$month.'-'.$day.' '.$hour.':'.$time[2].':00',
            $day.' '.self::monthName($month).' '.$year.' в '.$hour.':'.$time[2],
        );
    }

    protected function parseTitle(string $page): ?string
    {
        $title = self::pageTitle($page);
        if ($title === NULL)
            return NULL;
        $name = preg_replace('/\s+(\||::|—|–|-|»|•)\s+Anime\s?Layer(\.ru)?$/iu', '', $title);
        if ($name === NULL || $name === $title)
        {
            // the title ending is not recognized: as before, the site name is the last 15 bytes
            if ( ! preg_match('/<title>(.*)<\/title>/sU', $page, $m) || strlen($m[1]) <= 15)
                return NULL;
            $name = trim(html_entity_decode(substr($m[1], 0, -15), ENT_QUOTES, 'UTF-8'));
            $name = \TorrentMonitor\Text::cleanUtf8($name);
        }
        return $name === '' ? NULL : $name;
    }

    protected function downloadRequest(string $topicId, string $page): ?Request
    {
        return $this->get('/torrent/'.rawurlencode($topicId).'/download/')
            ->referer($this->url('/torrent/'.rawurlencode($topicId)));
    }

    public function topicUrl(string $topicId): string
    {
        return $this->base().'/torrent/'.$topicId;
    }

    public function topicIdFromUrl(string $url): ?string
    {
        $path = str_replace('/torrent', '', (string)parse_url($url, PHP_URL_PATH));
        return preg_match('/\/(\w*)\/?/', $path, $m) && $m[1] !== '' ? $m[1] : NULL;
    }
}
