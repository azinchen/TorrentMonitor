<?php
// Sonarr "On Grab" webhook: Sonarr sends the torrent hash, TorrentMonitor reads the
// topic address from the torrent comment in qBittorrent and starts tracking it.
final class SonarrWebhookTest extends IntegrationTestCase
{
    private function event($body, $query = '')
    {
        return Http::postJson(Env::APP.'/api/sonarr?token='.Env::API_TOKEN.$query, $body);
    }

    // Sonarr gets its answer at once; the topic appears a moment later
    private function grab($hash, $query = '', $wait = true)
    {
        $before = count(AppState::topics());
        $started = microtime(true);
        $response = $this->event(array(
            'eventType'  => 'Grab',
            'downloadId' => strtoupper($hash),
            'series'     => array('id' => 77, 'title' => 'Сериал из Sonarr'),
        ), $query);
        $this->assertLessThan(2.0, microtime(true) - $started, 'the webhook answers without waiting for the torrent client');
        if ($wait)
            Env::waitFor(function () use ($before) { return count(AppState::topics()) > $before; }, 'topic added by the webhook');
        return $response;
    }

    // a torrent that Sonarr put into qBittorrent
    private function torrentFromSonarr($topicId)
    {
        $this->loginToTracker();
        AppState::useQbittorrent();
        Qbt::addFile(TorrentBuilder::torrent($topicId, 1, self::TOPIC.$topicId), Env::DOWNLOADS.'/qbt', 'tv-sonarr');
        $hash = TorrentBuilder::infoHash($topicId, 1);
        Env::waitFor(function () use ($hash) { return Qbt::has($hash); }, 'torrent in qBittorrent');
        return $hash;
    }

    public function testConnectionTest()
    {
        $response = Http::postJson(Env::APP.'/api/sonarr?token='.Env::API_TOKEN, array('eventType' => 'Test'));
        $this->assertTrue($response['json']['ok']);
        $this->assertNoWarnings();
    }

    public function testGrabStartsTrackingTheTopic()
    {
        $hash = $this->torrentFromSonarr(601);

        $response = $this->grab($hash, '&category=tv-sonarr');

        $this->assertTrue($response['json']['ok'], $response['body']);
        $topics = AppState::topics();
        $this->assertCount(1, $topics);
        $this->assertSame('rutracker.org', $topics[0]['tracker']);
        $this->assertSame('601', $topics[0]['torrent_id']);
        $this->assertSame('tv-sonarr', $topics[0]['category']);
        $this->assertSame(strtolower($hash), $topics[0]['hash'], 'the torrent Sonarr added belongs to the topic');
        $this->assertNoWarnings();
    }

    public function testCategoryAndFolderOfTheTorrentAreKept()
    {
        $hash = $this->torrentFromSonarr(606);

        $this->grab($hash);

        $topic = AppState::topics()[0];
        $this->assertSame('tv-sonarr', $topic['category']);
        $this->assertSame(Env::DOWNLOADS.'/qbt', rtrim($topic['path'], '/'));

        // the first check finds the same torrent: it stays in the client where Sonarr put it
        Env::setTopic(606, 'Сериал', '01-Ноя-26 10:00', 1);
        Env::runEngine();
        $this->assertTrue(Qbt::has($hash));
        $this->assertNoWarnings();

        // a new version of the torrent goes to the same folder and category
        Env::setTopic(606, 'Сериал', '02-Ноя-26 10:00', 2);
        Env::runEngine();
        $new = TorrentBuilder::infoHash(606, 2);
        Env::waitFor(function () use ($new) { return Qbt::has($new); }, 'updated torrent in qBittorrent');
        $this->assertFalse(Qbt::has($hash));
        $this->assertSame($new, AppState::topics()[0]['hash']);
        $this->assertNoWarnings();
    }

    public function testOtherEventsAreAcceptedAndIgnored()
    {
        foreach (array('Download', 'Rename', 'Health', 'SeriesAdd', 'ApplicationUpdate') as $type)
        {
            $response = $this->event(array('eventType' => $type, 'isUpgrade' => true));
            $this->assertSame(200, $response['status'], $type);
            $this->assertTrue($response['json']['ok'], $type);
        }
        $this->assertNoWarnings();
    }

