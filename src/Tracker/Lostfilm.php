<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Request;
use TorrentMonitor\Http\Response;

final class Lostfilm extends SeriesTracker
{
    const ADDRESSES = array('https://www.lostfilm.download', 'https://www.lostfilm.tv', 'https://www.lostfilmtv5.site');

    // topic quality => array(label on the page, release title pattern, tag in the file name)
    const QUALITY = array(
        'sd'   => array('SD', '(WEBRip|WEB-DLRip|HDTVRip)', 'SD'),
        '1080' => array('1080', '1080p? (WEBRip|WEB-DLRip|HDTVRip)', 'HD'),
        '720'  => array('MP4', '720p? (WEB-DLRip|WEBRip|WEB-DL|HDTVRip)', 'MP4'),
    );

    public function validName(string $name): bool
    {
        return (bool)preg_match('/^[\.\+\s\'\`\:\;\-a-zA-Z0-9]+$/', $name);
    }

    protected function sessionAlive(string $cookie): bool
    {
        $response = $this->send($this->get('/', $cookie));
        return (bool)preg_match('/<a href=\"\/my\" title=\"Перейти в личный кабинет\">/u', $response->body);
    }

    protected function signIn(): string
    {
        $body = 'act=users&type=login&mail='.rawurlencode($this->account['login']).'&pass='.rawurlencode($this->account['password']).'&rem=1';
        $response = $this->send($this->post('/ajaxik.php', $body, ''));

        if ($response->body === '' && $response->cookie('lf_session') === NULL)
            throw new TrackerException('cant_get_auth_page');
        if (strpos($response->body, '"need_captcha":true') !== FALSE)
            throw new TrackerException('captcha');
        if (strpos($response->body, '"error"') !== FALSE)
            throw new TrackerException('credential_wrong');
        if (empty($response->cookie('lf_session')))
            throw new TrackerException('cant_find_cookie');
        return 'lf_session='.$response->cookie('lf_session');
    }

    public function request(array $topic): ?Request
    {
        return $this->get('/rss.xml', '');
    }

    public function check(array $topic, Response $response): array
    {
        $xml = $this->feed($response, TRUE);
        $quality = isset(self::QUALITY[$topic['quality']]) ? self::QUALITY[$topic['quality']] : self::QUALITY['sd'];
        $have = $topic['episode'];
        $found = array();

        foreach (self::items($xml) as $item)
        {
            $title = (string)$item->title;
            if ( ! preg_match('/'.preg_quote($topic['name'], '/').'/i', $title) || ! preg_match('/S(\d{2})E(\d{2})/', $title, $se))
                continue;
            $season = (int)$se[1];
            $episode = (int)$se[2];
            if ( ! self::newer($have, $season, $episode))
                continue;
            if ( ! preg_match('/\/Static\/Images\/(.*)\/Posters\/image.jpg/', (string)$item->description->img['src'], $id))
                continue;

            try
            {
                $release = $this->release($topic, $item, $quality, $id[1], $se);
            }
            catch (TrackerException $e)
            {
                // keep the episodes already found: the error will repeat on the next run
                if (empty($found))
                    throw $e;
                break;
            }
            $found[] = $release;
            $have = $release->episode;
        }
        return $found;
    }

    private function release(array $topic, \SimpleXMLElement $item, array $quality, string $showId, array $se): Found
    {
        // page that redirects to the list of the episode's releases
        $search = $this->send($this->get('/v_search.php?a='.$showId.sprintf('%03d%03d', (int)$se[1], (int)$se[2])));
        if ( ! preg_match('/a href=\"(.*)\"/U', $search->body, $link))
            throw new TrackerException('cant_find_dowload_link');
        $list = $this->send($this->get($this->url($link[1]), ''));

        if ( ! preg_match_all('/<div class=\"inner-box--label\">\s*'.$quality[0].'\s*<\/div>\s*<div class=\"inner-box--link main\"><a href=\"(https:\/\/(n\.)?tracktor\.(in|site)\/td\.php\?s=[^\"]*)\">[\s\S]*'.$quality[1].'<\/a><\/div>/U', $list->body, $m))
            throw new TrackerException('quality_not_found');

        $torrent = $this->torrent(Request::get($m[1][0])->tracker($this->name));
        list($date, $dateText) = $this->feedDate((string)$item->pubDate);
        $file = str_replace(' ', '.', $topic['name']).'.S'.$se[1].'E'.$se[2].'.'.$quality[2];
        $release = new Found($torrent, $date, $dateText, $file, self::message($topic['name'], $quality[2], (int)$se[1], (int)$se[2]));
        $release->episode = 'S'.$se[1].'E'.$se[2];
        return $release;
    }

    public function searchUrl(string $name): string
    {
        return $this->base().'/search?q='.urlencode($name);
    }
}
