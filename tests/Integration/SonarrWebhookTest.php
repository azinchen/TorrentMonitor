<?php
// Sonarr "On Grab" webhook: Sonarr sends the torrent hash, TorrentMonitor reads the
// topic address from the torrent comment in qBittorrent and starts tracking it.
final class SonarrWebhookTest extends IntegrationTestCase
{
    private function grab($hash, $query = '')
    {
        return Http::postJson(Env::APP.'/api/sonarr?token='.Env::API_TOKEN.$query, array(
            'eventType'  => 'Grab',
            'downloadId' => strtoupper($hash),
            'series'     => array('title' => 'Сериал из Sonarr'),
        ));
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
        $this->assertNoWarnings();
    }

    public function testGrabOfTrackedTopicDoesNotDuplicateIt()
    {
        $hash = $this->torrentFromSonarr(602);
        $this->grab($hash);

        $this->grab($hash);

        $this->assertCount(1, AppState::topics());
    }

    public function testGrabOfUnknownTorrentIsReported()
    {
        $this->loginToTracker();
        AppState::useQbittorrent();

        $response = $this->grab(str_repeat('ab', 20));

        $this->assertFalse($response['json']['ok']);
        $this->assertCount(0, AppState::topics());
        $this->assertNotEmpty(AppState::warnings());
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
