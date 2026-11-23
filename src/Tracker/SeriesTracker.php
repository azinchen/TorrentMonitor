<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Response;

// A tracker where a series is watched: new episodes are looked up by title in the tracker's RSS feed.
// A topic has no id: it is identified by title and quality (sd, 720, 1080).
abstract class SeriesTracker extends Tracker
{
    /** @var array<string,\SimpleXMLElement|string> parsed feeds (or an error code) keyed by content */
    private array $feeds = array();

    // Parses the RSS feed (once for all series of the tracker).
    // TrackerException: cant_find_rss - the feed was not received, rss_parse_false - it is malformed.
    protected function feed(Response $response, bool $escapeAmpersands = FALSE): \SimpleXMLElement
    {
        $key = md5($response->body);
        if ( ! isset($this->feeds[$key]))
        {
            if ($response->challenged)
                $this->feeds[$key] = 'cloudflare';
            elseif ($response->body === '')
                $this->feeds[$key] = $response->failure() ?: 'cant_find_rss';
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

    // feed items from oldest to newest
    /** @return \SimpleXMLElement[] */
    protected static function items(\SimpleXMLElement $xml): array
    {
        $items = array();
        foreach ($xml->channel->item as $item)
            array_unshift($items, $item);
        return $items;
    }

    // Whether the episode is newer than the one already downloaded. $have - 'S01E02' or '' (nothing downloaded).
    public static function newer(string $have, int $season, int $episode): bool
    {
        if ( ! preg_match('/S(\d+)E(\d+)/i', $have, $m))
            return TRUE;
        return $season > (int)$m[1] || ($season == (int)$m[1] && $episode > (int)$m[2]);
    }

    // date from RSS (RFC 822) in local time: array('Y-m-d H:i:s', 'd Mon Y H:i') with a Russian month
    protected static function feedDate(string $pubDate): array
    {
        $time = strtotime($pubDate);
        if ($time === FALSE)
            $time = time();
        return array(date('Y-m-d H:i:s', $time), date('d', $time).' '.self::monthName(date('n', $time)).' '.date('Y H:i', $time));
    }

    // series update message
    protected static function message(string $name, string $quality, int $season, int $episode): string
    {
        return $name.' '.$quality.' обновлён до '.$episode.' серии, '.$season.' сезона.';
    }
}
