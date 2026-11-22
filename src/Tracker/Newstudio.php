<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Cookies;
use TorrentMonitor\Http\Request;
use TorrentMonitor\Http\Response;

final class Newstudio extends SeriesTracker
{
    const ADDRESSES = array('https://newstudio.tv');

    //качество темы => array(шаблон названия раздачи, обозначение в имени файла)
    const QUALITY = array(
        'sd'   => array('WEBDLRip', ''),
        '720'  => array('720p', 'HD'),
        '1080' => array('1080p', 'HD'),
    );

    public function validName(string $name): bool
    {
        return (bool)preg_match('/^[\.\+\s\'\`\:\;\-a-zA-Z0-9]+$/', $name);
    }

    protected function sessionAlive(string $cookie): bool
    {
        $response = $this->send($this->post('/', '', $cookie)->follow());
        return (bool)preg_match('/login\.php\?logout=1/', $response->body);
    }

    protected function signIn(): string
    {
        $body = 'login_username='.$this->encodedLogin().'&login_password='.rawurlencode($this->account['password']).'&autologin=1&login=1';
        $response = $this->send($this->post('/login.php', $body, '')->follow());

        $cookie = $this->sessionCookie($response);
        if ($cookie !== NULL)
            return $cookie;
        if ($response->body === '')
            throw new TrackerException('cant_get_auth_page');
        if (preg_match('/profile\.php\?mode=sendpassword/', $response->body))
            throw new TrackerException('credential_wrong');
        throw new TrackerException('cant_find_cookie');
    }

    //cookie сессии из ответа: bb_data, а если её нет - первая установленная сайтом
    private function sessionCookie(Response $response): ?string
    {
        if ( ! empty($response->cookie('bb_data')) && $response->cookie('bb_data') != 'deleted')
            return 'bb_data='.$response->cookie('bb_data');
        foreach ($response->cookies as $name => $value)
            if ($value !== '' && $value != 'deleted' && ! Cookies::isCloudflare((string)$name))
                return $name.'='.$value;
        return NULL;
    }

    public function request(array $topic): ?Request
    {
        return $this->get('/rss.php', '');
    }

    public function check(array $topic, Response $response): array
    {
        $xml = $this->feed($response);
        $quality = isset(self::QUALITY[$topic['quality']]) ? self::QUALITY[$topic['quality']] : self::QUALITY['sd'];
        $have = $topic['episode'];
        $found = array();

        foreach (self::items($xml) as $item)
        {
            $title = (string)$item->title;
            if ( ! preg_match('/'.preg_quote($topic['name'], '/').'/i', $title) || ! preg_match('/'.$quality[0].'/', $title)
                || ! preg_match('/Сезон (\d{1,2}), Серия (\d{1,2})/', $title, $se))
                continue;
            $season = (int)$se[1];
            $episode = (int)$se[2];
            if ( ! self::newer($have, $season, $episode))
                continue;

            try
            {
                $torrent = $this->release((string)$item->link);
            }
            catch (TrackerException $e)
            {
                //уже найденные серии не теряем: ошибка повторится при следующем запуске
                if (empty($found))
                    throw $e;
                break;
            }
            list($date, $dateText) = self::date((string)$item->pubDate);
            $code = sprintf('S%02dE%02d', $season, $episode);
            $file = str_replace(' ', '.', $topic['name']).'.'.$code.'.'.$quality[1];
            $release = new Found($torrent, $date, $dateText, $file, self::message($topic['name'], $quality[1], $season, $episode));
            $release->episode = $code;
            $found[] = $release;
            $have = $code;
        }
        return $found;
    }

    //torrent-файл раздачи: его номер указан на странице темы
    private function release(string $link): string
    {
        $page = $this->send($this->post($link)->follow());
        if ( ! preg_match('/download\.php\?id=(\d{2,6})/', $page->body, $id))
            throw new TrackerException('torrent_file_fail');
        return $this->torrent($this->post('/download.php?id='.$id[1])->follow());
    }

    //дата из ленты как есть, без пересчёта часового пояса: array('Y-m-d H:i:s', 'd Мес Y H:i')
    private static function date(string $pubDate): array
    {
        if ( ! preg_match('/(\d{1,2})\s+(\w{3})\s+(\d{4})\s+(\d{2}:\d{2}):(\d{2})/', $pubDate, $m) || self::monthNumber($m[2]) === NULL)
            return self::feedDate($pubDate);
        $month = self::monthNumber($m[2]);
        $day = sprintf('%02d', $m[1]);
        return array($m[3].'-'.$month.'-'.$day.' '.$m[4].':'.$m[5], $day.' '.self::monthName($month).' '.$m[3].' '.$m[4]);
    }
}
