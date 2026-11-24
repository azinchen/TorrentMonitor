<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Request;
use TorrentMonitor\Http\Response;

// Feed of lostfilm.tv releases on a third-party server: no account needed.
final class LostfilmMirror extends SeriesTracker
{
    const ADDRESSES = array('https://rss.bzda.ru');
    const NEEDS_ACCOUNT = FALSE;
    // fallback feed address, used if the main source is unavailable
    const MIRROR_URL = 'https://lf.tormon.ru/rss.xml';

    // topic quality => array(release link pattern, tag in the file name)
    const QUALITY = array(
        'sd'   => array('avi|AVI', 'SD'),
        '1080' => array('mkv|MKV', 'HD'),
        '720'  => array('mp4|MP4', 'MP4'),
    );

    // feed from the fallback address (loaded once per run)
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
        // words in release titles are separated by dots; a dot in the pattern is any character
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
                // keep the episodes already found: the error will repeat on the next run
                if (empty($found))
                    throw $e;
                break;
            }
            list($date, $dateText) = $this->feedDate((string)$item->pubDate);
            $file = str_replace(' ', '.', $topic['name']).'.S'.$se[1].'E'.$se[2].'.'.$quality[1];
            $release = new Found($torrent, $date, $dateText, $file, self::message($topic['name'], $quality[1], $season, $episode));
            $release->episode = 'S'.$se[1].'E'.$se[2];
            $found[] = $release;
            $have = $release->episode;
        }
        return $found;
    }
}
