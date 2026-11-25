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

    // A file can be valid JSON and wrong inside: what has an obvious value is put right
    public function testWrongValuesInsideAFileArePutRight()
    {
        $this->loginToTracker();
        $kept = $this->trackTopic(720);
        $torrents = json_decode(file_get_contents($this->dataFile('torrents.json')), true);
        $good = $torrents['topics'][0];
        $torrents['topics'] = array(
            'a' => $good,                                                          // the list became a map
            'b' => null,                                                           // not a topic at all
            'c' => 'oops',
            'd' => array('tracker' => 'rutracker.org', 'topicId' => 721),          // no number, no other fields, a number where text belongs
            'e' => array('id' => $good['id'], 'tracker' => 'rutracker.org', 'name' => array('x'), 'paused' => 'no', 'error' => 'yes',
                'updatedAt' => 12345, 'hash' => null, 'sonarrSeasons' => 'all', 'stopAfterDays' => 'abc', 'fromTheFuture' => array('kept' => true)),
        );
        unset($torrents['nextId']);
        $torrents['users'] = 'oops';
        file_put_contents($this->dataFile('torrents.json'), json_encode($torrents));

        $config = json_decode(file_get_contents($this->dataFile('config.json')), true);
        $config['settings']['httpTimeout'] = array('15');          // a list where a value belongs: the default is used
        $config['settings']['rss'] = 1;                             // a number where text belongs
        $config['trackers']['kinozal.me'] = 'oops';
        $config['trackers']['rutracker.org']['login'] = array('a');
        $config['notifications'] = 'oops';
        file_put_contents($this->dataFile('config.json'), json_encode($config));

        $state = json_decode(file_get_contents($this->dataFile('state.json')), true);
        $state['warnings'] = array('oops', array(), array('id' => 7, 'source' => 'rutracker.org', 'code' => 'cant_find_date', 'topicId' => $kept));
        $state['lastRun'] = array('x');
        $state['found'] = 'oops';
        file_put_contents($this->dataFile('state.json'), json_encode($state));

        Env::uiClose();
        $boot = Env::uiOpen();
        $this->assertSame(200, $boot['status'], $boot['body']);
        $overview = Env::ui('overview');
        $this->assertTrue($overview['json']['ok'], $overview['body']);
        $topics = $overview['json']['topics'];
        $this->assertCount(3, $topics, 'what is not a topic is left out');
        $this->assertCount(3, array_unique(array_column($topics, 'id')), 'every topic has a number of its own');
        $this->assertContains($kept, array_column($topics, 'id'), 'a topic keeps its number');
        $this->assertSame('15', $overview['json']['settings']['httpTimeout']);
        $this->assertCount(1, $overview['json']['warnings']);
        $this->assertTrue(Env::api('GET', 'torrents')['json']['ok']);
        $output = Env::runEngine();
        $this->assertStringNotContainsString('Warning', $output);
        $this->assertStringNotContainsString('Fatal', $output);
        $this->assertStringContainsString('Проверено тем:', $output);

        // the next save writes the file as it should be, and keeps what this version does not know
        $saved = AppState::topics();
        $this->assertCount(3, $saved);
        foreach ($saved as $topic)
        {
            $this->assertIsInt($topic['id']);
            $this->assertIsString($topic['name']);
            $this->assertIsBool($topic['paused']);
        }
    }

    // What cannot be guessed makes the file damaged: the program says which part, and offers its copy
    public function testASectionOfAWrongTypeIsDamageAndTheCopyCanBeBroughtBack()
    {
        $this->loginToTracker();
        $this->trackTopic(722);
        $this->trackTopic(723);       // the second save leaves a copy that holds the first topic
        $torrents = json_decode(file_get_contents($this->dataFile('torrents.json')), true);
        $torrents['topics'] = 'oops';
        file_put_contents($this->dataFile('torrents.json'), json_encode($torrents));

        Env::uiClose();
        $boot = Env::uiOpen();
        $this->assertSame(503, $boot['status']);
        $this->assertStringContainsString('Файл данных повреждён', $boot['json']['message']);
        $this->assertStringContainsString('(раздел topics)', $boot['json']['message']);
        $this->assertSame('torrents.json', $boot['json']['restore']['file']);
        $this->assertSame('bak', $boot['json']['restore']['copies'][0]['copy']);
        $this->assertSame(1, $boot['json']['restore']['copies'][0]['topics']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $boot['json']['restore']['time']);
        $this->assertStringContainsString('(раздел topics)', Env::runEngineExpectingFailure());

        $restored = Env::ui('data.restore');
        $this->assertTrue($restored['json']['ok'], $restored['body']);
        Env::uiClose();
        $this->assertSame(200, Env::uiOpen()['status']);
        $this->assertCount(1, AppState::topics(), 'the copy is the file as it was before the last save');
        $this->assertStringContainsString('"oops"', file_get_contents($this->dataFile('torrents.json.broken')), 'the damaged file is kept');
        $this->assertContains(true, array_map(function ($line) { return strpos($line, 'torrents.json был повреждён и возвращён из копии') !== false; }, AppState::journal('data')));

        // nothing is damaged any more: without naming a file and a copy there is nothing to bring back
        $again = Env::ui('data.restore');
        $this->assertSame(422, $again['status']);

        // the settings: a section of a wrong type, and no section at all
        foreach (array('"oops"', NULL) as $settings)
        {
            $config = json_decode(file_get_contents($this->dataFile('config.json')), true);
            Env::ui('settings.save', array('httpTimeout' => '21'));     // makes a copy
            if ($settings === NULL)
                unset($config['settings']);
            else
                $config['settings'] = 'oops';
            file_put_contents($this->dataFile('config.json'), json_encode($config));
            Env::uiClose();
            $boot = Env::uiOpen();
            $this->assertSame(503, $boot['status']);
            $this->assertStringContainsString('(раздел settings)', $boot['json']['message']);
            $this->assertSame('config.json', $boot['json']['restore']['file']);
            $this->assertTrue(Env::ui('data.restore')['json']['ok']);
            Env::uiClose();
            $this->assertSame(200, Env::uiOpen()['status']);
        }
    }

    // Three copies are kept: of the last save, of about a day ago and of about two days ago
    public function testCopiesOfADayAndOfTwoDaysAgoAreKeptAndCanBeBroughtBack()
    {
        $this->loginToTracker();
        $first = $this->trackTopic(730);
        $this->trackTopic(731);
        $bak = $this->dataFile('torrents.json.bak');
        $this->assertFileExists($bak);
        $this->assertFileDoesNotExist($bak.'.1', 'the first save of a day only leaves the copy of the last save');

        // the next save: there is no copy of the day yet, so the copy of the last save becomes it
        $this->trackTopic(732);
        $this->assertFileExists($bak.'.1');
        $this->assertCount(1, json_decode(file_get_contents($bak.'.1'), true)['topics'], 'it holds the file as it was two saves ago');
        // saves of the same day leave it alone
        $this->trackTopic(733);
        $this->assertCount(1, json_decode(file_get_contents($bak.'.1'), true)['topics']);
        $this->assertFileDoesNotExist($bak.'.2');

        // a day later the copy of the day becomes the copy of the day before
        touch($bak.'.1', time() - 90000);
        $this->trackTopic(734);
        $this->assertCount(1, json_decode(file_get_contents($bak.'.2'), true)['topics']);
        $this->assertCount(3, json_decode(file_get_contents($bak.'.1'), true)['topics']);
        $this->assertCount(4, json_decode(file_get_contents($bak), true)['topics']);
        foreach (array('', '.1', '.2') as $suffix)
            $this->assertSame(substr(sprintf('%o', fileperms($this->dataFile('torrents.json'))), -4), substr(sprintf('%o', fileperms($bak.$suffix)), -4));

        // the page «Состояние» lists them, the newest first, with the number of topics in each
        $copies = array_values(array_filter(Env::ui('data.copies')['json']['copies'], function ($c) { return $c['file'] == 'torrents.json'; }));
        $this->assertSame(array('bak', 'bak.1', 'bak.2'), array_column($copies, 'copy'));
        $this->assertSame(array(4, 3, 1), array_column($copies, 'topics'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $copies[0]['timeUtc']);

        // a mistake noticed later: the file is brought back to the copy of two days ago
        $this->assertCount(5, AppState::topics());
        $back = Env::ui('data.restore', array('file' => 'torrents.json', 'copy' => 'bak.2'));
        $this->assertTrue($back['json']['ok'], $back['body']);
        $topics = AppState::topics();
        $this->assertCount(1, $topics);
        $this->assertSame($first, $topics[0]['id']);
        $this->assertCount(5, json_decode(file_get_contents($this->dataFile('torrents.json.replaced')), true)['topics'], 'the file that was replaced is kept');
        $this->assertContains(true, array_map(function ($line) { return strpos($line, 'torrents.json возвращён из копии') !== false; }, AppState::journal('data')));
        $this->assertFalse(Env::ui('data.restore', array('file' => 'torrents.json', 'copy' => 'bak.9'))['json']['ok']);
        $this->assertFalse(Env::ui('data.restore', array('file' => 'state.json', 'copy' => 'bak'))['json']['ok']);

        // behind the password when nothing is damaged
        Env::ui('settings.password', array('password' => 'Copy-Secret-1', 'password2' => 'Copy-Secret-1'));
        Env::uiClose();
        Env::uiOpen();
        $this->assertSame(401, Env::ui('data.restore', array('file' => 'torrents.json', 'copy' => 'bak'))['status']);
        $this->assertSame(401, Env::ui('data.copies')['status']);
    }

    // The state holds nothing that cannot be found out again: a damaged one does not stop the program
    public function testDamagedStateFileIsStartedAnew()
    {
        $this->loginToTracker();
        $id = $this->trackTopic(724);
        file_put_contents($this->dataFile('state.json'), '{"version": 2, "cookies": {');

        Env::uiClose();
        $boot = Env::uiOpen();
        $this->assertSame(200, $boot['status'], $boot['body']);
        $this->assertFileExists($this->dataFile('state.json.broken'));
        $this->assertSame('{"version": 2, "cookies": {', file_get_contents($this->dataFile('state.json.broken')));
        $this->assertNotNull(AppState::topic($id), 'topics and settings are untouched');
        $lines = AppState::journal('data');
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('state.json был повреждён и начат заново', $lines[0]);
        Env::runEngine();
        $this->assertCount(1, AppState::journal('data'), 'said once');
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
        Env::waitFor(function () { return strpos((string)@file_get_contents('/tmp/engine.out'), 'Готово за') !== false; }, 'engine to finish');

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
        Env::ui('client.save', array('id' => 1, 'name' => 'qBittorrent', 'type' => 'qBittorrent', 'address' => Env::QBT, 'login' => 'tm', 'password' => ''));

        $config = json_decode(AppState::file('config.json'), true);
        $this->assertSame(array('login' => 'new-login', 'password' => 'Saved-Pass', 'passkey' => '', 'address' => ''), $config['trackers']['rutracker.org']);
        $this->assertSame('Saved-Client-Pass', $config['clients'][0]['password']);

        Env::ui('tracker.save', array('name' => 'rutracker.org', 'login' => 'new-login', 'password' => 'Changed-Pass'));
        $this->assertSame('Changed-Pass', json_decode(AppState::file('config.json'), true)['trackers']['rutracker.org']['password']);

        // a saved password can be removed on purpose
        Env::ui('client.save', array('id' => 1, 'name' => 'qBittorrent', 'type' => 'qBittorrent', 'address' => Env::QBT, 'login' => 'tm', 'clearPassword' => true));
        Env::ui('tracker.save', array('name' => 'rutracker.org', 'login' => 'new-login', 'clearPassword' => true));
        $config = json_decode(AppState::file('config.json'), true);
        $this->assertSame('', $config['clients'][0]['password']);
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

    // Data files written by a newer version of the program: this version works with them
    // and keeps everything it does not know.
    public function testFilesFromANewerVersionKeepWorkingAndKeepTheirUnknownData()
    {
        $this->loginToTracker();
        Env::setTopic(730, 'Сериал', '21-Ноя-26 10:00', 1);
        $id = $this->trackTopic(730);
        Env::runEngine();
        $this->assertNoWarnings();

        // what a later version could add: a section, a setting, fields of a topic, of an account and of a watched user
        $future = function ($name, $change) {
            $data = json_decode(file_get_contents($this->dataFile($name)), true);
            $data['version'] = 99;
            $data['futureSection'] = array('answer' => 42);
            $change($data);
            file_put_contents($this->dataFile($name), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        };
        $future('config.json', function (&$data) {
            $data['settings']['futureSetting'] = 'kept';
            $data['trackers']['rutracker.org']['futureField'] = 'kept';
        });
        $future('torrents.json', function (&$data) {
            $data['topics'][0]['futureField'] = array('kept' => true);
        });
        $future('state.json', function (&$data) {
            $data['cookies']['future.example'] = 'a=b';
        });

        // ordinary work: the interface, the engine, edits of everything that was extended
        $boot = Env::uiOpen();
        $this->assertSame(200, $boot['status'], $boot['body']);
        $this->assertCount(1, $boot['json']['topics']);
        $this->assertTrue(Env::ui('topic.save', array('id' => $id, 'kind' => 'topic', 'url' => self::TOPIC.'730', 'name' => 'Новое имя', 'category' => 'tv'))['json']['ok']);
        $this->assertTrue(Env::ui('tracker.save', array('name' => 'rutracker.org', 'login' => Env::TRACKER_USER, 'password' => Env::TRACKER_PASS))['json']['ok']);
        $this->assertTrue(Env::ui('settings.save', array('stopAfterDays' => '30'))['json']['ok']);
        Env::setTopic(730, 'Сериал', '22-Ноя-26 10:00', 2);
        $second = $this->trackTopicAfterSetting(731);
        Env::runEngine();
        $this->assertNoWarnings();
        $this->assertSame('2026-11-22 10:00:00', AppState::topic($id)['timestamp'], 'the engine works with the newer files');

        foreach (array('config.json', 'torrents.json', 'state.json') as $name)
        {
            $data = json_decode(file_get_contents($this->dataFile($name)), true);
            $this->assertSame(99, $data['version'], $name.': the format number is not lowered');
            $this->assertSame(array('answer' => 42), $data['futureSection'], $name);
        }
        $config = json_decode(file_get_contents($this->dataFile('config.json')), true);
        $this->assertSame('kept', $config['settings']['futureSetting']);
        $this->assertSame('kept', $config['trackers']['rutracker.org']['futureField']);
        $this->assertSame('30', $config['settings']['stopAfterDays']);
        $topics = json_decode(file_get_contents($this->dataFile('torrents.json')), true)['topics'];
        $this->assertCount(2, $topics);
        $this->assertSame(array('kept' => true), $topics[0]['futureField']);
        $this->assertSame('tv', $topics[0]['category']);
        $this->assertSame('a=b', json_decode(file_get_contents($this->dataFile('state.json')), true)['cookies']['future.example']);
        $this->assertNotNull($second);
    }

    private function trackTopicAfterSetting($topicId)
    {
        Env::setTopic($topicId, 'Ещё одна', '20-Ноя-26 10:00', 1);
        return $this->trackTopic($topicId);
    }

    public function testFilesThatNeedANewerProgramAreRefusedUntouched()
    {
        $this->loginToTracker();
        $this->trackTopic(732);
        $file = $this->dataFile('torrents.json');
        $data = json_decode(file_get_contents($file), true);
        $data['version'] = 99;
        $data['compatible'] = 98;
        file_put_contents($file, json_encode($data));
        $before = file_get_contents($file);

        $boot = Env::uiOpen();
        $this->assertSame(503, $boot['status']);
        $this->assertStringContainsString('более новой версией', $boot['body']);
        Env::runEngineExpectingFailure();
        $this->assertSame($before, file_get_contents($file));
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

    // By default the data files are closed to other users; the dataFileMode setting opens them.
    public function testAccessModeOfTheDataFilesCanBeSet()
    {
        $mode = function ($name) { clearstatcache(); return substr(sprintf('%o', fileperms($this->dataFile($name))), -4); };
        AppState::setSettings(array('userAgent' => 'one'));
        AppState::setSettings(array('userAgent' => 'two'));   // makes config.json.bak
        $default = $mode('config.json');
        $this->assertContains($default, array('0600', '0660'));

        $saved = Env::ui('settings.save', array('dataFileMode' => '640'));
        $this->assertTrue($saved['json']['ok'], $saved['body']);
        foreach (array('config.json', 'config.json.bak', 'state.json', 'torrents.json') as $name)
            if (is_file($this->dataFile($name)))
                $this->assertSame('0640', $mode($name), $name.' right after saving the setting');

        // files written later, by the engine too, get the same mode
        Env::runEngine();
        Env::ui('settings.save', array('userAgent' => 'three'));
        foreach (array('config.json', 'config.json.bak', 'state.json') as $name)
            $this->assertSame('0640', $mode($name), $name.' after later writes');

        // a leading zero is accepted; the owner must keep access
        $this->assertTrue(Env::ui('settings.save', array('dataFileMode' => '0644'))['json']['ok']);
        $this->assertSame('0644', $mode('config.json'));
        foreach (array('444', '64', '888', 'rw-r--r--', '1644') as $bad)
            $this->assertFalse(Env::ui('settings.save', array('dataFileMode' => $bad))['json']['ok'], $bad);
        $this->assertSame('0644', AppState::setting('dataFileMode'));

        // empty: back to the default
        $this->assertTrue(Env::ui('settings.save', array('dataFileMode' => ''))['json']['ok']);
        foreach (array('config.json', 'config.json.bak', 'state.json') as $name)
            $this->assertSame($default, $mode($name), $name.' back to the default');
    }
}
