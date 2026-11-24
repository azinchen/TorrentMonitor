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
        $this->assertArrayNotHasKey('news', $new, 'the news list is dropped');
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
        $this->assertNull(Registry::kind('tfile.cc'));
        $this->assertNull(Registry::kind('baibako.tv'));
        $this->assertNull(Registry::fromUrl('http://baibako.tv/details.php?id=12'));
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
            array('http://rustorka.com/forum/viewtopic.php?t=31', 'rustorka.com', '31'),
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
        // 12:34 UTC is 15:34 on the tracker's (Moscow) clock
        $now = strtotime('2026-11-22 12:34:56 UTC');
        $this->assertSame(array('2026-11-05 10:15:00', '5 ноября 2026 в 10:15'), Kinozal::date('5 ноября 2026 в 10:15', $now));
        $this->assertSame('2026-11-22 09:07:00', Kinozal::date('сегодня в 09:07', $now)[0]);
        $this->assertSame('2026-11-21 23:00:00', Kinozal::date('вчера в 23:00', $now)[0]);
        $this->assertSame('21 Ноя 2026 в 23:00', Kinozal::date('вчера в 23:00', $now)[1]);
        $this->assertSame('2026-11-22 15:34:00', Kinozal::date('сейчас', $now)[0]);
        $this->assertNull(Kinozal::date('когда-то', $now));
    }

    // "today" of the tracker is not "today" of a server in another time zone: the date must not
    // depend on where the server is, or the topic looks changed and is downloaded again
    public function testKinozalTodayIsTheDayOfTheTracker()
    {
        // 22 Nov 22:30 UTC: already 23 Nov 01:30 in Moscow, still 22 Nov 17:30 in New York
        $now = strtotime('2026-11-22 22:30:00 UTC');
        $before = date_default_timezone_get();
        foreach (array('UTC', 'America/New_York', 'Asia/Vladivostok') as $server)
        {
            date_default_timezone_set($server);
            $this->assertSame('2026-11-23 01:10:00', Kinozal::date('сегодня в 01:10', $now)[0], 'server in '.$server);
            $this->assertSame('2026-11-22 19:00:00', Kinozal::date('вчера в 19:00', $now)[0], 'server in '.$server);
        }
        date_default_timezone_set($before);
        // a tracker set to another zone
        $this->assertSame('2026-11-22 17:00:00', Kinozal::date('сегодня в 17:00', $now, new \DateTimeZone('America/New_York'))[0]);
    }

    public function testTimeZones()
    {
        $moscow = new \DateTimeZone('Europe/Moscow');
        $this->assertSame('2026-11-23T11:23:00Z', \TorrentMonitor\Time::utc('2026-11-23 14:23:00', $moscow));
        $this->assertSame('', \TorrentMonitor\Time::utc('', $moscow));
        $this->assertSame('', \TorrentMonitor\Time::utc('not a date', $moscow));
        $this->assertSame('МСК', \TorrentMonitor\Time::label($moscow));
        $this->assertSame('EST', \TorrentMonitor\Time::label(new \DateTimeZone('America/New_York'), '2026-11-23 10:00:00'));
        $this->assertSame('UTC+5', \TorrentMonitor\Time::label(new \DateTimeZone('Asia/Yekaterinburg'), '2026-11-23 10:00:00'));
        $this->assertSame('UTC+5:45', \TorrentMonitor\Time::label(new \DateTimeZone('Asia/Kathmandu'), '2026-11-23 10:00:00'));
        $this->assertTrue(\TorrentMonitor\Time::valid('Europe/Kyiv') || \TorrentMonitor\Time::valid('Europe/Kiev'));
        $this->assertFalse(\TorrentMonitor\Time::valid('Mars/Olympus'));
        $this->assertFalse(\TorrentMonitor\Time::valid(''));
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

    // ---- text encodings (mbstring only, no iconv)

    public function testTextConversionDropsWhatCannotBeConverted()
    {
        $cp1251 = "\xCF\xF0\xE8\xE2\xE5\xF2";
        $this->assertSame('Привет', \TorrentMonitor\Text::toUtf8($cp1251, 'windows-1251'));
        $this->assertSame('Привет', \TorrentMonitor\Text::toUtf8($cp1251."\x98", 'windows-1251'), 'a byte with no meaning in cp1251');
        $this->assertSame($cp1251.' ', \TorrentMonitor\Text::fromUtf8('Привет 日本', 'windows-1251'), 'letters missing in cp1251');
        $this->assertSame('abcd', \TorrentMonitor\Text::cleanUtf8("ab\xFFcd"));
        $this->assertSame('При', \TorrentMonitor\Text::cleanUtf8(substr('Прив', 0, 7)), 'a letter cut in half');
        $this->assertSame('', \TorrentMonitor\Text::toUtf8('abc', 'no-such-encoding'));
        $this->assertSame('?', mb_convert_encoding("\xFF", 'UTF-8', 'UTF-8'), 'the global substitute character is restored');
    }

    public function testSourceDoesNotUseIconv()
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(TM_ROOT.'/src', FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file)
            if ($file->getExtension() == 'php')
                $this->assertDoesNotMatchRegularExpression('/\biconv(_\w+)?\s*\(/', file_get_contents($file->getPathname()), $file->getPathname());
    }

    // ---- topic dates

    public function testNnmclubDate()
    {
        $tracker = Registry::get('nnmclub.to');
        $expected = array('2026-11-22 04:57:11', '22 Ноя 2026 04:57:11');
        // as the site sends it (plus the later "checked by a moderator" date that must not win)
        $page = '<td class="genmed">&nbsp;Зарегистрирован:&nbsp;</td>'."\n".'  <td class="genmed">&nbsp;22 Ноя 2026 04:57:11</td></tr>'
            .'<td class="genmed">&nbsp;Оформление проверено модератором 22 Ноя 2026 04:58:26</td>';
        $this->assertSame($expected, $this->call($tracker, 'parseDate', $page));
        // as a solver's browser returns it: real no-break spaces
        $this->assertSame($expected, $this->call($tracker, 'parseDate', str_replace('&nbsp;', "\u{A0}", $page)));
        // other markup around the label
        $this->assertSame($expected, $this->call($tracker, 'parseDate', '<td>Зарегистрирован:</td><td class="x"> 22 Ноя 2026 04:57:11 </td>'));
        // no label: the old cell
        $this->assertSame($expected, $this->call($tracker, 'parseDate', '<td class="genmed">&nbsp;22 Ноя 2026 04:57:11</td>'));
        $this->assertNull($this->call($tracker, 'parseDate', '<td class="genmed">&nbsp;Зарегистрирован:&nbsp;</td>'));
    }

    public function testBooktrackerDate()
    {
        $tracker = Registry::get('booktracker.org');
        foreach (array(
            'Зарегистрирован &nbsp; [ <span title="6 дней назад">2026-11-21 10:00</span> ]',
            "Зарегистрирован\n\t&nbsp;\n\t[\n\t<span>2026-11-21 10:00</span>\n\t]",
            "Зарегистрирован\u{A0}[ <span class=\"d\" title=\"x\">2026-11-21 10:00</span> ]",
        ) as $page)
            $this->assertSame('2026-11-21 10:00:00', $this->call($tracker, 'parseDate', $page)[0], $page);
        $this->assertNull($this->call($tracker, 'parseDate', 'Зарегистрирован [ <span>когда-то</span> ]'));
    }

    // ---- why a request got no response

    public function testConnectionFailureNamesTheProxyOrTheSite()
    {
        $response = function ($status, $errno, $proxy) {
            $r = new \TorrentMonitor\Http\Response();
            $r->status = $status;
            $r->errno = $errno;
            $r->proxy = $proxy;
            $r->error = $errno ? 'curl says so' : '';
            return $r;
        };
        $this->assertNull($response(200, 0, NULL)->failure());
        $this->assertNull($response(503, 0, 'socks5h://p:1080')->failure(), 'an answer, even a bad one, is not a connection failure');
        $this->assertSame('', $response(200, 0, NULL)->failureText());

        foreach (array(5, 7, 97) as $errno)
            $this->assertSame('proxy_fail', $response(0, $errno, 'socks5h://p:1080')->failure(), (string)$errno);
        $this->assertSame('tracker_unreachable', $response(0, 7, NULL)->failure(), 'without a proxy the site itself refused');
        $this->assertSame('tracker_unreachable', $response(0, 28, 'socks5h://p:1080')->failure(), 'a timeout through a proxy does not prove the proxy is to blame');
        $this->assertSame('tracker_unreachable', $response(0, 6, NULL)->failure());

        $this->assertSame('прокси socks5h://p:1080 не отвечает', $response(0, 7, 'socks5h://p:1080')->failureText());
        $this->assertStringContainsString('не смог соединиться с сайтом', $response(0, 97, 'socks5h://p:1080')->failureText());
        $this->assertSame('адрес сайта не найден (DNS)', $response(0, 6, NULL)->failureText());
        $this->assertSame('нет ответа за отведённое время через прокси socks5h://p:1080', $response(0, 28, 'socks5h://p:1080')->failureText());
        $this->assertSame('нет ответа (curl says so)', $response(0, 99, NULL)->failureText());
    }

    public function testKinozalEntriesFallBackToTheOtherAddresses()
    {
        $addresses = function ($name) { return $this->call(Registry::get($name), 'defaultAddresses'); };
        $this->assertSame(array('https://kinozal.me', 'https://kinozal.guru'), $addresses('kinozal.me'));
        $this->assertSame(array('https://kinozal.guru', 'https://kinozal.me'), $addresses('kinozal.guru'));
        // the dead domain stays first only so that its entry keeps its name; the others take over
        $this->assertSame(array('https://kinozal.tv', 'https://kinozal.me', 'https://kinozal.guru'), $addresses('kinozal.tv'));
    }

    // ---- link of a topic to a Sonarr series

    public function testSeasonsAndSeriesTitles()
    {
        $this->assertSame(array(1, 2), Topics::seasons('2, 1 2;1'));
        $this->assertSame(array(0, 12), Topics::seasons(array(12, '0')));
        $this->assertSame(array(), Topics::seasons(''));
        $this->assertNull(Topics::seasons('first'));
        $this->assertNull(Topics::seasons(array(1.5)));
        $this->assertNull(Topics::seasons('-1'));

        $this->assertTrue(Topics::sameTitle("Marvel's Agents of S.H.I.E.L.D.", 'marvels agents of s h i e l d'));
        $this->assertTrue(Topics::sameTitle(' Дом   Дракона ', 'дом дракона'));
        $this->assertFalse(Topics::sameTitle('House', 'House of the Dragon'));
        $this->assertFalse(Topics::sameTitle('', ''));
        $this->assertFalse(Topics::sameTitle('!!!', '???'));

        // Sonarr adds the year to some titles; its page address holds the same name
        $this->assertTrue(Topics::sameTitle('The Kitchen', 'The Kitchen (2012)'));
        $this->assertTrue(Topics::sameTitle('the kitchen 2012', 'The Kitchen (2012)'));
        $this->assertTrue(Topics::sameTitle('the-kitchen-2012', 'The Kitchen (2012)'));
        $this->assertFalse(Topics::sameTitle('The Kitchen (2020)', 'The Kitchen (2012)'), 'another year is another series');
        $this->assertTrue(Topics::sameTitle('2012', '2012 (2009)'), 'a title that is only a year stays a title');
        $this->assertTrue(Topics::sameTitle('1923', '1923'));
        $this->assertTrue(Topics::sameTitle("The Boy's Word: Blood on the Asphalt", 'the boys word blood on the asphalt'));
        $this->assertFalse(Topics::sameTitle('Кухня', 'The Kitchen (2012)'), 'the Russian name is not what Sonarr sends');

        $this->assertSame('the-kitchen-2012', Topics::sonarrName(' http://sonarr.local:8989/series/the-kitchen-2012 '));
        $this->assertSame('the-kitchen-2012', Topics::sonarrName('https://example.org/sonarr/series/the-kitchen-2012/'));
        $this->assertSame('The Kitchen (2012)', Topics::sonarrName(' The Kitchen (2012) '));
    }

    public function testSummaryOfAReleaseDescription()
    {
        $summary = function ($notes) { return \TorrentMonitor\Update\Release::summary($notes); };
        $this->assertSame('Часовые пояса и выбор версий.', $summary("Часовые пояса и **выбор версий**.\n\n### ✨ Новое\n\n- пункт"));
        $this->assertSame('Две строки одного абзаца.', $summary("Две строки\nодного абзаца.\n\nВторой абзац."));
        $this->assertSame('См. вики и config.php.', $summary("См. [вики](https://example.com/wiki) и `config.php`.\n- пункт"));
        // several versions at once: the heading of the newest comes first
        $this->assertSame('Итоговая версия.', $summary("## 9.9.9\n\nИтоговая версия.\n\n## 9.9.8\n\nПрежняя."));
        // commit titles of the version in development have no summary
        $this->assertSame('', $summary("- Первое изменение\n- Второе"));
        $this->assertSame('', $summary(''));
    }

    public function testReleaseDateOnTwoClocks()
    {
        $before = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        $this->assertSame('23 ноя 2026, 06:23 (на трекере 14:23 МСК)', \TorrentMonitor\Time::both('2026-11-23 14:23:00', 'kinozal.me'));
        // another day on the tracker
        $this->assertSame('22 ноя 2026, 17:10 (на трекере 23 ноя, 01:10 МСК)', \TorrentMonitor\Time::both('2026-11-23 01:10:00', 'kinozal.me'));
        // the server on the tracker's clock: one time is enough
        date_default_timezone_set('Europe/Moscow');
        $this->assertSame('23 ноя 2026, 14:23', \TorrentMonitor\Time::both('2026-11-23 14:23:00', 'kinozal.me'));
        $this->assertSame('', \TorrentMonitor\Time::both('', 'kinozal.me'));
        date_default_timezone_set($before);
    }

    public function testRunTakesTopicsOfEveryTrackerInTurn()
    {
        $runner = new \TorrentMonitor\Engine\Runner(function ($line) {}, array('dryRun' => true));
        $parts = new \ReflectionMethod($runner, 'parts');
        $parts->setAccessible(true);
        $topics = array();
        $id = 0;
        foreach (array('rutracker.org' => 150, 'kinozal.me' => 150, 'lostfilm.tv' => 30, 'nnmclub.to' => 5) as $tracker => $count)
            for ($i = 0; $i < $count; $i++)
                $topics[++$id] = array('id' => $id, 'tracker' => $tracker);

        $result = $parts->invoke($runner, $topics);
        $this->assertSame(335, array_sum(array_map('count', $result)), 'every topic is in exactly one part');
        $this->assertSame(array_keys($topics), self::sorted(array_merge(...array_map('array_keys', $result))));
        foreach ($result as $part)
            $this->assertLessThanOrEqual(\TorrentMonitor\Engine\Runner::PART + 30, count($part));

        // the first part holds topics of every tracker, and all the series of the feed tracker
        $first = array_count_values(array_column($result[0], 'tracker'));
        $this->assertSame(30, $first['lostfilm.tv'], 'the series of one tracker share a feed and stay together');
        $this->assertGreaterThan(20, $first['rutracker.org']);
        $this->assertGreaterThan(20, $first['kinozal.me']);
        $this->assertSame(5, $first['nnmclub.to']);
        foreach (array_slice($result, 1) as $part)
            $this->assertArrayNotHasKey('lostfilm.tv', array_count_values(array_column($part, 'tracker')));
    }

    public function testSimultaneousRequestsAreLimitedPerSite()
    {
        $startable = function ($queue, $site, $busy, $active, $limit) { return \TorrentMonitor\Http\Client::startable($queue, $site, $busy, $active, $limit); };
        $site = array();
        foreach (array('a' => 8, 'b' => 8, 'c' => 8, 'd' => 8) as $name => $count)
            for ($i = 1; $i <= $count; $i++)
                $site[$name.$i] = $name;
        $queue = array_keys($site);

        // nothing runs yet, 3 per site: three sites start, the fourth waits for the total (3 sites x 3)
        $this->assertSame(array('a1', 'a2', 'a3', 'b1', 'b2', 'b3', 'c1', 'c2', 'c3'), $startable($queue, $site, array(), 0, 3));
        // one site is full: its requests wait, the others go on
        $this->assertSame(array('b1', 'c1'), $startable($queue, $site, array('a' => 3, 'b' => 2, 'c' => 2), 7, 3));
        // one request of the full site has finished
        $this->assertSame(array('a1'), $startable($queue, $site, array('a' => 2, 'b' => 3, 'c' => 3), 8, 3));
        // everything is busy
        $this->assertSame(array(), $startable($queue, $site, array('a' => 3, 'b' => 3, 'c' => 3), 9, 3));
        // a single site gets no more than its limit however long the queue is
        $this->assertCount(10, $startable(array_keys(array_fill(0, 50, 'x')), array_fill(0, 50, 'one'), array(), 0, 10));
        // the number of sites asked at once can be set: one site at a time
        $this->assertSame(array('a1', 'a2', 'a3'), \TorrentMonitor\Http\Client::startable($queue, $site, array(), 0, 3, 1));
    }

    private static function sorted(array $values): array
    {
        sort($values);
        return $values;
    }
}
