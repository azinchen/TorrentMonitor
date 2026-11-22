<?php
// Behaviour introduced with the rewritten core: run lock, partial and dry runs, stopping
// the checks of dead topics, connectivity check, closed web root, torrent files over HTTP.
final class CoreFeaturesTest extends IntegrationTestCase
{
    private function engine($arguments = '')
    {
        return Env::sh('cd /app && php engine.php '.$arguments.' 2>&1');
    }

    private function setTopicFields($id, $fields)
    {
        require_once TM_ROOT.'/src/bootstrap.php';
        \TorrentMonitor\Data\Topics::update($id, $fields);
    }

    public function testSecondRunIsSkippedWhileOneIsRunning()
    {
        $this->loginToTracker();
        Env::setTopic(801, 'Тема', '01-Ноя-26 10:00', 1);
        $id = $this->trackTopic(801);

        // another process holds the run lock
        $holder = proc_open(array(PHP_BINARY, '-r', '$f = fopen(getenv("TM_DATA_DIR")."/engine.lock", "c"); flock($f, LOCK_EX); echo "locked\n"; sleep(20);'),
            array(1 => array('pipe', 'w')), $pipes);
        fgets($pipes[1]);
        try
        {
            $output = Env::runEngineExpectingFailure();
        }
        finally
        {
            proc_terminate($holder);
            proc_close($holder);
        }

        $this->assertStringContainsString('уже выполняется', $output);
        $this->assertSame('', AppState::topic($id)['timestamp']);
        $this->assertEmpty(Env::fakeRequests('/forum/'));

        $this->engine();
        $this->assertSame('2026-11-01 10:00:00', AppState::topic($id)['timestamp'], 'the lock is free again');
    }

    public function testDryRunChangesNothing()
    {
        $this->loginToTracker();
        AppState::useQbittorrent();
        Env::setTopic(802, 'Тема', '01-Ноя-26 10:00', 1);
        $id = $this->trackTopic(802);

        $output = $this->engine('--dry-run');

        $this->assertStringContainsString('Найдено обновление', $output);
        $this->assertSame('', AppState::topic($id)['timestamp']);
        $this->assertFileDoesNotExist('/app/torrents/[rutracker.org]_802.torrent');
        $this->assertFalse(Qbt::has(TorrentBuilder::infoHash(802, 1)));
        $this->assertEmpty(Env::fakeRequests('/forum/dl.php'), 'the torrent file is not downloaded');
    }

    public function testOnlyTheChosenTopicIsChecked()
    {
        $this->loginToTracker();
        Env::setTopic(803, 'Первая', '01-Ноя-26 10:00', 1);
        Env::setTopic(804, 'Вторая', '01-Ноя-26 10:00', 1);
        $first = $this->trackTopic(803);
        $second = $this->trackTopic(804);

        $this->engine('--topic='.$second);
        $this->assertSame('', AppState::topic($first)['timestamp']);
        $this->assertSame('2026-11-01 10:00:00', AppState::topic($second)['timestamp']);

        $this->engine('--tracker=kinozal.me');
        $this->assertSame('', AppState::topic($first)['timestamp']);

        $this->engine('--tracker=rutracker.org');
        $this->assertSame('2026-11-01 10:00:00', AppState::topic($first)['timestamp']);
    }

    public function testTopicWithoutUpdatesStopsBeingChecked()
    {
        $this->loginToTracker();
        Env::setTopic(805, 'Заброшенная', '01-Ноя-26 10:00', 1);
        Env::setTopic(806, 'Живая', '01-Ноя-26 10:00', 1);
        $old = $this->trackTopic(805);
        $live = $this->trackTopic(806);
        $this->engine();
        $longAgo = date('Y-m-d H:i:s', time() - 40 * 86400);
        $this->setTopicFields($old, array('lastActivityAt' => $longAgo, 'addedAt' => $longAgo));
        Env::setTopic(805, 'Заброшенная', '02-Ноя-26 10:00', 2);
        Env::setTopic(806, 'Живая', '02-Ноя-26 10:00', 2);

        // by default topics are checked forever
        $this->engine();
        $this->assertSame('2026-11-02 10:00:00', AppState::topic($old)['timestamp']);

        $this->setTopicFields($old, array('lastActivityAt' => $longAgo));
        AppState::setSettings(array('stopAfterDays' => '30'));
        Env::setTopic(805, 'Заброшенная', '03-Ноя-26 10:00', 3);
        Env::setTopic(806, 'Живая', '03-Ноя-26 10:00', 3);
        Env::fakeRequests();
        $output = $this->engine();

        $this->assertStringContainsString('проверка прекращена', $output);
        $this->assertSame('2026-11-02 10:00:00', AppState::topic($old)['timestamp']);
        $this->assertSame('2026-11-03 10:00:00', AppState::topic($live)['timestamp']);
        $this->assertTrue(Env::api('GET', 'torrents/'.$old)['json']['data']['stopped']);
        $this->assertFalse(Env::api('GET', 'torrents/'.$live)['json']['data']['stopped']);

        // the topic's own setting wins over the global one; asking for the topic by number checks it anyway
        $this->setTopicFields($old, array('stopAfterDays' => 0));
        $this->engine();
        $this->assertSame('2026-11-03 10:00:00', AppState::topic($old)['timestamp']);
        $this->assertNoWarnings();
    }