    public function testSeriesDeleteRemovesItsTopicsWhenEnabled()
    {
        $hash = $this->torrentFromSonarr(607);
        $this->grab($hash);
        $delete = array('eventType' => 'SeriesDelete', 'series' => array('id' => 77), 'deletedFiles' => true);

        $this->event($delete);
        $this->assertCount(1, AppState::topics(), 'off by default');

        AppState::setSettings(array('removeOnSeriesDelete' => '1'));
        $this->event(array('eventType' => 'SeriesDelete', 'series' => array('id' => 78)));
        $this->assertCount(1, AppState::topics(), 'another series');

        $response = $this->event($delete);
        $this->assertTrue($response['json']['ok']);
        $this->assertCount(0, AppState::topics());
        Env::waitFor(function () use ($hash) { return ! Qbt::has($hash); }, 'torrent removed from qBittorrent');
    }

    public function testGrabOfTrackedTopicDoesNotDuplicateIt()
    {
        $hash = $this->torrentFromSonarr(602);
        $this->grab($hash);

        $response = $this->grab($hash, '', false);
        sleep(2);

        $this->assertTrue($response['json']['ok'], 'a topic that is already tracked is not an error');
        $this->assertCount(1, AppState::topics());
        $this->assertNoWarnings();
    }

    public function testGrabOfUnknownTorrentIsReported()
    {
        $this->loginToTracker();
        AppState::useQbittorrent();

        $this->grab(str_repeat('ab', 20), '', false);

        Env::waitFor(function () { return AppState::warnings(); }, 'warning about the unknown torrent', 40);
        $this->assertSame('api', AppState::warnings()[0]['where']);
        $this->assertCount(0, AppState::topics());
    }

    public function testGrabWithoutHashIsRejected()
    {
        $response = Http::postJson(Env::APP.'/api/sonarr?token='.Env::API_TOKEN, array('eventType' => 'Grab'));
        $this->assertFalse($response['json']['ok']);
        $this->assertCount(0, AppState::topics());
    }

    public function testWebhookNeedsTheToken()
    {
        $response = Http::postJson(Env::APP.'/api/sonarr', array('eventType' => 'Test'));
        $this->assertSame(401, $response['status']);
    }

    // ---- a season pack replaces the older topic of the same season

    // Sonarr grabbed the torrent of $topicId: a season pack unless $episodes is 1
    private function grabSeason($topicId, $hash, $season, $episodes = 3, $seriesId = 77, $releaseType = null)
    {
        $list = array();
        for ($n = 1; $n <= $episodes; $n++)
            $list[] = array('id' => $season * 100 + $n, 'episodeNumber' => $n, 'seasonNumber' => $season, 'title' => 'Серия '.$n);
        $body = array('eventType' => 'Grab', 'downloadId' => strtoupper($hash), 'series' => array('id' => $seriesId, 'title' => 'Сериал из Sonarr'), 'episodes' => $list);
        if ($releaseType !== null)
            $body['release'] = array('releaseType' => $releaseType);
        $this->assertTrue($this->event($body)['json']['ok']);
        Env::waitFor(function () use ($topicId) { return in_array((string)$topicId, $this->trackedTopics(), true); }, 'topic '.$topicId.' added by the webhook');
    }

    private function trackedTopics()
    {
        $ids = array_map(function ($t) { return (string)$t['torrent_id']; }, AppState::topics());
        sort($ids);
        return $ids;
    }

    private function waitForTopics(array $expected)
    {
        $expected = array_map('strval', $expected);
        sort($expected);
        try
        {
            Env::waitFor(function () use ($expected) { return $this->trackedTopics() === $expected; }, 'topics '.implode(', ', $expected), 15);
        }
        catch (RuntimeException $e)
        {
            $this->assertSame($expected, $this->trackedTopics());
        }
    }

    public function testSeasonPackReplacesTheOlderTopicAndItsTorrent()
    {
        $first = $this->torrentFromSonarr(621);
        $this->grabSeason(621, $first, 1);
        $second = $this->torrentFromSonarr(622);
        $this->grabSeason(622, $second, 1);
        sleep(2);
        $this->assertSame(array('621', '622'), $this->trackedTopics(), 'off by default');

        AppState::setSettings(array('sonarrReplaceTopic' => '1', 'sonarrReplaceTorrent' => '1'));
        $keptFile = $this->placePayload('qbt', 621, 1);
        $third = $this->torrentFromSonarr(623);
        $this->grabSeason(623, $third, 1);

        $this->waitForTopics(array(623));
        Env::waitFor(function () use ($first, $second) { return ! Qbt::has($first) && ! Qbt::has($second); }, 'old torrents removed from qBittorrent');
        $this->assertTrue(Qbt::has($third));
        $this->assertFileExists($keptFile, 'files stay unless deleting files is turned on');
        $this->assertNoWarnings();
    }

