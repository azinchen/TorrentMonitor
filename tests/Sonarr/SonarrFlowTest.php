<?php
use PHPUnit\Framework\TestCase;

// The whole Sonarr flow against a real Sonarr, once per torrent client:
// first setup of Sonarr, a series is added, a season pack is found at the indexer and grabbed,
// TorrentMonitor starts monitoring its topic; another pack of the season replaces the topic and
// its torrent; a topic added by hand and linked to the series is replaced too; deleting the
// series in Sonarr removes what is left. Torrents are added stopped: nothing is downloaded.
//
// Run by: tests/run.sh sonarr. The tracker and the indexer are fake; Sonarr needs the internet
// to load the description of the series.
final class SonarrFlowTest extends TestCase
{
    // a Russian series: Sonarr names it in English, with the year - "The Kitchen (2012)"
    const TVDB = 263656;
    const TOPIC = 'https://rutracker.org/forum/viewtopic.php?t=';

    protected function setUp(): void
    {
        if (getenv('TM_SONARR') !== '1')
            $this->markTestSkipped('the Sonarr test runs only through "tests/run.sh sonarr"');
        Env::resetApp();
        Qbt::removeAll();
        Tr::removeAll();
        Sonarr::reset();
    }

    public function clients()
    {
        return array('qBittorrent' => array('qBittorrent'), 'Transmission' => array('Transmission'), 'Deluge' => array('Deluge'));
    }

    private function useClient($client)
    {
        if ($client == 'qBittorrent')
            AppState::useQbittorrent();
        elseif ($client == 'Transmission')
            AppState::useTransmission();
        else
        {
            @mkdir(Env::DOWNLOADS.'/deluge', 0777, true);
            @chmod(Env::DOWNLOADS.'/deluge', 0777);
            AppState::setSettings(array('useTorrent' => '1', 'torrentClient' => 'Deluge', 'torrentAddress' => Env::DELUGE,
                'torrentLogin' => '', 'torrentPassword' => Env::DELUGE_PASS, 'pathToDownload' => Env::DOWNLOADS.'/deluge/'));
        }
    }

    // whether the torrent client has the torrent
    private function has($client, $hash)
    {
        if ($client == 'qBittorrent')
            return Qbt::has($hash);
        if ($client == 'Transmission')
            return Tr::has($hash);
        require_once TM_ROOT.'/src/bootstrap.php';
        \TorrentMonitor\App::resetHttp();
        return \TorrentMonitor\Client\Clients::current()->info($hash) !== null;
    }

    // a client may list a torrent a moment after it has accepted it
    private function assertInClient($client, $hash, $what)
    {
        try
        {
            Env::waitFor(function () use ($client, $hash) { return $this->has($client, $hash); }, $what, 20);
        }
        catch (RuntimeException $e)
        {
            $seen = $client == 'qBittorrent' ? ' It has: '.implode(', ', array_map(function ($t) { return $t['name'].' ('.$t['state'].')'; }, Qbt::torrents())) : '';
            $this->fail($what.': the torrent '.$hash.' is not in '.$client.'.'.$seen);
        }
        $this->addToAssertionCount(1);
    }

    private function removeFromClient($client, array $hashes)
    {
        if ($client != 'Deluge')
            return;
        foreach ($hashes as $hash)
            \TorrentMonitor\Client\Clients::current()->remove($hash, true);
    }

    private function tracked()
    {
        $ids = array_map(function ($t) { return (string)$t['torrent_id']; }, AppState::topics());
        sort($ids);
        return $ids;
    }

    private function waitForTopics(array $expected, $what)
    {
        $expected = array_map('strval', $expected);
        sort($expected);
        try
        {
            Env::waitFor(function () use ($expected) { return $this->tracked() === $expected; }, $what, 60);
        }
        catch (RuntimeException $e)
        {
            $this->assertSame($expected, $this->tracked(), $what."\nerrors: ".implode(', ', AppState::warningReasons()));
        }
    }

    private function topicOf($topicId)
    {
        foreach (AppState::topics() as $topic)
            if ($topic['torrent_id'] == (string)$topicId)
                return $topic;
        return null;
    }

    // the release of the given topic among what Sonarr found
    private function found(array $releases, $topicId)
    {
        foreach ($releases as $release)
            if (strpos($release['guid'], 't='.$topicId.'&') !== false)
                return $release;
        $this->fail('Sonarr did not find the release of topic '.$topicId.' among: '.implode(', ', array_column($releases, 'title')));
    }

