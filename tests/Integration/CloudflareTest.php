<?php
// The tracker answers with a Cloudflare challenge; TorrentMonitor must go through
// the configured solver (FlareSolverr / Byparr API, faked here).
final class CloudflareTest extends IntegrationTestCase
{
    public function testTopicIsUpdatedThroughTheSolver()
    {
        $this->loginToTracker();
        AppState::useQbittorrent();
        AppState::setSettings(array('flaresolverrUrl' => Env::SOLVER));
        Env::setTopic(201, 'За Cloudflare', '11-Мар-26 09:15', 1);
        $id = $this->trackTopic(201);
        Env::fake(array('rutracker' => array('cloudflare' => true)));

        Env::runEngine();

        $solved = Env::fakeRequests('/solver/v1');
        $this->assertNotEmpty($solved, 'solver was used');
        // the solver only opens pages; the login POST goes directly with the clearance cookie
        // (Byparr loses POST data, so the login must not depend on the solver's request.post)
        $commands = array_map(function ($r) { return json_decode($r['body'], true)['cmd']; }, $solved);
        $this->assertNotContains('request.post', $commands);
        $this->assertCount(1, $solved, 'one solution serves all requests of the run');
        $logins = Env::fakeRequests('/forum/login.php');
        $login = end($logins);
        $this->assertStringContainsString('cf_clearance=fake-clearance', $login['cookie']);
        $this->assertStringContainsString('FakeSolver', $login['userAgent']);

        $topic = AppState::topic($id);
        $this->assertSame('2026-03-11 09:15:00', $topic['timestamp']);
        $this->assertSame('За Cloudflare', $topic['name']);
        $this->assertTrue(Qbt::has(TorrentBuilder::infoHash(201, 1)));
        $this->assertNoWarnings();

        // the torrent itself is downloaded directly, with the clearance cookie and the solver's user agent
        $download = Env::fakeRequests('/forum/dl.php');
        $last = end($download);
        $this->assertStringContainsString('cf_clearance=fake-clearance', $last['cookie']);
        $this->assertStringContainsString('FakeSolver', $last['userAgent']);
    }

    public function testWithoutSolverTheChallengeIsReported()
    {
        $this->loginToTracker();
        Env::setTopic(202, 'За Cloudflare', '11-Мар-26 09:15', 1);
        $id = $this->trackTopic(202);
        Env::fake(array('rutracker' => array('cloudflare' => true)));

        Env::runEngine();

        $this->assertNotEmpty(AppState::warnings());
        $this->assertSame('rutracker.org', AppState::warnings()[0]['where']);
        $this->assertFileDoesNotExist('/app/torrents/[rutracker.org]_202.torrent');
        $this->assertEmpty(Env::fakeRequests('/solver/v1'));
    }
}