    public function testOnlyTheTopicIsRemovedWhenTheTorrentSettingIsOff()
    {
        AppState::setSettings(array('sonarrReplaceTopic' => '1'));
        $first = $this->torrentFromSonarr(624);
        $this->grabSeason(624, $first, 2);
        $second = $this->torrentFromSonarr(625);
        $this->grabSeason(625, $second, 2);

        $this->waitForTopics(array(625));
        sleep(2);
        $this->assertTrue(Qbt::has($first), 'the old torrent stays in the client');

        // the torrent setting alone does nothing
        AppState::setSettings(array('sonarrReplaceTopic' => '0', 'sonarrReplaceTorrent' => '1'));
        $third = $this->torrentFromSonarr(626);
        $this->grabSeason(626, $third, 2);
        sleep(2);
        $this->assertSame(array('625', '626'), $this->trackedTopics());
        $this->assertTrue(Qbt::has($second));
    }

    public function testWhatIsNeverReplaced()
    {
        AppState::setSettings(array('sonarrReplaceTopic' => '1', 'sonarrReplaceTorrent' => '1'));
        $season1 = $this->torrentFromSonarr(631);
        $this->grabSeason(631, $season1, 1);
        // a topic of the same show added by hand
        Env::setTopic(632, 'Тот же сериал', '01-Ноя-26 10:00', 1);
        $this->trackTopic(632);

        // one episode of the season, by the number of episodes and by the release type
        $this->grabSeason(633, $this->torrentFromSonarr(633), 1, 1);
        $this->grabSeason(634, $this->torrentFromSonarr(634), 1, 2, 77, 'MultiEpisode');
        // another season, another series
        $this->grabSeason(635, $this->torrentFromSonarr(635), 2);
        $this->grabSeason(636, $this->torrentFromSonarr(636), 1, 3, 78);
        sleep(2);

        $this->assertSame(array('631', '632', '633', '634', '635', '636'), $this->trackedTopics());
        $this->assertTrue(Qbt::has($season1));

        // a season pack by the release type, even with one episode listed: season 1 topics of series 77 go, the rest stay
        $this->grabSeason(637, $this->torrentFromSonarr(637), 1, 1, 77, 'SeasonPack');
        $this->waitForTopics(array(632, 635, 636, 637));
        $this->assertNoWarnings();
    }

    public function testFilesOfTheOldTorrentFollowTheDeleteFilesSetting()
    {
        AppState::setSettings(array('sonarrReplaceTopic' => '1', 'sonarrReplaceTorrent' => '1', 'deleteFilesOnRemove' => '1'));
        $oldFile = $this->placePayload('qbt', 641, 1);
        $old = $this->torrentFromSonarr(641);
        $this->grabSeason(641, $old, 1);
        $newFile = $this->placePayload('qbt', 642, 1);
        $new = $this->torrentFromSonarr(642);
        $this->grabSeason(642, $new, 1);

        $this->waitForTopics(array(642));
        Env::waitFor(function () use ($old) { return ! Qbt::has($old); }, 'old torrent removed');
        Env::waitFor(function () use ($oldFile) { clearstatcache(); return ! file_exists($oldFile); }, 'files of the old torrent deleted');
        $this->assertFileExists($newFile);

        // a different torrent of another topic with the very same file: deleting the files of the
        // old torrent would delete the files of the new one, so they are kept
        $payload = TorrentBuilder::payload(642, 1);
        $pieces = '';
        foreach (str_split($payload, 32768) as $piece)
            $pieces .= sha1($piece, true);
        $info = array('length' => strlen($payload), 'name' => TorrentBuilder::fileName(642, 1), 'piece length' => 32768, 'pieces' => $pieces, 'private' => 1);
        $same = sha1(TorrentBuilder::bencode($info));
        Qbt::addFile(TorrentBuilder::bencode(array('announce' => 'http://tracker.invalid/announce', 'comment' => self::TOPIC.'643', 'info' => $info)), Env::DOWNLOADS.'/qbt', 'tv-sonarr');
        Env::waitFor(function () use ($same) { return Qbt::has($same); }, 'torrent in qBittorrent');
        $this->grabSeason(643, $same, 1);

        $this->waitForTopics(array(643));
        Env::waitFor(function () use ($new) { return ! Qbt::has($new); }, 'old torrent removed');
        sleep(2);
        clearstatcache();
        $this->assertFileExists($newFile, 'the file both torrents share is kept');
        $this->assertTrue(Qbt::has($same));
    }

