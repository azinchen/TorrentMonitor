<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Request;
use TorrentMonitor\Http\Response;

//riperam.org. Номер темы - путь страницы темы (начинается с /).
final class Riperam extends ForumTracker
{
    const ADDRESSES = array('http://riperam.org');

    //номер torrent-файла, найденный на странице темы: он же идёт в имя сохраняемого файла
    private string $downloadId = '';

    protected function sessionAlive(string $cookie): bool
    {
        $response = $this->send($this->get('/', $cookie));
        return (bool)preg_match('/ucp\.php\?mode=logout/', $response->body);
    }

    protected function signIn(): string
    {
        $body = 'username='.$this->encodedLogin().'&password='.rawurlencode($this->account['password']).'&login=%C2%F5%EE%E4';
        $response = $this->send($this->post('/ucp.php?mode=login', $body, ''));

        //cookie phpBB: <префикс>_u (номер пользователя, 1 - гость), <префикс>_k, <префикс>_sid
        $pairs = array();
        $user = NULL;
        foreach ($response->cookies as $name => $value)
        {
            if ($value === '' || $value == 'deleted')
                continue;
            $pairs[] = $name.'='.$value;
            if (substr($name, -2) == '_u')
                $user = $value;
        }
        if ($user !== NULL && ctype_digit($user) && (int)$user > 1)
            return implode('; ', $pairs);
        if ($response->body === '' && ($response->status == 0 || $response->status >= 500 || $response->challenged))
            throw new TrackerException('cant_get_auth_page');
        if (preg_match('/login\.php\?redirect=/', $response->body))
            throw new TrackerException('credential_wrong');
        //cookie названы иначе: как и раньше, сессией считается всё, что выдал сайт
        if ($user === NULL && ! empty($pairs))
            return implode('; ', $pairs);
        throw new TrackerException($response->body === '' && $response->status != 302 ? 'cant_get_auth_page' : 'cant_find_cookie');
    }

    protected function topicRequest(string $topicId): Request
    {
        return $this->get(self::path($topicId));
    }

    protected function parseDate(string $page): ?array
    {
        //[ 21 ноя 2026, 10:15 ]; месяц - три русские буквы, 6 байт (выражение без /u)
        if ( ! preg_match('/\[ (\d{2} \D{6} \d{4}\, \d{2}\:\d{2}) \]/', $page, $m))
            return NULL;
        $parts = explode(', ', $m[1]);
        $day = explode(' ', $parts[0]);
        $month = self::monthNumber($day[1]);
        if ($month === NULL)
            return NULL;
        return array($day[2].'-'.$month.'-'.$day[0].' '.$parts[1].':00', $parts[0].' в '.$parts[1]);
    }

    protected function parseTitle(string $page): ?string
    {
        $title = self::pageTitle($page);
        if ($title === NULL || ! preg_match('/^(.*) • Riper\.AM/su', $title, $m))
            return NULL;
        return trim($m[1]);
    }

    private static function downloadId(string $page): ?string
    {
        return preg_match('/download\/file\.php\?id=(\d{6,8})/', $page, $m) ? $m[1] : NULL;
    }

    public function check(array $topic, Response $response): array
    {
        //как и раньше: если на странице нет ссылки на файл (например, сессия не действует), это ошибка, даже когда дата не изменилась
        if ($response->body !== '' && ! $response->challenged && $this->parseDate($response->body) !== NULL
            && self::downloadId($response->body) === NULL)
            throw new TrackerException('cant_find_dowload_link');
        return parent::check($topic, $response);
    }

    protected function downloadRequest(string $topicId, string $page): ?Request
    {
        $id = self::downloadId($page);
        if ($id === NULL)
            return NULL;
        $this->downloadId = $id;
        return $this->get('/download/file.php?id='.$id)->follow()->referer($this->url(self::path($topicId)));
    }

    protected function fileId(string $topicId): string
    {
        return $this->downloadId !== '' ? $this->downloadId : $topicId;
    }

    //ссылка на torrent-файл есть только на странице темы
    public function download(string $topicId): string
    {
        $response = $this->send($this->topicRequest($topicId));
        if ($response->body === '' || $response->challenged)
            throw new TrackerException('cant_get_forum_page');
        return $this->fetchTorrent($topicId, $response->body);
    }

    public function topicUrl(string $topicId): string
    {
        return $this->base().self::path($topicId);
    }

    public function topicIdFromUrl(string $url): ?string
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        return $path === '' || preg_match('/\D\d\/+/', $path) ? NULL : $path;
    }

    private static function path(string $topicId): string
    {
        return '/'.ltrim($topicId, '/');
    }
}
