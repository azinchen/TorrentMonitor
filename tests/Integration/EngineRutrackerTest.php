<?php
// The engine against the fake rutracker.org: login, topic check, torrent download,
// hand-over to qBittorrent.
final class EngineRutrackerTest extends IntegrationTestCase
{
    public function testNewTopicIsDownloadedAndSentToClient()
    {
        $this->loginToTracker();
        AppState::useQbittorrent();
        Env::setTopic(101, 'Сериал / Serial (Сезон 1) [WEB-DL 1080p]', '21-Ноя-26 10:00', 1);
        $id = $this->trackTopic(101);

        Env::runEngine();

        $topic = AppState::topic($id);
        $hash = TorrentBuilder::infoHash(101, 1);
        $this->assertSame('Сериал / Serial (Сезон 1) [WEB-DL 1080p]', $topic['name'], 'topic name is taken from the page title');
        $this->assertSame('2026-11-21 10:00:00', $topic['timestamp']);
        $this->assertSame($hash, strtolower($topic['hash']), 'hash reported by the client is stored');
        $this->assertFileExists('/app/torrents/[rutracker.org]_101.torrent');
        $this->assertTrue(Qbt::has($hash), 'torrent is in qBittorrent');
        $this->assertSame(Env::DOWNLOADS.'/qbt', rtrim(Qbt::torrents()[$hash]['save_path'], '/'));
        $this->assertNoWarnings();
        $this->assertCount(1, Env::fakeRequests('/forum/login.php'), 'one login');
    }

    public function testUnchangedTopicIsLeftAlone()
    {
        $this->loginToTracker();
        AppState::useQbittorrent();
        Env::setTopic(102, 'Фильм', '20-Окт-26 08:30', 1);
        $id = $this->trackTopic(102);
        Env::runEngine();
        $before = AppState::topic($id);

        Env::runEngine();

        $this->assertSame($before, AppState::topic($id));
        $this->assertCount(1, Env::fakeRequests('/forum/dl.php'), 'torrent is downloaded once');
        $this->assertCount(1, Env::fakeRequests('/forum/login.php'), 'session cookie is reused on the second run');
        $this->assertCount(1, Qbt::torrents());
        $this->assertNoWarnings();
    }

    public function testUpdatedTopicReplacesTorrentInClient()
    {
        $this->loginToTracker();
        AppState::useQbittorrent();
        Env::setTopic(103, 'Сериал', '01-Янв-26 12:00', 1);
        $id = $this->trackTopic(103);
        Env::runEngine();
        $this->assertTrue(Qbt::has(TorrentBuilder::infoHash(103, 1)));

        // a new episode: the tracker re-registers the torrent
        Env::setTopic(103, 'Сериал', '08-Янв-26 19:45', 2);
        Env::runEngine();

        $newHash = TorrentBuilder::infoHash(103, 2);
        $topic = AppState::topic($id);
        $this->assertSame('2026-01-08 19:45:00', $topic['timestamp']);
        $this->assertSame($newHash, strtolower($topic['hash']));
        $this->assertTrue(Qbt::has($newHash), 'new torrent is in the client');
        $this->assertFalse(Qbt::has(TorrentBuilder::infoHash(103, 1)), 'old torrent is removed from the client');
        $this->assertNoWarnings();
    }

    public function testWithoutClientTheTorrentFileIsOnlySaved()
    {
        $this->loginToTracker();
        Env::setTopic(104, 'Книга', '05-Май-26 01:02', 1);
        $id = $this->trackTopic(104);

        Env::runEngine();

        $this->assertFileExists('/app/torrents/[rutracker.org]_104.torrent');
        $this->assertSame(
            TorrentBuilder::torrent(104, 1, self::TOPIC.'104'),
            file_get_contents('/app/torrents/[rutracker.org]_104.torrent')
        );
        $this->assertSame('2026-05-05 01:02:00', AppState::topic($id)['timestamp']);
        $this->assertCount(0, Qbt::torrents());
        $this->assertNoWarnings();
    }

    public function testWrongPasswordIsReported()
    {
        AppState::setCredentials('rutracker.org', Env::TRACKER_USER, 'wrong-password');
        Env::setTopic(105, 'Фильм', '20-Окт-26 08:30', 1);
        $id = $this->trackTopic(105);

        Env::runEngine();

        $this->assertContains('rutracker.org:credential_wrong', AppState::warningReasons());
        $this->assertCount(0, Env::fakeRequests('/forum/dl.php'));
        $this->assertFileDoesNotExist('/app/torrents/[rutracker.org]_105.torrent');
        $this->assertSame('', (string)AppState::topic($id)['hash']);
    }