    // ---- topics added by hand and linked to a Sonarr series by its title and season

    public function testTopicLinkedByHandIsReplacedBySeasonPackOfItsSeries()
    {
        AppState::setSettings(array('sonarrReplaceTopic' => '1', 'sonarrReplaceTorrent' => '1'));
        $this->loginToTracker();
        AppState::useQbittorrent();
        Env::setTopic(651, 'Сезон 1, своя тема', '01-Ноя-26 10:00', 1);
        Env::setTopic(652, 'Сезон 2, своя тема', '01-Ноя-26 10:00', 1);
        Env::setTopic(653, 'Без связи', '01-Ноя-26 10:00', 1);
        // the title is written a little differently than in Sonarr: case, spaces and punctuation do not matter
        $this->assertTrue(Env::ui('topic.add', array('kind' => 'topic', 'url' => self::TOPIC.'651', 'sonarrTitle' => ' сериал  ИЗ sonarr! ', 'sonarrSeasons' => '1'))['json']['ok']);
        $this->assertTrue(Env::ui('topic.add', array('kind' => 'topic', 'url' => self::TOPIC.'652', 'sonarrTitle' => 'Сериал из Sonarr', 'sonarrSeasons' => '2, 3'))['json']['ok']);
        $this->assertTrue(Env::ui('topic.add', array('kind' => 'topic', 'url' => self::TOPIC.'653'))['json']['ok']);
        Env::runEngine();
        $own = array();
        foreach (AppState::topics() as $topic)
            $own[$topic['torrent_id']] = $topic['hash'];
        $this->assertTrue(Qbt::has($own['651']));

        // a single episode of season 1 replaces nothing
        $this->grabSeason(654, $this->torrentFromSonarr(654), 1, 1);
        sleep(2);
        $this->assertSame(array('651', '652', '653', '654'), $this->trackedTopics());

        $this->grabSeason(655, $this->torrentFromSonarr(655), 1);

        $this->waitForTopics(array(652, 653, 655));
        Env::waitFor(function () use ($own) { return ! Qbt::has($own['651']); }, 'torrent of the replaced topic removed');
        $this->assertTrue(Qbt::has($own['652']), 'another season stays');
        $this->assertTrue(Qbt::has($own['653']), 'a topic without a link stays');
        $this->assertNoWarnings();
    }

