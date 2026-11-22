<?php
// Deleting a topic, with and without removing the torrent (and its data) from qBittorrent.
final class TopicRemovalTest extends IntegrationTestCase
{
    private $id;
    private $hash;
    private $payload;

    // a tracked topic whose torrent is in the client and whose data is on disk
    private function downloadedTopic($topicId, $settings = array())
    {
        $this->loginToTracker();
        AppState::useQbittorrent($settings);
        Env::setTopic($topicId, 'Сериал', '21-Ноя-26 10:00', 1);
        $this->id = $this->trackTopic($topicId);
        $this->payload = $this->placePayload('qbt', $topicId, 1);
        Env::runEngine();
        $this->hash = TorrentBuilder::infoHash($topicId, 1);
        $this->assertTrue(Qbt::has($this->hash));
    }

    private function waitUntilGoneFromClient()
    {
        $hash = $this->hash;
        Env::waitFor(function () use ($hash) { return ! Qbt::has($hash); }, 'torrent to leave qBittorrent');
    }

    public function testDeleteTopicOnlyKeepsTorrentInClient()
    {
        $this->downloadedTopic(301);

        $response = Env::uiAction(array('action' => 'del', 'id' => $this->id, 'removeFromClient' => 0));

        $this->assertFalse($response['json']['error']);
        $this->assertNull(AppState::topic($this->id));
        $this->assertTrue(Qbt::has($this->hash));
        $this->assertFileExists($this->payload);
    }

    public function testDeleteFromClientKeepsDataByDefault()
    {
        $this->downloadedTopic(302);

        Env::uiAction(array('action' => 'del', 'id' => $this->id, 'removeFromClient' => 1));

        $this->assertNull(AppState::topic($this->id));
        $this->waitUntilGoneFromClient();
        sleep(1);
        $this->assertFileExists($this->payload, 'data stays on disk while the setting is off');
    }

    public function testDeleteFromClientRemovesDataWhenSettingIsOn()
    {
        $this->downloadedTopic(303, array('deleteFilesOnRemove' => '1'));

        Env::uiAction(array('action' => 'del', 'id' => $this->id, 'removeFromClient' => 1));

        $this->waitUntilGoneFromClient();
        $payload = $this->payload;
        Env::waitFor(function () use ($payload) { clearstatcache(); return ! file_exists($payload); }, 'data to be deleted');
        $this->assertNoWarnings();
    }

    public function testOldEpisodeSettingDoesNotDeleteDataOnRemoval()
    {
        // "delete files of old torrents" is about updates only
        $this->downloadedTopic(304, array('deleteOldFiles' => '1', 'deleteDistribution' => '1'));

        Env::uiAction(array('action' => 'del', 'id' => $this->id, 'removeFromClient' => 1));

        $this->waitUntilGoneFromClient();
        sleep(1);
        $this->assertFileExists($this->payload);
    }

    public function testApiDeleteLeavesClientAloneByDefault()
    {
        $this->downloadedTopic(305, array('deleteFilesOnRemove' => '1'));

        $response = Env::api('DELETE', 'torrents/'.$this->id);

        $this->assertSame(200, $response['status']);
        $this->assertNull(AppState::topic($this->id));
        $this->assertTrue(Qbt::has($this->hash));
        $this->assertFileExists($this->payload);
    }

    public function testApiDeleteCanRemoveTorrentAndData()
    {
        $this->downloadedTopic(306);

        $response = Env::api('DELETE', 'torrents/'.$this->id.'?removeFromClient=1&deleteData=1');

        $this->assertTrue($response['json']['ok'] ?? null, $response['body']);
        $this->waitUntilGoneFromClient();
        $payload = $this->payload;
        Env::waitFor(function () use ($payload) { clearstatcache(); return ! file_exists($payload); }, 'data to be deleted');
    }

    public function testApiDeleteCanOverrideSettingAndKeepData()
    {
        $this->downloadedTopic(307, array('deleteFilesOnRemove' => '1'));

        Env::api('DELETE', 'torrents/'.$this->id.'?removeFromClient=1&deleteData=0');

        $this->waitUntilGoneFromClient();
        sleep(1);
        $this->assertFileExists($this->payload);
    }
}