    public function testWithoutInternetTrackersAreNotTouched()
    {
        $this->loginToTracker();
        Env::setTopic(807, 'Тема', '01-Ноя-26 10:00', 1);
        $id = $this->trackTopic(807);
        file_put_contents('/app/config.php', "\nConfig::write('connectivity.url', 'http://127.0.0.1:1/');\n", FILE_APPEND);

        $output = $this->engine();
        $this->engine();

        $this->assertStringContainsString('Нет подключения к интернету', $output);
        $this->assertSame(array('system:no_internet'), AppState::warningReasons(), 'one warning, not one per tracker or run');
        $this->assertSame('', AppState::topic($id)['timestamp']);
        $this->assertSame(0, AppState::topic($id)['error'] ? 1 : 0);
        $this->assertEmpty(Env::fakeRequests('/forum/'));

        Env::sh('/src/tests/env/app/sync.sh');
        $this->engine();
        $this->assertNoWarnings();
        $this->assertSame('2026-11-01 10:00:00', AppState::topic($id)['timestamp']);
    }

    public function testOnlyThePublicFolderIsServed()
    {
        $this->loginToTracker();
        file_put_contents('/app/data-probe.txt', 'secret');
        foreach (array('/config.php', '/version.txt', '/engine.php', '/src/App.php', '/action.php', '/include/settings.php', '/src/Web/page.html', '/data/config.json',
            '/tools/convert-db.php', '/data-probe.txt', '/../config.php', '/include/../../config.php', '/torrents/../config.php') as $path)
        {
            $response = Http::get(Env::APP.$path);
            $this->assertSame(404, $response['status'], $path);
            $this->assertStringNotContainsString('Config::write', $response['body'], $path);
        }
        unlink('/app/data-probe.txt');
        $this->assertSame(200, Http::get(Env::APP.'/assets/app.css')['status']);
        $this->assertSame(200, Http::get(Env::APP.'/')['status']);
    }

    public function testSavedTorrentFilesAreAvailableOverHttp()
    {
        $this->loginToTracker();
        Env::setTopic(808, 'Тема', '01-Ноя-26 10:00', 1);
        $this->trackTopic(808);
        $this->engine();

        $file = Http::get(Env::APP.'/torrents/'.rawurlencode('[rutracker.org]_808.torrent'));
        $this->assertSame(200, $file['status']);
        $this->assertSame(TorrentBuilder::torrent(808, 1, self::TOPIC.'808'), $file['body']);
        $this->assertSame(404, Http::get(Env::APP.'/torrents/missing.torrent')['status']);

        $feed = Http::get(Env::APP.'/rss');
        $this->assertSame(200, $feed['status']);
        $xml = simplexml_load_string($feed['body']);
        $this->assertSame('[rutracker.org]_808', (string)$xml->channel->item[0]->title);
        $this->assertStringEndsWith('/torrents/'.rawurlencode('[rutracker.org]_808.torrent'), (string)$xml->channel->item[0]->link);

        AppState::setSettings(array('rss' => '0'));
        $this->assertSame(404, Http::get(Env::APP.'/rss')['status']);
    }

    public function testSolverWhoseCookiesDoNotWorkDirectlyIsStillUsedForPages()
    {
        // the solver sits behind another IP address: its clearance cookie is useless for direct requests,
        // so pages and the login go through the solver's browser (FlareSolverr handles POST)
        $this->loginToTracker();
        AppState::setSettings(array('flaresolverrUrl' => Env::SOLVER));
        Env::setTopic(811, 'За строгим Cloudflare', '11-Мар-26 09:15', 1);
        $id = $this->trackTopic(811);
        Env::fake(array('rutracker' => array('cloudflare' => true, 'cloudflareStrict' => true)));

        $this->engine();

        $commands = array_map(function ($r) { return json_decode($r['body'], true)['cmd']; }, Env::fakeRequests('/solver/v1'));
        $this->assertContains('request.post', $commands, 'the login went through the solver as a POST');
        // the page was read, but a torrent file cannot be fetched through a browser
        $this->assertSame(array('rutracker.org:torrent_file_fail'), AppState::warningReasons());
        $this->assertSame('', AppState::topic($id)['timestamp']);
    }
}
