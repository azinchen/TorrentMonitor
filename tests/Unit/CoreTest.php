<?php
use PHPUnit\Framework\TestCase;

if (getenv('TM_DATA_DIR') === false || getenv('TM_DATA_DIR') === '')
    putenv('TM_DATA_DIR='.sys_get_temp_dir().'/tm-unit-data-'.getmypid());
require_once TM_ROOT.'/src/bootstrap.php';

use TorrentMonitor\Client\Bencode;
use TorrentMonitor\Data\Topics;
use TorrentMonitor\Http\Client;
use TorrentMonitor\Http\Cookies;
use TorrentMonitor\Http\Request;
use TorrentMonitor\Storage\Migrations;
use TorrentMonitor\Tracker\Kinozal;
use TorrentMonitor\Tracker\Registry;
use TorrentMonitor\Tracker\SeriesTracker;
use TorrentMonitor\Tracker\Tracker;

// Pure logic of the core: no network, no data files.
final class CoreTest extends TestCase
{
    private function call($object, $method, ...$arguments)
    {
        $reflection = new ReflectionMethod($object, $method);
        if (PHP_VERSION_ID < 80100)
            $reflection->setAccessible(true);
        return $reflection->invoke($object, ...$arguments);
    }

    // ---- HTTP

    /** @dataProvider challengePages */
    public function testCloudflareChallengeIsRecognised($status, $headers, $body, $expected)
    {
        $this->assertSame($expected, Client::isChallengePage($status, $headers, $body));
    }

    public function challengePages()
    {
        return array(
            'classic'             => array(403, '', '<title>Just a moment...</title>', true),
            'header'              => array(403, "HTTP/2 403\r\ncf-mitigated: challenge\r\n", '<html></html>', true),
            'old style'           => array(503, '', 'Checking your browser before accessing. Cloudflare', true),
            'plain 403'           => array(403, '', '<h1>Forbidden</h1>', false),
            'ok page with words'  => array(200, '', 'Just a moment', false),
            'cloudflare in footer' => array(403, '', 'Protected by Cloudflare', false),
        );
    }

    public function testCookieHelpers()
    {
        $this->assertSame(array('a' => '1', 'b' => 'x=y'), Cookies::parse('a=1; b=x=y; broken'));
        $this->assertSame('a=2; b=3', Cookies::merge('a=1; b=3', 'a=2'));
        $headers = "HTTP/1.1 302 Found\r\nSet-Cookie: bb_session=abc; path=/; HttpOnly\r\nset-cookie: uid=7\r\n\r\n";
        $this->assertSame(array('bb_session' => 'abc', 'uid' => '7'), Cookies::fromHeaders($headers));
        $this->assertTrue(Cookies::isCloudflare('cf_clearance'));
        $this->assertTrue(Cookies::isCloudflare('__cf_bm'));
        $this->assertFalse(Cookies::isCloudflare('bb_session'));
    }

    public function testProxySelection()
    {
        $client = new Client(array(
            'proxy'        => array('enabled' => true, 'address' => '10.0.0.1:9050', 'type' => 'SOCKS5'),
            'trackerProxy' => array(
                'rutracker.org' => array('use' => 'no'),
                'kinozal.me'    => array('use' => 'yes', 'address' => '10.0.0.2:3128', 'type' => 'http'),
            ),
        ));
        $this->assertSame('socks5h://10.0.0.1:9050', $client->proxyFor(Request::get('https://nnmclub.to/')));
        $this->assertNull($client->proxyFor(Request::get('https://rutracker.org/forum/')));
        $this->assertSame('http://10.0.0.2:3128', $client->proxyFor(Request::get('https://www.kinozal.me/')));
        $this->assertSame('http://10.0.0.2:3128', $client->proxyFor(Request::get('https://mirror.example/')->tracker('kinozal.me')));
        $this->assertNull($client->proxyFor(Request::get('http://qbittorrent:8080/')->plain()), 'torrent clients are reached directly');

        $off = new Client(array('trackerProxy' => array('kinozal.me' => array('use' => 'yes'))));
        $this->assertNull($off->proxyFor(Request::get('https://kinozal.me/')), 'no address anywhere: no proxy');
    }

