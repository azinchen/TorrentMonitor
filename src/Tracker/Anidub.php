<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Request;

// tr.anidub.com. The topic id is the path of the release page (/anime_tv/.../1234-name.html).
final class Anidub extends ForumTracker
{
    const ADDRESSES = array('https://tr.anidub.com');

    // torrent file id found on the topic page: it also goes into the saved file name
    private string $downloadId = '';

    protected function sessionAlive(string $cookie): bool
    {
        $response = $this->send($this->get('/', $cookie));
        return (bool)preg_match('/Добро пожаловать <a href=\"https?:\/\/[^\/\"]+\/user\/.*\/\" target=\"_blank\">/', $response->body);
    }

    protected function signIn(): string
    {
        $body = 'login_name='.$this->encodedLogin().'&login_password='.rawurlencode($this->account['password']).'&login=submit';
        $response = $this->send($this->post('/', $body, ''));

        $user = $response->cookie('dle_user_id');
        $password = $response->cookie('dle_password');
        if ( ! empty($user) && ! empty($password) && $user != 'deleted' && $password != 'deleted')
        {
            $cookie = 'dle_user_id='.$user.'; dle_password='.$password;
            $newpm = $response->cookie('dle_newpm');
            if ($newpm !== NULL && $newpm !== '' && $newpm != 'deleted')
                $cookie .= '; dle_newpm='.$newpm;
            return $cookie;
        }
        if ($response->body === '' && ($response->status == 0 || $response->status >= 500 || $response->challenged))
            throw new TrackerException('cant_get_auth_page');
        if (preg_match('/index\.php\?do=register\">Регистрация<\/a>/u', $response->body))
            throw new TrackerException('credential_wrong');
        throw new TrackerException($response->body === '' ? 'cant_get_auth_page' : 'cant_find_cookie');
    }

    protected function topicRequest(string $topicId): Request
    {
        return $this->get(self::path($topicId));
    }

    protected function parseDate(string $page): ?array
    {
        if ( ! preg_match('/<li><b>Дата:<\/b> (.*)<\/li>/', $page, $m) || empty($m[1]))
            return NULL;
        $text = $m[1];
        $pieces = explode(', ', $text);
        if (count($pieces) < 2)
            return NULL;
        //"Сегодня, 10:15", "Вчера, 23:00"
        if (strpos($text, 'Сегодня') !== FALSE || strpos($text, 'Вчера') !== FALSE)
        {
            //days of the tracker's clock, not of the server's
            $today = \TorrentMonitor\Time::at($this->zone());
            return array(($pieces[0] == 'Вчера' ? $today->modify('-1 day') : $today)->format('Y-m-d').' '.$pieces[1].':00', $text);
        }
        //"5-11-2026, 10:15"
        $day = explode('-', $pieces[0]);
        if (count($day) != 3)
            return NULL;
        if (strlen($day[0]) == 1)
            $day[0] = '0'.$day[0];
        return array($day[2].'-'.$day[1].'-'.$day[0].' '.$pieces[1].':00', $text);
    }

    // The site name at the end of the title takes 114 bytes: what exactly it says is unknown, so cut by length.
    protected function parseTitle(string $page): ?string
    {
        if ( ! preg_match('/<title>(.*)<\/title>/sU', $page, $m) || strlen($m[1]) <= 114)
            return NULL;
        $title = trim(html_entity_decode(substr($m[1], 0, -114), ENT_QUOTES, 'UTF-8'));
        // cutting by bytes may have split a letter
        $title = \TorrentMonitor\Text::cleanUtf8($title);
        return $title === '' ? NULL : $title;
    }

    protected function downloadRequest(string $topicId, string $page): ?Request
    {
        if ( ! preg_match('/download\.php\?id=(\d*)/', $page, $m) || $m[1] === '')
            return NULL;
        $this->downloadId = $m[1];
        return $this->get('/engine/download.php?id='.$m[1])->referer($this->url(self::path($topicId)));
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