    public function testLinkIsCheckedAndCanBeChangedAndRemoved()
    {
        $this->loginToTracker();
        Env::setTopic(661, 'Тема', '01-Ноя-26 10:00', 1);
        $add = array('kind' => 'topic', 'url' => self::TOPIC.'661');

        $noSeason = Env::ui('topic.add', $add + array('sonarrTitle' => 'Show'))['json'];
        $this->assertFalse($noSeason['ok']);
        $this->assertStringContainsString('сезон', $noSeason['message']);
        $this->assertFalse(Env::ui('topic.add', $add + array('sonarrSeasons' => '1'))['json']['ok'], 'a season without a series');
        $this->assertFalse(Env::ui('topic.add', $add + array('sonarrTitle' => 'Show', 'sonarrSeasons' => 'first'))['json']['ok']);
        $this->assertCount(0, AppState::topics());

        $added = Env::ui('topic.add', $add + array('sonarrTitle' => 'Show', 'sonarrSeasons' => '2,1 2'))['json'];
        $this->assertTrue($added['ok']);
        $this->assertSame(array('Show', array(1, 2)), array($added['topics'][0]['sonarrTitle'], $added['topics'][0]['sonarrSeasons']));
        $this->assertSame(array('Show'), $added['sonarrTitles'], 'known series are suggested in the form');
        $id = $added['topics'][0]['id'];

        // Sonarr names the series: the topic is found by its number from now on
        $this->event(array('eventType' => 'Grab', 'downloadId' => str_repeat('cd', 20), 'series' => array('id' => 91, 'title' => 'show')));
        Env::waitFor(function () use ($id) { return AppState::topic($id)['sonarrSeries'] === 91; }, 'the topic to get the series number');

        $this->assertSame('show', AppState::topic($id)['sonarrTitle'], 'the title is kept as Sonarr writes it');

        // the address of the series page in Sonarr works as well, and the year may be left out
        Env::setTopic(662, 'Кухня', '01-Ноя-26 10:00', 1);
        Env::setTopic(663, 'Мажор', '01-Ноя-26 10:00', 1);
        $this->assertTrue(Env::ui('topic.add', array('kind' => 'topic', 'url' => self::TOPIC.'662', 'sonarrTitle' => 'http://sonarr.local:8989/series/the-kitchen-2012', 'sonarrSeasons' => '1'))['json']['ok']);
        $this->assertTrue(Env::ui('topic.add', array('kind' => 'topic', 'url' => self::TOPIC.'663', 'sonarrTitle' => 'Silver Spoon', 'sonarrSeasons' => '1'))['json']['ok']);
        $this->event(array('eventType' => 'Rename', 'series' => array('id' => 92, 'title' => 'The Kitchen (2012)', 'titleSlug' => 'the-kitchen-2012')));
        $this->event(array('eventType' => 'Rename', 'series' => array('id' => 93, 'title' => 'Silver Spoon (2014)', 'titleSlug' => 'silver-spoon-2014')));
        $linked = array();
        foreach (AppState::topics() as $topic)
            $linked[$topic['torrent_id']] = array($topic['sonarrSeries'], $topic['sonarrTitle']);
        $this->assertSame(array(92, 'The Kitchen (2012)'), $linked['662']);
        $this->assertSame(array(93, 'Silver Spoon (2014)'), $linked['663']);

        // an edit that does not touch the link keeps it
        Env::ui('topic.pause', array('id' => $id, 'paused' => true));
        $this->assertSame(91, AppState::topic($id)['sonarrSeries']);
        // another series: the number of the previous one is dropped
        $edit = array('id' => $id, 'kind' => 'topic', 'url' => self::TOPIC.'661', 'name' => 'Тема');
        $this->assertTrue(Env::ui('topic.save', $edit + array('sonarrTitle' => 'Other Show', 'sonarrSeasons' => '4'))['json']['ok']);
        $topic = AppState::topic($id);
        $this->assertSame(array(null, 'Other Show', array(4)), array($topic['sonarrSeries'], $topic['sonarrTitle'], $topic['sonarrSeasons']));
        // both fields emptied: no link
        $this->assertTrue(Env::ui('topic.save', $edit + array('sonarrTitle' => '', 'sonarrSeasons' => ''))['json']['ok']);
        $topic = AppState::topic($id);
        $this->assertSame(array(null, '', array()), array($topic['sonarrSeries'], $topic['sonarrTitle'], $topic['sonarrSeasons']));
    }

    public function testLinkThroughTheApiAndSeriesDeleteOfALinkedTopic()
    {
        AppState::setSettings(array('removeOnSeriesDelete' => '1'));
        $this->loginToTracker();
        Env::setTopic(671, 'Тема', '01-Ноя-26 10:00', 1);
        Env::setTopic(672, 'Другая', '01-Ноя-26 10:00', 1);

        $bad = Env::api('POST', 'torrents', array('json' => array('url' => self::TOPIC.'671', 'sonarrTitle' => 'Show')));
        $this->assertSame(422, $bad['status']);
        $created = Env::api('POST', 'torrents', array('json' => array('url' => self::TOPIC.'671', 'sonarrTitle' => 'Show', 'sonarrSeasons' => array(3))));
        $this->assertSame(201, $created['status'], $created['body']);
        Env::api('POST', 'torrents', array('json' => array('url' => self::TOPIC.'672')));
        $listed = Env::api('GET', 'torrents')['json']['data'];
        $byTopic = array();
        foreach ($listed as $topic)
            $byTopic[$topic['topicId']] = array($topic['sonarrTitle'], $topic['sonarrSeasons']);
        $this->assertSame(array('671' => array('Show', array(3)), '672' => array('', array())), $byTopic);

        // the series is deleted in Sonarr: the linked topic goes, the other one stays
        $this->event(array('eventType' => 'SeriesDelete', 'series' => array('id' => 55, 'title' => 'Show'), 'deletedFiles' => false));
        $this->assertSame(array('672'), $this->trackedTopics());
    }
}