    public function testMultipartBody()
    {
        $request = Request::multipart('http://x/', array('savepath' => '/d'), array('torrents' => array('a".torrent', 'DATA')));
        $this->assertMatchesRegularExpression('~^Content-Type: multipart/form-data; boundary=(\S+)$~', $request->headers[0]);
        $this->assertStringContainsString("name=\"savepath\"\r\n\r\n/d\r\n", $request->body);
        $this->assertStringContainsString("filename=\"a_.torrent\"", $request->body);
        $this->assertStringContainsString("\r\n\r\nDATA\r\n", $request->body);
    }

    // ---- torrent files

    public function testInfoHashAndComment()
    {
        $torrent = TorrentBuilder::torrent(42, 3, 'https://rutracker.org/forum/viewtopic.php?t=42');
        $this->assertSame(TorrentBuilder::infoHash(42, 3), Bencode::infoHash($torrent));
        $this->assertSame('https://rutracker.org/forum/viewtopic.php?t=42', Bencode::comment($torrent));
        $this->assertNull(Bencode::infoHash('<html>not a torrent</html>'));
        $this->assertNull(Bencode::infoHash('d4:infod'));
        $this->assertTrue(Tracker::isTorrent($torrent));
        $this->assertFalse(Tracker::isTorrent('<html>login page with the word announce '.str_repeat('x', 200).'</html>'));
        $this->assertFalse(Tracker::isTorrent(''));
    }

    // ---- data

    /** @dataProvider qualities */
    public function testOldQualityNumbers($tracker, $hd, $expected)
    {
        $this->assertSame($expected, Migrations::quality($tracker, $hd));
    }

    public function qualities()
    {
        return array(
            // lostfilm numbered its qualities the other way round
            array('lostfilm.tv', 0, 'sd'), array('lostfilm.tv', 1, '1080'), array('lostfilm.tv', 2, '720'),
            array('lostfilm-mirror', 1, '1080'),
            array('newstudio.tv', 0, 'sd'), array('newstudio.tv', 1, '720'), array('newstudio.tv', 2, '1080'),
            array('baibako.tv', 2, '1080'),
        );
    }

    public function testTopicsFileMigration()
    {
        $old = array('version' => 1, 'nextId' => 8, 'nextUserId' => 1, 'users' => array(), 'torrents' => array(array(
            'id' => 7, 'tracker' => 'lostfilm.tv', 'name' => 'House', 'hd' => 1, 'path' => '/d/', 'torrent_id' => '', 'ep' => 'S01E02',
            'timestamp' => '0000-00-00 00:00:00', 'auto_update' => 1, 'hash' => 'AB', 'script' => '', 'pause' => 1, 'error' => 0, 'closed' => 0,
            'category' => 'tv', 'addedAt' => '2026-01-01 00:00:00', 'lastActivityAt' => '',
        )));
        $new = Migrations::torrents1($old);
        $this->assertArrayNotHasKey('torrents', $new);
        $topic = $new['topics'][0];
        $this->assertSame(array(7, 'lostfilm.tv', 'House', '', '1080', 'S01E02', '', true, true, false),
            array($topic['id'], $topic['tracker'], $topic['name'], $topic['topicId'], $topic['quality'], $topic['episode'],
                $topic['updatedAt'], $topic['autoRename'], $topic['paused'], $topic['error']));
        $this->assertSame(8, $new['nextId']);
    }

