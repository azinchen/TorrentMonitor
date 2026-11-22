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
}
