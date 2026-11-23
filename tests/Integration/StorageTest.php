<?php
// Data is kept in JSON files in the data directory (TM_DATA_DIR in the test stack).
final class StorageTest extends IntegrationTestCase
{
    private function dataFile($name)
    {
        return getenv('TM_DATA_DIR').'/'.$name;
    }

    public function testFreshInstallationWorksWithoutAnyFiles()
    {
        Env::sh('/src/tests/env/app/reset-data.sh');

        $page = Http::get(Env::APP.'/');
        $boot = Env::uiOpen();

        $this->assertSame(200, $page['status']);
        $this->assertTrue($boot['json']['setup'], 'a new installation starts with the setup');
        $this->assertSame(array(), glob(getenv('TM_DATA_DIR').'/*.json'), 'opening the page creates nothing');
    }

    public function testFilesArePrivateAndReadable()
    {
        $this->loginToTracker();
        $this->trackTopic(701);

        foreach (array('config.json', 'torrents.json') as $name)
        {
            $mode = substr(sprintf('%o', fileperms($this->dataFile($name))), -4);
            $this->assertContains($mode, array('0600', '0660'), $name.' is not readable by other users');
            $this->assertMatchesRegularExpression('/\n    "version": \d+/', file_get_contents($this->dataFile($name)));
        }
        $this->assertFileExists($this->dataFile('.htaccess'));
    }

    public function testTopicGetsDatesWhenAddedAndUpdated()
    {
        $this->loginToTracker();
        Env::setTopic(702, 'Сериал', '21-Ноя-26 10:00', 1);
        $id = $this->trackTopic(702);
        $added = AppState::topic($id);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $added['addedAt']);
        $this->assertSame('', $added['lastActivityAt']);

        Env::runEngine();

        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', AppState::topic($id)['lastActivityAt']);
        $first = AppState::topic($id)['lastActivityAt'];

