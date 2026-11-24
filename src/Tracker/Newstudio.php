<?php
namespace TorrentMonitor\Tracker;

use TorrentMonitor\Http\Cookies;
use TorrentMonitor\Http\Request;
use TorrentMonitor\Http\Response;

final class Newstudio extends SeriesTracker
{
    const ADDRESSES = array('https://newstudio.tv');

    // topic quality => array(release title pattern, tag in the file name)
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

    // session cookie from the response: bb_data, or if it is missing - the first one set by the site
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
                // keep the episodes already found: the error will repeat on the next run
                if (empty($found))
                    throw $e;
                break;
            }
            list($date, $dateText) = $this->feedDate((string)$item->pubDate);
            $code = sprintf('S%02dE%02d', $season, $episode);
            $file = str_replace(' ', '.', $topic['name']).'.'.$code.'.'.$quality[1];
            $release = new Found($torrent, $date, $dateText, $file, self::message($topic['name'], $quality[1], $season, $episode));
            $release->episode = $code;
            $found[] = $release;
            $have = $code;
        }
        return $found;
    }

    // torrent file of the release: its id is given on the topic page
    private function release(string $link): string
    {
        $page = $this->send($this->post($link)->follow());
        if ( ! preg_match('/download\.php\?id=(\d{2,6})/', $page->body, $id))
            throw new TrackerException('torrent_file_fail');
        return $this->torrent($this->post('/download.php?id='.$id[1])->follow());
    }
}