    public function testStateFileMigration()
    {
        $old = array('version' => 1, 'settings' => array('cf_userAgent' => 'x', 'sentUpdateNotification' => '1'), 'lastStart' => '21-11-2026 10:05:00',
            'cookies' => array('rutracker.org' => 'bb_session=1'), 'nextWarningId' => 2,
            'warnings' => array(array('id' => 1, 'time' => '2026-11-21 10:00:00', 'where' => 'rutracker.org', 'reason' => 'cant_find_date', 't_id' => 5)),
            'news' => array(array('id' => 'update_4.0.0', 'text' => 't', 'new' => 1)), 'nextBufferId' => 4,
            'buffer' => array(array('id' => 3, 'user_id' => 1, 'section' => 'S', 'threme_id' => 77, 'threme' => 'T', 'timestamp' => '2026-11-20',
                'accept' => 1, 'downloaded' => 0, 'new' => 0, 'tracker' => 'rutracker.org')),
            'temp' => array('5' => array('name' => 'N', 'path' => '/app/torrents/[rutracker.org]_5.torrent', 'tracker' => 'rutracker.org', 'date' => 'd')));
        $new = Migrations::state1($old);
        $this->assertTrue($new['updateNotified']);
        $this->assertSame('2026-11-21 10:05:00', $new['lastRun']);
        $this->assertSame(array('id' => 1, 'time' => '2026-11-21 10:00:00', 'source' => 'rutracker.org', 'code' => 'cant_find_date', 'topicId' => 5), $new['warnings'][0]);
        $this->assertTrue($new['news'][0]['unread']);
        $this->assertSame(array('77', 'T', true, false, 4), array($new['found'][0]['topicId'], $new['found'][0]['title'], $new['found'][0]['accepted'], $new['found'][0]['unread'], $new['nextFoundId']));
        $this->assertSame('[rutracker.org]_5.torrent', $new['pending']['5']['file']);
        foreach (array('settings', 'lastStart', 'buffer', 'temp', 'nextBufferId') as $gone)
            $this->assertArrayNotHasKey($gone, $new);
    }

    public function testCheckingStopsAfterDaysWithoutUpdates()
    {
        $now = strtotime('2026-11-22 12:00:00');
        $topic = array('stopAfterDays' => null, 'addedAt' => '2026-01-01 00:00:00', 'lastActivityAt' => '2026-11-01 12:00:00');
        $this->assertFalse(Topics::expired($topic, 0, $now), '0 = never stop');
        $this->assertFalse(Topics::expired($topic, 30, $now));
        $this->assertTrue(Topics::expired($topic, 20, $now));
        $this->assertFalse(Topics::expired(array('stopAfterDays' => 0) + $topic, 20, $now), 'the topic overrides the global setting');
        $this->assertTrue(Topics::expired(array('stopAfterDays' => 5) + $topic, 0, $now));
        $never = array('stopAfterDays' => null, 'addedAt' => '2026-11-20 00:00:00', 'lastActivityAt' => '');
        $this->assertFalse(Topics::expired($never, 20, $now), 'a topic that never updated counts from the day it was added');
        $this->assertTrue(Topics::expired(array('addedAt' => '2026-10-01 00:00:00') + $never, 20, $now));
    }

    // ---- trackers

    public function testEveryRegisteredTrackerLoads()
    {
        foreach (Registry::names() as $name)
        {
            $tracker = Registry::get($name);
            $this->assertSame($name, $tracker->name());
            $this->assertMatchesRegularExpression('~^https?://[^/]+$~', $tracker->base(), $name);
        }
        $this->assertFalse(Registry::needsAccount('rutor.is'));
        $this->assertTrue(Registry::needsAccount('rutracker.org'));
        $this->assertTrue(Registry::watchesUsers('rutracker.org'));
        $this->assertFalse(Registry::watchesUsers('kinozal.me'));
        $this->assertSame('series', Registry::kind('lostfilm.tv'));
        $this->assertNull(Registry::kind('casstudio.tk'));
    }

    /** @dataProvider topicUrls */
    public function testTopicIsRecognisedByItsAddress($url, $tracker, $topicId)
    {
        $parsed = Registry::fromUrl($url);
        $this->assertSame(array('tracker' => $tracker, 'topicId' => $topicId), $parsed);
        // and the link built for the topic leads back to the same topic
        $this->assertSame($parsed, Registry::fromUrl(Registry::get($tracker)->topicUrl($topicId)));
    }