        sleep(1);
        Env::runEngine();
        $this->assertSame($first, AppState::topic($id)['lastActivityAt'], 'a check without changes is not an update');
    }

    public function testDamagedFileStopsTheApplicationWithAMessage()
    {
        $this->loginToTracker();
        $this->trackTopic(703);
        $this->trackTopic(704);
        file_put_contents($this->dataFile('torrents.json'), '{"version": 1, "torrents": [');

        // the page itself opens; its first request gets the reason why nothing works
        $page = Env::uiOpen();
        $page['body'] = (string)$page['json']['message'];
        $api = Env::api('GET', 'torrents');
        $engine = Env::runEngineExpectingFailure();

        $this->assertSame(503, $page['status']);
        $this->assertStringContainsString('Файл данных повреждён', $page['body']);
        $this->assertStringContainsString('torrents.json.bak', $page['body']);
        $this->assertSame(503, $api['status']);
        $this->assertFalse($api['json']['ok']);
        $this->assertStringContainsString('Файл данных повреждён', $engine);
        $this->assertSame('{"version": 1, "torrents": [', file_get_contents($this->dataFile('torrents.json')), 'the damaged file is not overwritten');
    }

    public function testWebAndEngineWritingTogetherLoseNothing()
    {
        $this->loginToTracker();
        for ($t = 710; $t < 716; $t++)
            Env::setTopic($t, 'Тема '.$t, '21-Ноя-26 10:00', 1);
        for ($t = 710; $t < 713; $t++)
            $this->trackTopic($t);

        // the engine updates topics while the API adds more
        exec('cd /app && php engine.php > /tmp/engine.out 2>&1 &');
        for ($t = 713; $t < 716; $t++)
            Env::api('POST', 'torrents', array('json' => array('url' => self::TOPIC.$t)));
        Env::waitFor(function () { return strpos((string)@file_get_contents('/tmp/engine.out'), 'Удаление старых torrent-файлов') !== false; }, 'engine to finish');

        $topics = AppState::topics();
        $this->assertCount(6, $topics);
        $this->assertCount(6, array_unique(array_column($topics, 'id')), 'ids are unique');
        foreach (array_slice($topics, 0, 3) as $topic)
            $this->assertSame('2026-11-21 10:00:00', $topic['timestamp']);
    }

    public function testStoredPasswordsAreNotSentToTheBrowser()
    {
        AppState::setCredentials('rutracker.org', 'visible-login', 'Secret-Tracker-Pass');
        AppState::useQbittorrent(array('torrentPassword' => 'Secret-Client-Pass'));
        AppState::setSettings(array('password' => password_hash('Interface-Pass', PASSWORD_BCRYPT)));
        Http::post(Env::FAKE.'/__control/reset');

        $pages = Http::get(Env::APP.'/')['body'].Env::uiOpen()['body'].Env::ui('overview')['body'].Env::ui('health')['body'];

        $this->assertStringContainsString('visible-login', $pages);
        $this->assertStringNotContainsString('Secret-Tracker-Pass', $pages);
        $this->assertStringNotContainsString('Secret-Client-Pass', $pages);
        $this->assertStringNotContainsString(AppState::setting('password'), $pages, 'password hash stays on the server');
    }

    public function testEmptyPasswordFieldKeepsTheSavedPassword()
    {
        AppState::setCredentials('rutracker.org', 'old-login', 'Saved-Pass');
        AppState::useQbittorrent(array('torrentPassword' => 'Saved-Client-Pass'));

        Env::ui('tracker.save', array('name' => 'rutracker.org', 'login' => 'new-login', 'password' => ''));
        Env::ui('settings.save', array('useTorrent' => true, 'torrentClient' => 'qBittorrent', 'torrentAddress' => Env::QBT, 'torrentLogin' => 'tm', 'torrentPassword' => ''));

        $config = json_decode(AppState::file('config.json'), true);
        $this->assertSame(array('login' => 'new-login', 'password' => 'Saved-Pass', 'passkey' => '', 'address' => ''), $config['trackers']['rutracker.org']);
        $this->assertSame('Saved-Client-Pass', $config['settings']['torrentPassword']);

        Env::ui('tracker.save', array('name' => 'rutracker.org', 'login' => 'new-login', 'password' => 'Changed-Pass'));
        $this->assertSame('Changed-Pass', json_decode(AppState::file('config.json'), true)['trackers']['rutracker.org']['password']);

        // a saved password can be removed on purpose
        Env::ui('settings.save', array('clearTorrentPassword' => true));
        Env::ui('tracker.save', array('name' => 'rutracker.org', 'login' => 'new-login', 'clearPassword' => true));
        $config = json_decode(AppState::file('config.json'), true);
        $this->assertSame('', $config['settings']['torrentPassword']);
        $this->assertSame('', $config['trackers']['rutracker.org']['password']);
    }

    public function testForgettingATrackerRemovesCredentialsAndSessionOnly()
    {
        AppState::setCredentials('rutracker.org', 'old-login', 'Saved-Pass');
        Env::ui('tracker.save', array('name' => 'rutracker.org', 'login' => 'old-login', 'address' => 'rutracker.example', 'cookie' => 'bb_session=abc', 'proxy' => 'off'));
        $this->assertSame('bb_session=abc', json_decode(AppState::file('state.json'), true)['cookies']['rutracker.org']);

        $forgot = Env::ui('tracker.forget', array('name' => 'rutracker.org'))['json'];
        $this->assertTrue($forgot['ok']);
        $config = json_decode(AppState::file('config.json'), true);
        $this->assertSame(array('login' => '', 'password' => '', 'passkey' => '', 'address' => 'rutracker.example'), $config['trackers']['rutracker.org']);
        $this->assertSame(array('use' => 'no'), $config['trackerProxy']['rutracker.org'], 'the proxy choice stays');
        $this->assertSame('', json_decode(AppState::file('state.json'), true)['cookies']['rutracker.org']);

        $this->assertFalse(Env::ui('tracker.forget', array('name' => 'nowhere.example'))['json']['ok']);
    }

    public function testProxyForOneTrackerIsSavedAndUsed()
    {
        $this->loginToTracker();
        Env::setTopic(720, 'Сериал', '21-Ноя-26 10:00', 1);
        $id = $this->trackTopic(720);

        // nothing listens on this address, so the tracker becomes unreachable
        $saved = Env::ui('tracker.save', array('name' => 'rutracker.org', 'login' => Env::TRACKER_USER, 'proxy' => 'own', 'proxyAddress' => '127.0.0.1:1', 'proxyType' => 'HTTP'));
        $this->assertTrue($saved['json']['ok'], $saved['body']);
        $this->assertSame(array('rutracker.org' => array('use' => 'yes', 'address' => '127.0.0.1:1', 'type' => 'HTTP')), json_decode(AppState::file('config.json'), true)['trackerProxy']);
        Env::runEngine();
        $this->assertSame(array('rutracker.org:proxy_fail'), array_values(array_unique(AppState::warningReasons())), 'the proxy is named as the cause, not the tracker');
        $this->assertSame('', AppState::topic($id)['timestamp']);
        $this->assertEmpty(Env::fakeRequests('/forum/'), 'no request reached the tracker directly');
        $health = json_encode(Env::ui('health')['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('прокси http://127.0.0.1:1 не отвечает', $health);

        // "off" switches the proxy off for this tracker
        Env::ui('tracker.save', array('name' => 'rutracker.org', 'login' => Env::TRACKER_USER, 'proxy' => 'off'));
        $this->assertSame(array('rutracker.org' => array('use' => 'no')), json_decode(AppState::file('config.json'), true)['trackerProxy']);
        Env::runEngine();
        $this->assertSame('2026-11-21 10:00:00', AppState::topic($id)['timestamp']);
    }

    public function testUnreachableTrackerIsReportedAsSuch()
    {
        // booktracker.org resolves to this container, where nothing listens on port 443
        $hosts = file_get_contents('/etc/hosts');
        file_put_contents('/etc/hosts', $hosts."127.0.0.1 booktracker.org\n");
        try
        {
            AppState::setCredentials('booktracker.org', Env::TRACKER_USER, Env::TRACKER_PASS);
            $created = Env::api('POST', 'torrents', array('json' => array('url' => 'https://booktracker.org/viewtopic.php?t=721', 'name' => 'Книга')));
            $this->assertSame(201, $created['status'], $created['body']);

            Env::runEngine();

            $this->assertSame(array('booktracker.org:tracker_unreachable'), array_values(array_unique(AppState::warningReasons())));
            $health = json_encode(Env::ui('health')['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertStringContainsString('booktracker.org (https://booktracker.org) недоступен: сайт не принимает соединения', $health);
        }
        finally
        {
            file_put_contents('/etc/hosts', $hosts);
        }
    }

    public function testHealthPageChecksTheDataDirectory()
    {
        $page = Env::ui('health');
        $page['body'] = json_encode($page['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('Каталог данных /data доступен для записи', $page['body']);
        $this->assertStringContainsString('Подключение к интернету есть', $page['body']);
        $this->assertStringNotContainsString('PDO', $page['body']);
    }

    public function testLastStartIsRemembered()
    {
        Env::runEngine();
        $this->assertMatchesRegularExpression('/"lastRun": "\d{4}-\d\d-\d\d \d\d:\d\d:\d\d"/', AppState::file('state.json'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', Env::uiOpen()['json']['status']['lastRun']);
    }
}
