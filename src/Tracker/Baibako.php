<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Cookies;
use TorrentMonitor\Http\Request;
use TorrentMonitor\Http\Response;

final class Baibako extends SeriesTracker
{
    const ADDRESSES = array('http://baibako.tv');
    const CHARSET = 'windows-1251';

    //качество темы => array(шаблон названия раздачи, обозначение в имени файла)
    const QUALITY = array(
        'sd'   => array('x264', ''),
        '720'  => array('HD(TV)?720', 'HD'),
        '1080' => array('HD(TV)?1080', 'HD'),
    );

    protected function sessionAlive(string $cookie): bool
    {
        $response = $this->send($this->post('/', '', $cookie));
        return (bool)preg_match('/<a href=\"logout\.php\">Выход<\/a>/u', $response->body);
    }

    protected function signIn(): string
    {
        //commit - "Пустите меня" в windows-1251
        $body = 'username='.$this->encodedLogin().'&password='.rawurlencode($this->account['password']).'&commit=%CF%F3%F1%F2%E8%F2%E5+%EC%E5%ED%FF';
        $response = $this->send($this->post('/takelogin.php', $body, ''));

        $uid = $response->cookie('uid');
        $pass = $response->cookie('pass');
        if ( ! empty($uid) && ! empty($pass) && $uid != 'deleted')
            return 'uid='.$uid.'; pass='.$pass;
        //других признаков успешного входа нет: подходят любые cookie, установленные сайтом
        $pairs = array();
        foreach ($response->cookies as $name => $value)
            if ($value !== '' && $value != 'deleted' && ! Cookies::isCloudflare((string)$name))
                $pairs[] = $name.'='.$value;
        if ( ! empty($pairs))
            return implode('; ', $pairs);
        if ($response->body === '')
            throw new TrackerException('cant_get_auth_page');
        throw new TrackerException('cant_find_cookie');
    }

    public function request(array $topic): ?Request
    {
        return $this->get('/rss2.php?feed=dl&passkey='.rawurlencode($this->account['passkey']));
    }

    public function check(array $topic, Response $response): array
    {
        $xml = $this->feed(self::fixed($response));
        $quality = isset(self::QUALITY[$topic['quality']]) ? self::QUALITY[$topic['quality']] : self::QUALITY['sd'];
        $have = $topic['episode'];
        $found = array();

        foreach (self::items($xml) as $item)
        {
            $title = (string)$item->title;
            $link = (string)$item->link;
            if ( ! preg_match('/'.preg_quote($topic['name'], '/').'/i', $title) || ! preg_match('/'.$quality[0].'/', $title))
                continue;
            //серия указана в ссылке; у раздачи нескольких серий (s01e01-11 в названии) берётся последняя
            if ( ! preg_match('/s(\d{2})\.?e(\d{2})/i', $link, $se) && ! preg_match('/s(\d{2})\.?e\d{2}-(\d{2})/i', $title, $se))
                continue;
            $season = (int)$se[1];
            $episode = (int)$se[2];
            if ( ! self::newer($have, $season, $episode))
                continue;

            try
            {
                $torrent = $this->torrent($this->get($link));
            }
            catch (TrackerException $e)
            {
                //уже найденные серии не теряем: ошибка повторится при следующем запуске
                if (empty($found))
                    throw $e;
                break;
            }
            list($date, $dateText) = self::feedDate((string)$item->pubDate);
            $file = str_replace(' ', '.', $topic['name']).'.S'.$se[1].'E'.$se[2].'.'.$quality[1];
            $release = new Found($torrent, $date, $dateText, $file, self::message($topic['name'], $quality[1], $season, $episode));
            $release->episode = 'S'.$se[1].'E'.$se[2];
            $found[] = $release;
            $have = $release->episode;
        }
        return $found;
    }

    //Лента в виде, пригодном для разбора: тело уже в UTF-8, а в заголовке XML указана windows-1251;
    //амперсанды в ссылках не экранированы.
    private static function fixed(Response $response): Response
    {
        if ($response->body === '')
            return $response;
        $fixed = clone $response;
        $body = (string)preg_replace('/^(\s*<\?xml[^>]*encoding=["\'])[^"\']*/i', '$1utf-8', $response->body);
        $fixed->body = str_replace('&', '&amp;', str_replace('&amp;', '&', $body));
        return $fixed;
    }
}