    /** @dataProvider clients */
    public function testSeriesIsGrabbedMonitoredReplacedAndDeleted($client)
    {
        // ---- TorrentMonitor: the tracker account, the torrent client, what Sonarr may do
        AppState::setCredentials('rutracker.org', Env::TRACKER_USER, Env::TRACKER_PASS);
        $this->useClient($client);
        AppState::setSettings(array('sonarrReplaceTopic' => '1', 'sonarrReplaceTorrent' => '1', 'removeOnSeriesDelete' => '1'));

        // ---- first setup of Sonarr and a new series
        // Sonarr saves an indexer only if a search in it returns something
        Env::fake(array('torznab' => array('releases' => array(array('title' => 'Some.Show.S01E01.1080p.WEB-DL.H264-TM', 'topic' => 899)))));
        Sonarr::setup($client);
        $series = Sonarr::addSeries(self::TVDB);
        $name = str_replace(array('(', ')', ' '), array('', '', '.'), $series['title']);

        // ---- the tracker and the indexer: two releases of season 1, one of season 2, one single episode
        $releases = array(
            array('title' => $name.'.S01.1080p.WEB-DL.H264-TM', 'topic' => 801),
            array('title' => $name.'.S01.720p.WEB-DL.H264-TM', 'topic' => 802),
            array('title' => $name.'.S02.1080p.WEB-DL.H264-TM', 'topic' => 804),
            array('title' => $name.'.S02E01.1080p.WEB-DL.H264-TM', 'topic' => 805),
        );
        foreach (array(801, 802, 803, 804, 805) as $topicId)
            Env::setTopic($topicId, 'Кухня, тема '.$topicId, '01-Ноя-26 10:00', 1);
        Env::fake(array('torznab' => array('releases' => $releases)));
        $hash = function ($topicId) { return TorrentBuilder::infoHash($topicId, 1); };

        // ---- search and grab: the topic of the grabbed torrent is monitored
        $season1 = Sonarr::releases($series['id'], 1);
        $first = $this->found($season1, 801);
        $this->assertTrue($first['fullSeason'], 'Sonarr sees the release as a season pack');
        Sonarr::grab($first);

        $this->waitForTopics(array(801), 'the grabbed topic to be monitored');
        $this->assertInClient($client, $hash(801), 'the grabbed torrent');
        $topic = $this->topicOf(801);
        $this->assertSame($hash(801), strtolower($topic['hash']));
        $this->assertSame($series['id'], $topic['sonarrSeries']);
        $this->assertSame($series['title'], $topic['sonarrTitle']);
        $this->assertSame(array(1), $topic['sonarrSeasons']);

        // ---- another pack of the same season: the first topic and its torrent are replaced
        Sonarr::grab($this->found($season1, 802));

        $this->waitForTopics(array(802), 'the topic of season 1 to be replaced');
        Env::waitFor(function () use ($client, $hash) { return ! $this->has($client, $hash(801)); }, 'the replaced torrent to leave '.$client, 30);
        $this->assertInClient($client, $hash(802), 'the torrent of the new topic');

        // ---- a topic added by hand, linked to the series by the address of its page in Sonarr
        $added = Env::ui('topic.add', array('kind' => 'topic', 'url' => self::TOPIC.'803',
            'sonarrTitle' => 'http://sonarr.local:8989/series/'.$series['titleSlug'], 'sonarrSeasons' => '2'))['json'];
        $this->assertTrue($added['ok'], json_encode($added, JSON_UNESCAPED_UNICODE));
        Env::runEngine();
        $this->assertSame(array(), AppState::warningReasons());
        $this->assertInClient($client, $hash(803), 'the torrent of the hand-added topic');

        // a single episode of season 2 replaces nothing
        $season2 = Sonarr::releases($series['id'], 2);
        Sonarr::grab($this->found($season2, 805));
        $this->waitForTopics(array(802, 803, 805), 'the topic of the single episode to be monitored');
        $this->assertSame($series['id'], $this->topicOf(803)['sonarrSeries'], 'the hand-added topic is linked to the series by now');

        // the pack of season 2 replaces the hand-added topic and the single episode, with their torrents
        Sonarr::grab($this->found($season2, 804));
        $this->waitForTopics(array(802, 804), 'the hand-added topic of season 2 to be replaced');
        Env::waitFor(function () use ($client, $hash) { return ! $this->has($client, $hash(803)) && ! $this->has($client, $hash(805)); },
            'the replaced torrents to leave '.$client, 30);
        $this->assertInClient($client, $hash(802), 'season 1 stays');
        $this->assertInClient($client, $hash(804), 'the torrent of the season 2 pack');

        // ---- the series is deleted in Sonarr: its topics and torrents go
        Sonarr::deleteSeries($series['id']);
        $this->waitForTopics(array(), 'the topics of the deleted series to be removed');
        Env::waitFor(function () use ($client, $hash) { return ! $this->has($client, $hash(802)) && ! $this->has($client, $hash(804)); },
            'the torrents of the deleted series to leave '.$client, 30);
        $this->assertSame(array(), AppState::warningReasons());

        $this->removeFromClient($client, array());
    }
}
