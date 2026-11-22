<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Request;
use TorrentMonitor\Http\Response;

// riperam.org. The topic id is the path of the topic page (starts with /).
final class Riperam extends ForumTracker
{
    const ADDRESSES = array('http://riperam.org');

    // torrent file id found on the topic page: it also goes into the saved file name
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

        // phpBB cookies: <prefix>_u (user id, 1 - guest), <prefix>_k, <prefix>_sid
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
        // the cookies are named differently: as before, the session is everything the site issued
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
        // [ 21 ноя 2026, 10:15 ]; the month is three Russian letters, 6 bytes (the expression has no /u)
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
        // as before: if the page has no link to the file (e.g. the session is not valid), it is an error even when the date has not changed
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
