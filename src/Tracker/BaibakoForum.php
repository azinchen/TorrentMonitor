<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Request;

//Раздачи baibako.tv, отслеживаемые как темы (сериалы из RSS - класс Baibako).
final class BaibakoForum extends ForumTracker
{
    const ADDRESSES = array('http://baibako.tv');
    const CHARSET = 'windows-1251';

    protected function sessionAlive(string $cookie): bool
    {
        $response = $this->send($this->get('/', $cookie));
        return (bool)preg_match('/<a href=\"logout\.php\">Выход<\/a>/U', $response->body);
    }

    protected function signIn(): string
    {
        $body = 'username='.$this->encodedLogin().'&password='.rawurlencode($this->account['password']).'&commit=%CF%F3%F1%F2%E8%F2%E5+%EC%E5%ED%FF';
        $response = $this->send($this->post('/takelogin.php', $body, ''));

        $uid = $response->cookie('uid');
        $pass = $response->cookie('pass');
        if ( ! empty($uid) && ! empty($pass) && $uid != 'deleted' && $pass != 'deleted')
            return 'uid='.$uid.'; pass='.$pass;
        if ($response->body === '' && ($response->status == 0 || $response->status >= 500 || $response->challenged))
            throw new TrackerException('cant_get_auth_page');
        throw new TrackerException($response->body === '' && $response->status != 302 ? 'cant_get_auth_page' : 'cant_find_cookie');
    }

    protected function topicRequest(string $topicId): Request
    {
        return $this->get('/details.php?id='.rawurlencode($topicId));
    }

    protected function parseDate(string $page): ?array
    {
        if ( ! preg_match('/<td align=\"left\" width=\"16\.6\%\"><b>(.*)<\/b><\/td>/', $page, $m))
            return NULL;
        //"5 ноября 2026 в 10:15:30"
        $parts = explode(' ', $m[1]);
        if (count($parts) < 5 || self::monthNumber($parts[1]) === NULL)
            return NULL;
        $day = strlen($parts[0]) == 1 ? '0'.$parts[0] : $parts[0];
        //в тексте для уведомления время без секунд
        $text = preg_split('/\s/', substr($m[1], 0, -3));
        return array(
            $parts[2].'-'.self::monthNumber($parts[1]).'-'.$day.' '.$parts[4],
            $text[0].' '.$text[1].' '.$text[2].' '.(isset($text[4]) ? $text[4] : ''),
        );
    }

    protected function downloadRequest(string $topicId, string $page): ?Request
    {
        return $this->get('/download.php?id='.rawurlencode($topicId))
            ->referer($this->url('/download.php?id='.rawurlencode($topicId)));
    }

    public function topicUrl(string $topicId): string
    {
        return $this->base().'/details.php?id='.$topicId;
    }

    public function topicIdFromUrl(string $url): ?string
    {
        $query = explode('=', (string)parse_url($url, PHP_URL_QUERY));
        return isset($query[1]) && ctype_digit($query[1]) ? $query[1] : NULL;
    }
}
