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
}
