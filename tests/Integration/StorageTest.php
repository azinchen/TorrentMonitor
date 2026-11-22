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

        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('name="password"', $page['body'], 'a new installation asks for the password');
        $this->assertFileDoesNotExist('/app/config.php.bak');
    }

    public function testDefaultPasswordOpensANewInstallation()
    {
        Env::sh('/src/tests/env/app/reset-data.sh');
        $jar = tempnam(sys_get_temp_dir(), 'jar');
        $page = Http::get(Env::APP.'/', array('jar' => $jar));
        preg_match('/name="csrf-token" content="([^"]+)"/', $page['body'], $m);

        $login = Http::post(Env::APP.'/action.php', array('action' => 'enter', 'password' => 'torrentmonitor', 'remember' => 'false', 'csrf_token' => $m[1]), array('jar' => $jar));

        $this->assertFalse($login['json']['error'], $login['body']);
        $this->assertStringStartsWith('$2y$', AppState::setting('password'), 'the old default hash is replaced by a modern one at first login');
    }

    public function testFilesArePrivateAndReadable()
    {
        $this->loginToTracker();
        $this->trackTopic(701);

        foreach (array('config.json', 'torrents.json') as $name)
        {
            $mode = substr(sprintf('%o', fileperms($this->dataFile($name))), -4);
            $this->assertContains($mode, array('0600', '0660'), $name.' is not readable by other users');
            $this->assertStringContainsString("\n    \"version\": 1", file_get_contents($this->dataFile($name)));
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

        $page = Http::get(Env::APP.'/');
        $api = Env::api('GET', 'torrents');
        $engine = Env::runEngine();

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
        Http::post(Env::FAKE.'/__control/reset');

        $pages = Env::uiOpen()['body'].Env::uiGet('/include/settings.php')['body'].Env::uiGet('/include/credentials.php')['body'];

        $this->assertStringContainsString('visible-login', $pages);
        $this->assertStringNotContainsString('Secret-Tracker-Pass', $pages);
        $this->assertStringNotContainsString('Secret-Client-Pass', $pages);
        $this->assertStringNotContainsString(AppState::setting('password'), $pages, 'password hash stays on the server');
    }

    public function testEmptyPasswordFieldKeepsTheSavedPassword()
    {
        AppState::setCredentials('rutracker.org', 'old-login', 'Saved-Pass');
        AppState::useQbittorrent(array('torrentPassword' => 'Saved-Client-Pass'));

        Env::uiAction(array('action' => 'update_credentials', 'id' => 1, 'log' => 'new-login', 'pass' => '', 'passkey' => ''));
        Env::uiAction(array(
            'action' => 'update_torrent', 'useTorrent' => 'true', 'torrentClient' => 'qBittorrent', 'torrentAddress' => Env::QBT,
            'torrentLogin' => 'tm', 'torrentPassword' => '', 'pathToDownload' => '/downloads/qbt/', 'deleteDistribution' => 'false',
            'deleteOldFiles' => 'false', 'deleteFilesOnRemove' => 'false', 'qbitCategory' => '',
        ));

        $config = json_decode(AppState::file('config.json'), true);
        $this->assertSame(array('login' => 'new-login', 'password' => 'Saved-Pass', 'passkey' => ''), $config['trackers']['rutracker.org']);
        $this->assertSame('Saved-Client-Pass', $config['settings']['torrentPassword']);

        Env::uiAction(array('action' => 'update_credentials', 'id' => 1, 'log' => 'new-login', 'pass' => 'Changed-Pass', 'passkey' => ''));
        $this->assertSame('Changed-Pass', json_decode(AppState::file('config.json'), true)['trackers']['rutracker.org']['password']);
    }

    public function testProxyForOneTrackerIsSavedAndUsed()
    {
        $this->loginToTracker();
        Env::setTopic(720, 'Сериал', '21-Ноя-26 10:00', 1);
        $id = $this->trackTopic(720);

        // nothing listens on this address, so the tracker becomes unreachable
        $saved = Env::uiAction(array('action' => 'update_extended', 'httpTimeout' => 5, 'flaresolverrUrl' => '',
            'settings' => '{"rutracker.org": {"use": "yes", "type": "HTTP", "address": "127.0.0.1:1"}}'));
        $this->assertFalse($saved['json']['error'], $saved['body']);
        Env::runEngine();
        $this->assertNotEmpty(AppState::warnings());
        $this->assertSame('0000-00-00 00:00:00', AppState::topic($id)['timestamp']);
        $this->assertEmpty(Env::fakeRequests('/forum/'), 'no request reached the tracker directly');

        // "no" switches the proxy off for this tracker
        Env::uiAction(array('action' => 'update_extended', 'httpTimeout' => 5, 'flaresolverrUrl' => '',
            'settings' => '{"rutracker.org": {"use": "no"}}'));
        Env::runEngine();
        $this->assertSame('2026-11-21 10:00:00', AppState::topic($id)['timestamp']);
    }

    public function testBrokenProxyJsonIsRejected()
    {
        Env::uiAction(array('action' => 'update_extended', 'httpTimeout' => 5, 'flaresolverrUrl' => '',
            'settings' => '{"rutracker.org": {"use": "yes"}}'));

        $response = Env::uiAction(array('action' => 'update_extended', 'httpTimeout' => 5, 'flaresolverrUrl' => '', 'settings' => '{not json'));

        $this->assertTrue($response['json']['error']);
        $this->assertSame(array('rutracker.org' => array('use' => 'yes')), json_decode(AppState::file('config.json'), true)['trackerProxy']);
    }

    public function testHealthPageChecksTheDataDirectory()
    {
        $page = Env::uiGet('/include/check.php');

        $this->assertStringContainsString('Запись в каталог данных', $page['body']);
        $this->assertStringContainsString('Файлы данных читаются', $page['body']);
        $this->assertStringNotContainsString('encryption.key', $page['body']);
        $this->assertStringNotContainsString('PDO', $page['body']);
    }

    public function testLastStartIsRemembered()
    {
        Env::runEngine();
        $this->assertMatchesRegularExpression('/"lastStart": "\d\d-\d\d-\d{4} \d\d:\d\d:\d\d"/', AppState::file('state.json'));
        $this->assertStringNotContainsString('Ещё не производилась', Env::uiOpen()['body']);
    }
}