    public function testUnreachableTrackerIsReported()
    {
        $this->loginToTracker();
        Env::setTopic(106, 'Фильм', '20-Окт-26 08:30', 1);
        $this->trackTopic(106);
        Env::fake(array('rutracker' => array('down' => true)));

        Env::runEngine();

        // today the reason is "cant_find_cookie", which is misleading for a server error;
        // the test only requires that the failure is reported against the tracker
        $this->assertCount(1, AppState::warnings());
        $this->assertSame('rutracker.org', AppState::warnings()[0]['where']);
        $this->assertFileDoesNotExist('/app/torrents/[rutracker.org]_106.torrent');
    }

    public function testTopicWithoutTorrentIsReported()
    {
        $this->loginToTracker();
        // the topic is tracked but the tracker has no such topic
        $id = $this->trackTopic(107);

        Env::runEngine();

        $this->assertContains('rutracker.org:cant_find_date', AppState::warningReasons());
        $this->assertSame((string)$id, (string)AppState::warnings()[0]['t_id'], 'warning points at the topic');
    }

    public function testClientFailureKeepsTorrentForTheNextRun()
    {
        $this->loginToTracker();
        AppState::useQbittorrent(array('torrentPassword' => 'wrong-client-password'));
        Env::setTopic(108, 'Фильм', '20-Окт-26 08:30', 1);
        $this->trackTopic(108);

        Env::runEngine();

        $this->assertContains('qBittorrent:log_passwd', AppState::warningReasons());
        $this->assertFileExists('/app/torrents/[rutracker.org]_108.torrent', 'torrent file is kept');
        $this->assertCount(0, Qbt::torrents());

        // once the client works again, the next run adds the saved torrent
        AppState::useQbittorrent();
        Env::runEngine();

        $this->assertTrue(Qbt::has(TorrentBuilder::infoHash(108, 1)));
    }

    // A run loads the pages a hundred topics at a time and saves what it finds once per part.
    // Every topic must still be handled, whether it has an update, has none or fails.
    public function testManyTopicsAreCheckedInParts()
    {
        $this->loginToTracker();
        AppState::setSettings(array('useTorrent' => '0'));
        $count = 230;
        $topics = array();
        for ($i = 0; $i < $count; $i++)
        {
            $topicId = 60000 + $i;
            \TorrentMonitor\Data\Topics::add(array('tracker' => 'rutracker.org', 'topicId' => (string)$topicId, 'name' => 'Тема '.$topicId));
            $topics[(string)$topicId] = array('title' => 'Тема '.$topicId, 'date' => '21-Ноя-26 10:00', 'revision' => 1);
        }
        Env::fake(array('rutracker' => array('topics' => $topics)));

        // first run: every topic is new
        $output = Env::runEngine();
        $stored = AppState::topics();
        $this->assertCount($count, $stored);
        foreach ($stored as $topic)
            $this->assertSame(array('2026-11-21 10:00:00', 0), array($topic['timestamp'], (int)$topic['error']), 'topic '.$topic['torrent_id']);
        $this->assertNoWarnings();
        $this->assertSame($count, substr_count($output, 'обновлён'), $output);

        // second run: nothing changed, nothing is downloaded
        array_map('unlink', glob('/app/torrents/*.torrent'));
        Env::runEngine();
        $this->assertSame(array(), glob('/app/torrents/*.torrent'));
        $this->assertNoWarnings();

        // every topic fails at once: all are marked, all errors are recorded, one notification is due
        $gone = array();
        foreach (array_keys($topics) as $topicId)
            $gone[$topicId] = null;
        Env::fake(array('rutracker' => array('topics' => $gone)));
        Env::runEngine();
        foreach (AppState::topics() as $topic)
            $this->assertSame(1, (int)$topic['error'], 'topic '.$topic['torrent_id']);
        $this->assertCount($count, AppState::warnings());

        // and they recover together
        Env::fake(array('rutracker' => array('topics' => $topics)));
        Env::runEngine();
        foreach (AppState::topics() as $topic)
            $this->assertSame(0, (int)$topic['error'], 'topic '.$topic['torrent_id']);
        $this->assertNoWarnings();
    }
}
