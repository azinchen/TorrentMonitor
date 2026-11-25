<?php
// The same flow with Transmission as the torrent client.
final class TransmissionTest extends IntegrationTestCase
{
    public function testTopicIsSentToTransmissionAndReplacedOnUpdate()
    {
        $this->loginToTracker();
        AppState::useTransmission();
        Env::setTopic(401, 'Сериал', '01-Янв-26 12:00', 1);
        $id = $this->trackTopic(401);

        Env::runEngine();

        $hash = TorrentBuilder::infoHash(401, 1);
        $this->assertTrue(Tr::has($hash));
        $this->assertSame(Env::DOWNLOADS.'/transmission', rtrim(Tr::torrents()[$hash]['downloadDir'], '/'));
        $this->assertSame($hash, strtolower(AppState::topic($id)['hash']));
        $this->assertNoWarnings();

        // the page of topics is told what the client is doing with the torrent
        $status = Env::ui('client.status', array('id' => 1))['json'];
        $this->assertTrue($status['supported'] && $status['answered'], json_encode($status));
        $torrent = $status['torrents'][(string)$id];
        $this->assertContains($torrent['state'], array('downloading', 'seeding', 'stopped', 'queued', 'checking', 'error'), json_encode($torrent));
        $this->assertGreaterThanOrEqual(0, $torrent['progress']);
        $this->assertLessThanOrEqual(100, $torrent['progress']);
        $this->assertGreaterThan(0, $torrent['size']);

        Env::setTopic(401, 'Сериал', '08-Янв-26 19:45', 2);
        Env::runEngine();

        $this->assertTrue(Tr::has(TorrentBuilder::infoHash(401, 2)));
        $this->assertFalse(Tr::has($hash), 'old torrent is removed');
        $this->assertNoWarnings();
    }

    public function testRemovalKeepsOrDeletesDataAsConfigured()
    {
        $this->loginToTracker();
        AppState::useTransmission();
        Env::setTopic(402, 'Первый', '01-Янв-26 12:00', 1);
        Env::setTopic(403, 'Второй', '01-Янв-26 12:00', 1);
        $keepId = $this->trackTopic(402);
        $deleteId = $this->trackTopic(403);
        $keepFile = $this->placePayload('transmission', 402, 1);
        $deleteFile = $this->placePayload('transmission', 403, 1);
        Env::runEngine();
        $this->assertCount(2, Tr::torrents());

        Env::api('DELETE', 'torrents/'.$keepId.'?removeFromClient=1&deleteData=0');
        Env::api('DELETE', 'torrents/'.$deleteId.'?removeFromClient=1&deleteData=1');

        Env::waitFor(function () { return count(Tr::torrents()) == 0; }, 'torrents to leave Transmission');
        Env::waitFor(function () use ($deleteFile) { clearstatcache(); return ! file_exists($deleteFile); }, 'data to be deleted');
        $this->assertFileExists($keepFile);
        $this->assertNoWarnings();
    }

    public function testWrongPasswordIsReported()
    {
        $this->loginToTracker();
        AppState::useTransmission(array('torrentPassword' => 'wrong'));
        Env::setTopic(404, 'Сериал', '01-Янв-26 12:00', 1);
        $this->trackTopic(404);

        Env::runEngine();

        $this->assertContains('Transmission:log_passwd', AppState::warningReasons());
        $this->assertCount(0, Tr::torrents());
    }
}