    public function topicUrls()
    {
        return array(
            array('https://rutracker.org/forum/viewtopic.php?t=123', 'rutracker.org', '123'),
            array('https://rutracker.net/forum/viewtopic.php?t=123', 'rutracker.org', '123'),
            array('https://www.nnmclub.to/forum/viewtopic.php?t=55', 'nnmclub.to', '55'),
            array('http://pornolab.net/forum/viewtopic.php?t=7', 'pornolab.net', '7'),
            array('https://kinozal.me/details.php?id=99', 'kinozal.me', '99'),
            array('http://kinozal.tv/details.php?id=99', 'kinozal.tv', '99'),
            array('https://kinozal.guru/details.php?id=99', 'kinozal.guru', '99'),
            array('http://rutor.is/torrent/4321/some-name', 'rutor.is', '4321'),
            array('https://booktracker.org/viewtopic.php?t=8', 'booktracker.org', '8'),
            array('http://baibako.tv/details.php?id=12', 'baibako.tv_forum', '12'),
            array('http://rustorka.com/forum/viewtopic.php?t=31', 'rustorka.com', '31'),
            array('http://tfile.cc/forum/viewtopic.php?t=66', 'tfile.cc', '66'),
        );
    }

    public function testAddressesThatAreNotTopics()
    {
        $this->assertNull(Registry::fromUrl('https://www.lostfilm.tv/series/House'), 'series trackers are followed by name');
        $this->assertNull(Registry::fromUrl('https://example.org/viewtopic.php?t=1'));
        $this->assertNull(Registry::fromUrl('https://rutracker.org/forum/viewtopic.php?t=abc'));
        $this->assertNull(Registry::fromUrl('not a url'));
    }

    public function testRutrackerPage()
    {
        $tracker = Registry::get('rutracker.org');
        $page = "<title>Имя &amp; раздача :: RuTracker.org</title>\n<td style=\"width: 70%; padding: 5px;\">\n\t\t<ul class=\"inlined middot-separated\">\n\t\t\t<li>21-Ноя-26 10:05</li>\n";
        $this->assertSame(array('2026-11-21 10:05:00', '21 Ноя 2026 10:05'), $this->call($tracker, 'parseDate', $page));
        $this->assertSame('Имя & раздача', $this->call($tracker, 'parseTitle', $page));
        $this->assertNull($this->call($tracker, 'parseDate', '<html>login page</html>'));
    }

    public function testKinozalDates()
    {
        $now = strtotime('2026-11-22 12:34:56');
        $this->assertSame(array('2026-11-05 10:15:00', '5 ноября 2026 в 10:15'), Kinozal::date('5 ноября 2026 в 10:15', $now));
        $this->assertSame('2026-11-22 09:07:00', Kinozal::date('сегодня в 09:07', $now)[0]);
        $this->assertSame('2026-11-21 23:00:00', Kinozal::date('вчера в 23:00', $now)[0]);
        $this->assertSame('21 Ноя 2026 в 23:00', Kinozal::date('вчера в 23:00', $now)[1]);
        $this->assertSame('2026-11-22 12:34:00', Kinozal::date('сейчас', $now)[0]);
        $this->assertNull(Kinozal::date('когда-то', $now));
    }

    public function testNewerEpisode()
    {
        $this->assertTrue(SeriesTracker::newer('', 1, 1));
        $this->assertTrue(SeriesTracker::newer('S01E02', 1, 3));
        $this->assertTrue(SeriesTracker::newer('S01E09', 2, 1));
        $this->assertTrue(SeriesTracker::newer('S01E02', 2, 5), 'a new season is newer whatever the episode number');
        $this->assertFalse(SeriesTracker::newer('S01E02', 1, 2));
        $this->assertFalse(SeriesTracker::newer('S02E01', 1, 9));
    }

    public function testMonths()
    {
        $this->assertSame('11', Tracker::monthNumber('ноября'));
        $this->assertSame('11', Tracker::monthNumber('Ноя'));
        $this->assertSame('05', Tracker::monthNumber('мая'));
        $this->assertSame('08', Tracker::monthNumber('Aug'));
        $this->assertNull(Tracker::monthNumber('xyz'));
        $this->assertSame('Янв', Tracker::monthName('01'));
        $this->assertSame('Дек', Tracker::monthName(12));
    }
}
