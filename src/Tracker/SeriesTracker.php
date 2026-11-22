<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Response;

//Трекер, где отслеживается сериал: новые серии ищутся по названию в RSS-ленте трекера.
//У темы нет номера: её определяют название и качество (sd, 720, 1080).
abstract class SeriesTracker extends Tracker
{
    /** @var array<string,\SimpleXMLElement|string> разобранные ленты (или код ошибки) по содержимому */
    private array $feeds = array();

    //Разбирает RSS-ленту (один раз на все сериалы трекера).
    //TrackerException: cant_find_rss - лента не получена, rss_parse_false - в ней ошибка.
    protected function feed(Response $response, bool $escapeAmpersands = FALSE): \SimpleXMLElement
    {
        $key = md5($response->body);
        if ( ! isset($this->feeds[$key]))
        {
            if ($response->challenged)
                $this->feeds[$key] = 'cloudflare';
            elseif ($response->body === '')
                $this->feeds[$key] = 'cant_find_rss';
            else
            {
                $body = $response->body;
                if ($escapeAmpersands)
                    $body = str_replace(array('<![CDATA[', ']]'), '', str_replace('&', '&amp;', $body));
                $before = libxml_use_internal_errors(TRUE);
                $xml = simplexml_load_string($body);
                libxml_clear_errors();
                libxml_use_internal_errors($before);
                $this->feeds[$key] = $xml === FALSE ? 'rss_parse_false' : $xml;
            }
        }
        if (is_string($this->feeds[$key]))
            throw new TrackerException($this->feeds[$key]);
        return $this->feeds[$key];
    }

    //записи ленты от старых к новым
    /** @return \SimpleXMLElement[] */
    protected static function items(\SimpleXMLElement $xml): array
    {
        $items = array();
        foreach ($xml->channel->item as $item)
            array_unshift($items, $item);
        return $items;
    }

    //Новее ли серия той, что уже скачана. $have - 'S01E02' или '' (ничего не скачано).
    public static function newer(string $have, int $season, int $episode): bool
    {
        if ( ! preg_match('/S(\d+)E(\d+)/i', $have, $m))
            return TRUE;
        return $season > (int)$m[1] || ($season == (int)$m[1] && $episode > (int)$m[2]);
    }

    //дата из RSS (RFC 822) в местном времени: array('Y-m-d H:i:s', 'd Мес Y H:i')
    protected static function feedDate(string $pubDate): array
    {
        $time = strtotime($pubDate);
        if ($time === FALSE)
            $time = time();
        return array(date('Y-m-d H:i:s', $time), date('d', $time).' '.self::monthName(date('n', $time)).' '.date('Y H:i', $time));
    }

    //сообщение об обновлении сериала
    protected static function message(string $name, string $quality, int $season, int $episode): string
    {
        return $name.' '.$quality.' обновлён до '.$episode.' серии, '.$season.' сезона.';
    }
}
