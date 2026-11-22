<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Request;
use TorrentMonitor\Http\Response;

//Лента раздач lostfilm.tv на стороннем сервере: учётная запись не нужна.
final class LostfilmMirror extends SeriesTracker
{
    const ADDRESSES = array('https://rss.bzda.ru');
    const NEEDS_ACCOUNT = FALSE;
    //резервный адрес ленты, используется, если основной источник недоступен
    const MIRROR_URL = 'https://lf.tormon.ru/rss.xml';

    //качество темы => array(шаблон ссылки на раздачу, обозначение в имени файла)
    const QUALITY = array(
        'sd'   => array('avi|AVI', 'SD'),
        '1080' => array('mkv|MKV', 'HD'),
        '720'  => array('mp4|MP4', 'MP4'),
    );

    //лента с резервного адреса (загружается один раз за запуск)
    private ?Response $reserve = NULL;

    public function request(array $topic): ?Request
    {
        return $this->get('/rss.xml', '');
    }

    public function check(array $topic, Response $response): array
    {
        if ($response->body === '' || $response->challenged)
        {
            if ($this->reserve === NULL)
                $this->reserve = $this->send(Request::get(self::MIRROR_URL)->tracker($this->name));
            $response = $this->reserve;
        }
        $xml = $this->feed($response);
        $quality = isset(self::QUALITY[$topic['quality']]) ? self::QUALITY[$topic['quality']] : self::QUALITY['sd'];
        //в названиях раздач слова разделены точками; точка в шаблоне - любой символ
        $name = str_replace(array(' ', '\.'), '.', preg_quote($topic['name'], '/'));
        $have = $topic['episode'];
        $found = array();

        foreach (self::items($xml) as $item)
        {
            $link = (string)$item->link;
            if ( ! preg_match('/'.$name.'/i', (string)$item->title) || ! preg_match('/'.$quality[0].'/', $link)
                || ! preg_match('/s(\d{2})\.?e(\d{2})/i', $link, $se))
                continue;
            $season = (int)$se[1];
            $episode = (int)$se[2];
            if ( ! self::newer($have, $season, $episode))
                continue;

            try
            {
                $torrent = $this->torrent(Request::get($link)->tracker($this->name));
            }
            catch (TrackerException $e)
            {
                //уже найденные серии не теряем: ошибка повторится при следующем запуске
                if (empty($found))
                    throw $e;
                break;
            }
            list($date, $dateText) = self::date((string)$item->pubDate);
            $file = str_replace(' ', '.', $topic['name']).'.S'.$se[1].'E'.$se[2].'.'.$quality[1];
            $release = new Found($torrent, $date, $dateText, $file, self::message($topic['name'], $quality[1], $season, $episode));
            $release->episode = 'S'.$se[1].'E'.$se[2];
            $found[] = $release;
            $have = $release->episode;
        }
        return $found;
    }

    //дата из ленты как есть, без пересчёта часового пояса: array('Y-m-d H:i:s', 'd Mon Y H:i:s')
    private static function date(string $pubDate): array
    {
        if ( ! preg_match('/(\d{1,2})\s+(\w{3})\s+(\d{4})\s+(\d{2}:\d{2}:\d{2})/', $pubDate, $m) || self::monthNumber($m[2]) === NULL)
            return self::feedDate($pubDate);
        return array($m[3].'-'.self::monthNumber($m[2]).'-'.sprintf('%02d', $m[1]).' '.$m[4], $m[1].' '.$m[2].' '.$m[3].' '.$m[4]);
    }
}
