<?php
// The web interface: one page that talks to the server through POST /ui/<action>.
// These tests send the same requests the page sends.
final class WebInterfaceTest extends IntegrationTestCase
{
    private function fresh()
    {
        Env::sh('/src/tests/env/app/reset-data.sh');
        Env::uiClose();
    }

    public function testPageAndItsFilesAreServed()
    {
        $page = Http::get(Env::APP.'/');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('x-data="app"', $page['body']);
        // everything the page loads comes from this server
        preg_match_all('~\s(?:src|href)="([^"#{\']+)"~', $page['body'], $m);
        $this->assertGreaterThanOrEqual(4, count($m[1]));
        foreach ($m[1] as $url)
        {
            $this->assertDoesNotMatchRegularExpression('~^(https?:)?//~', $url, 'no files from other sites: '.$url);
            $this->assertSame(200, Http::get(Env::APP.'/'.preg_replace('/\?.*/', '', $url))['status'], $url);
        }
        $this->assertSame(200, Http::get(Env::APP.'/img/rutracker.org.ico')['status']);
    }

    public function testSetupOfANewInstallation()
    {
        $this->fresh();
        $boot = Env::uiOpen()['json'];
        $this->assertTrue($boot['setup']);
        $this->assertFalse($boot['in']);
        $this->assertSame('http://127.0.0.1/', $boot['suggestedAddress']);
        $this->assertSame(401, Env::ui('overview')['status'], 'nothing is available before the setup');
        $this->assertFalse(Env::ui('login', array('password' => 'torrentmonitor'))['json']['ok'], 'there is no default password');
        $this->assertFalse(Env::ui('setup', array('password' => 'abc'))['json']['ok'], 'too short');

        $done = Env::ui('setup', array('password' => 'My-Secret-1', 'serverAddress' => 'http://tm.example', 'torrentClient' => 'qBittorrent',
            'torrentAddress' => Env::QBT.'/', 'torrentLogin' => Env::CLIENT_USER, 'torrentPassword' => Env::CLIENT_PASS, 'pathToDownload' => '/downloads/qbt/'));

        $this->assertTrue($done['json']['ok'], $done['body']);
        $this->assertTrue($done['json']['in'], 'the person who did the setup is logged in');
        $this->assertSame('http://tm.example/', AppState::setting('serverAddress'));
        $this->assertSame('1', AppState::setting('auth'));
        $this->assertStringStartsWith('$2y$', AppState::setting('password'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', AppState::setting('ApiKey'), 'an API key is created');
        $this->assertSame(array('1', 'qBittorrent', Env::QBT), array(AppState::setting('useTorrent'), AppState::setting('torrentClient'), AppState::setting('torrentAddress')));
        $this->assertTrue(Env::ui('client.test')['json']['ok']);

        $this->assertFalse(Env::ui('setup', array('password' => 'Another-One'))['json']['ok'], 'the setup runs once');
        Env::uiClose();
        $this->assertFalse(Env::uiOpen()['json']['setup']);
        $this->assertFalse(Env::ui('setup', array('noPassword' => true))['json']['ok']);
        $this->assertTrue(Env::ui('login', array('password' => 'My-Secret-1'))['json']['ok']);
    }

    public function testLostSettingsFileDoesNotOpenTheInstallation()
    {
        // topics survived, config.json did not: nobody gets in with a built-in password
        $this->loginToTracker();
        $this->trackTopic(905);
        unlink(getenv('TM_DATA_DIR').'/config.json');
        @unlink(getenv('TM_DATA_DIR').'/config.json.bak');
        Env::uiClose();

        // it is not a new installation: the first setup, with a password of the visitor's choice, is not offered
        $boot = Env::uiOpen();
        $this->assertSame(503, $boot['status']);
        $this->assertStringContainsString('Файл настроек отсутствует', $boot['json']['message']);
        $this->assertArrayNotHasKey('setup', $boot['json']);
        $this->assertNull($boot['json']['restore'], 'there is no copy to bring back');
        $this->assertSame(503, Env::ui('setup', array('password' => 'New-Secret'))['status']);
        $this->assertSame(503, Env::ui('login', array('password' => 'torrentmonitor'))['status']);
        $this->assertStringContainsString('Файл настроек отсутствует', Env::runEngineExpectingFailure());

        // the owner of the server writes a settings file without protection and sets a password in the interface
        file_put_contents(getenv('TM_DATA_DIR').'/config.json', '{"version": 1, "settings": {"auth": "0"}}');
        Env::uiClose();
        $this->assertTrue(Env::uiOpen()['json']['in']);
        $this->assertCount(1, AppState::topics(), 'the topics are still there');
    }

    public function testSetupWithoutPassword()
    {
        $this->fresh();
        Env::uiOpen();
        $done = Env::ui('setup', array('noPassword' => true, 'serverAddress' => 'http://tm.example/'));
        $this->assertTrue($done['json']['ok'], $done['body']);
        $this->assertSame('0', AppState::setting('auth'));
        $this->assertSame('0', AppState::setting('useTorrent'));
        Env::uiClose();
        $this->assertTrue(Env::uiOpen()['json']['in']);
    }

    public function testPasswordProtectsEverything()
    {
        Env::ui('settings.password', array('password' => 'Right-Pass'));
        $this->assertSame('1', AppState::setting('auth'), 'setting a password turns the protection on');
        Env::uiClose();

        $boot = Env::uiOpen();
        $this->assertFalse($boot['json']['in']);
        $this->assertArrayNotHasKey('topics', $boot['json']);
        foreach (array('overview', 'settings.save', 'topic.add', 'tracker.save', 'tracker.forget', 'run', 'health', 'settings.showkey', 'update.install') as $action)
            $this->assertSame(401, Env::ui($action)['status'], $action);

        $this->assertFalse(Env::ui('login', array('password' => 'wrong'))['json']['ok']);
        $this->assertSame(401, Env::ui('overview')['status']);
        $in = Env::ui('login', array('password' => 'Right-Pass'));
        $this->assertTrue($in['json']['ok']);
        $this->assertArrayHasKey('topics', $in['json']);
        $this->assertStringNotContainsString(AppState::setting('password'), $in['body']);
        $this->assertSame(200, Env::ui('overview')['status']);

        Env::ui('logout');
        $this->assertSame(401, Env::ui('overview')['status']);
    }

    public function testRememberedLoginSurvivesANewSessionAndEndsWithThePassword()
    {
        Env::ui('settings.password', array('password' => 'Right-Pass'));
        Env::uiClose();
        Env::uiOpen();
        $login = Env::ui('login', array('password' => 'Right-Pass', 'remember' => true));
        $this->assertMatchesRegularExpression('/set-cookie: tm_login=([0-9a-f]{64});/i', $login['headers'], 'a separate long-lived cookie');
        preg_match('/set-cookie: tm_login=([0-9a-f]{64})/i', $login['headers'], $m);
        $this->assertStringNotContainsString($m[1], AppState::file('state.json'), 'only a hash of the token is stored');

        // the browser was closed: the session cookie is gone, the long-lived one is still there
        $remembered = function () use ($m) {
            return Http::postJson(Env::APP.'/ui/boot', array(), array('headers' => array('Cookie: tm_login='.$m[1])))['json']['in'];
        };
        $this->assertTrue($remembered());
        $this->assertFalse(Http::postJson(Env::APP.'/ui/boot', array(), array('headers' => array('Cookie: tm_login='.str_repeat('0', 64))))['json']['in']);

        $changed = Env::ui('settings.password', array('current' => 'Right-Pass', 'password' => 'Newer-Pass'));
        $this->assertTrue($changed['json']['ok'], $changed['body']);
        $this->assertFalse($remembered(), 'changing the password ends remembered logins');
        $this->assertFalse(Env::ui('settings.password', array('current' => 'wrong', 'password' => 'Hacked-Pass'))['json']['ok']);
    }

    public function testTopicIsAddedEditedAndDeleted()
    {
        $this->loginToTracker();
        Env::setTopic(901, 'Название с трекера', '01-Ноя-26 10:00', 1);
        Env::setTopic(902, 'Другая тема', '01-Ноя-26 10:00', 1);

        $added = Env::ui('topic.add', array('kind' => 'topic', 'url' => self::TOPIC.'901', 'name' => '', 'path' => '/downloads/x', 'category' => 'films', 'stopAfterDays' => ''));
        $this->assertTrue($added['json']['ok'], $added['body']);
        $topic = $added['json']['topics'][0];
        $this->assertSame(array('rutracker.org', 'topic', '901', 'Название с трекера', self::TOPIC.'901', '/downloads/x/', 'films', true, null),
            array($topic['tracker'], $topic['kind'], $topic['topicId'], $topic['name'], $topic['url'], $topic['path'], $topic['category'], $topic['autoRename'], $topic['stopAfterDays']));

        $this->assertFalse(Env::ui('topic.add', array('kind' => 'topic', 'url' => self::TOPIC.'901'))['json']['ok'], 'already tracked');
        $this->assertFalse(Env::ui('topic.add', array('kind' => 'topic', 'url' => 'https://example.org/?t=1'))['json']['ok']);
        $this->assertFalse(Env::ui('topic.add', array('kind' => 'topic', 'url' => 'https://kinozal.me/details.php?id=5'))['json']['ok'], 'no account for that tracker');
        $noAccount = Env::ui('topic.add', array('kind' => 'series', 'tracker' => 'lostfilm.tv', 'name' => 'House', 'quality' => '1080'))['json'];
        $this->assertFalse($noAccount['ok']);
        $this->assertStringContainsString('Трекеры', $noAccount['message'], 'the message says where to fix it');
        $this->assertCount(1, AppState::topics());

        Env::runEngine();
        $edited = Env::ui('topic.save', array('id' => $topic['id'], 'url' => self::TOPIC.'902', 'name' => 'Своё имя', 'autoRename' => false,
            'path' => '/downloads/y/', 'category' => 'tv', 'script' => '', 'stopAfterDays' => '0', 'paused' => true, 'reset' => true));
        $this->assertTrue($edited['json']['ok'], $edited['body']);
        $stored = AppState::topics()[0];
        $this->assertSame(array('902', 'Своё имя', '/downloads/y/', 'tv', false, true, '', 0),
            array($stored['topicId'], $stored['name'], $stored['path'], $stored['category'], $stored['autoRename'], $stored['paused'], $stored['updatedAt'], $stored['stopAfterDays']));

        $resumed = Env::ui('topic.pause', array('id' => $topic['id'], 'paused' => false));
        $this->assertFalse($resumed['json']['topics'][0]['paused']);

        $this->assertTrue(Env::ui('topic.delete', array('id' => $topic['id']))['json']['ok']);
        $this->assertCount(0, AppState::topics());
        $this->assertFalse(Env::ui('topic.delete', array('id' => $topic['id']))['json']['ok']);
    }

    public function testSeriesIsAdded()
    {
        AppState::setCredentials('lostfilm.tv', 'someone', 'secret');

        $added = Env::ui('topic.add', array('kind' => 'series', 'tracker' => 'lostfilm.tv', 'name' => 'House of the Dragon', 'quality' => '1080'));
        $this->assertTrue($added['json']['ok'], $added['body']);
        $topic = $added['json']['topics'][0];
        $this->assertSame(array('series', 'House of the Dragon', '1080', ''), array($topic['kind'], $topic['name'], $topic['quality'], $topic['url']));

        $this->assertFalse(Env::ui('topic.add', array('kind' => 'series', 'tracker' => 'lostfilm.tv', 'name' => 'House of the Dragon', 'quality' => '1080'))['json']['ok']);
        $this->assertTrue(Env::ui('topic.add', array('kind' => 'series', 'tracker' => 'lostfilm.tv', 'name' => 'House of the Dragon', 'quality' => '720'))['json']['ok'], 'another quality is another series');
        $this->assertFalse(Env::ui('topic.add', array('kind' => 'series', 'tracker' => 'lostfilm.tv', 'name' => 'Дом дракона', 'quality' => 'sd'))['json']['ok'], 'lostfilm names are latin');
        $this->assertFalse(Env::ui('topic.add', array('kind' => 'series', 'tracker' => 'rutracker.org', 'name' => 'X'))['json']['ok']);
    }

    public function testTrackerAccountIsSavedAndLoginIsChecked()
    {
        $saved = Env::ui('tracker.save', array('name' => 'rutracker.org', 'login' => Env::TRACKER_USER, 'password' => 'wrong-pass', 'address' => ''));
        $this->assertTrue($saved['json']['ok'], $saved['body']);
        $row = function ($response) {
            foreach ($response['json']['trackers'] as $t)
                if ($t['name'] == 'rutracker.org')
                    return $t;
        };
        $this->assertSame(array(Env::TRACKER_USER, true, true, 'https://rutracker.org'),
            array($row($saved)['login'], $row($saved)['hasPassword'], $row($saved)['ready'], $row($saved)['defaultAddress']));
        $this->assertStringNotContainsString('wrong-pass', $saved['body']);

        $test = Env::ui('tracker.test', array('name' => 'rutracker.org'))['json'];
        $this->assertFalse($test['ok']);
        $this->assertSame('Неправильные учётные данные.', $test['message']);

        Env::ui('tracker.save', array('name' => 'rutracker.org', 'login' => Env::TRACKER_USER, 'password' => Env::TRACKER_PASS));
        $test = Env::ui('tracker.test', array('name' => 'rutracker.org'));
        $this->assertTrue($test['json']['ok'], $test['body']);
        $this->assertTrue($row($test)['hasCookie'], 'the session is kept for the next check');

        $this->assertFalse(Env::ui('tracker.save', array('name' => 'rutracker.org', 'login' => 'x', 'address' => 'not a host'))['json']['ok']);
        $this->assertFalse(Env::ui('tracker.save', array('name' => 'unknown.example'))['json']['ok']);
        // a tracker that needs no account is opened instead: here it cannot be reached, and the check says so
        $unreachable = Env::ui('tracker.test', array('name' => 'rutor.is'));
        $this->assertFalse($unreachable['json']['ok'], 'the check is a real one');
        $this->assertStringContainsString('не отвечает', $unreachable['json']['message'].$unreachable['body']);

        // a tracker whose account is optional is ready without one and is read as a guest
        $nnm = null;
        foreach (Env::ui('overview')['json']['trackers'] as $t)
            if ($t['name'] == 'nnmclub.to')
                $nnm = $t;
        $this->assertTrue($nnm['ready']);
        $this->assertTrue($nnm['needsAccount'], 'the account can still be set');
        $this->assertTrue($nnm['accountOptional']);
        $guest = Env::ui('tracker.test', array('name' => 'nnmclub.to'));
        $this->assertTrue($guest['json']['ok'], $guest['body']);
        $this->assertSame('nnmclub.to отвечает. Учётные данные не заданы: трекер проверяется без входа.', $guest['json']['message']);
    }

    public function testCheckIsStartedFromTheInterfaceAndItsLogIsShown()
    {
        $this->loginToTracker();
        Env::setTopic(903, 'Тема', '01-Ноя-26 10:00', 1);
        $id = $this->trackTopic(903);

        $started = Env::ui('run');
        $this->assertTrue($started['json']['ok'], $started['body']);
        Env::waitFor(function () { return ! Env::ui('run.log')['json']['running']; }, 'the check to finish');

        $this->assertSame('2026-11-01 10:00:00', AppState::topic($id)['timestamp']);
        // what the check did is a line of the journal; a log of its own is kept only in detailed mode
        $run = AppState::journal('run')[0];
        $this->assertStringStartsWith('Проверка (вручную), ', $run);
        $this->assertStringEndsWith('Обновлено: Тема.', $run);
        $this->assertSame('', Env::ui('run.log')['json']['log']);

        // one topic can be checked on its own
        Env::setTopic(903, 'Тема', '02-Ноя-26 10:00', 2);
        Env::ui('topic.check', array('id' => $id));
        Env::waitFor(function () use ($id) { return AppState::topic($id)['timestamp'] == '2026-11-02 10:00:00'; }, 'the topic to be checked');
    }

    public function testErrorsAreShownWithTheirTopicAndCanBeCleared()
    {
        $this->loginToTracker();
        $id = $this->trackTopic(904); // the fake tracker has no such topic
        Env::runEngine();

        $overview = Env::ui('overview')['json'];
        $this->assertCount(1, $overview['warnings']);
        $this->assertSame(array('rutracker.org', 'cant_find_date', 'Не удалось найти время обновления торрента.', $id),
            array($overview['warnings'][0]['source'], $overview['warnings'][0]['code'], $overview['warnings'][0]['text'], $overview['warnings'][0]['topic']));
        $this->assertTrue($overview['topics'][0]['error']);

        $cleared = Env::ui('warnings.clear', array('source' => 'rutracker.org'))['json'];
        $this->assertCount(0, $cleared['warnings']);
    }

    public function testWatchedUserTopicsCanBeTrackedDownloadedOrHidden()
    {
        $this->loginToTracker();
        $this->assertFalse(Env::ui('user.add', array('tracker' => 'kinozal.me', 'name' => 'someone'))['json']['ok'], 'this tracker cannot list user topics');
        $added = Env::ui('user.add', array('tracker' => 'rutracker.org', 'name' => 'uploader'));
        $this->assertTrue($added['json']['ok'], $added['body']);
        $this->assertFalse(Env::ui('user.add', array('tracker' => 'rutracker.org', 'name' => 'uploader'))['json']['ok']);
        $userId = $added['json']['users'][0]['id'];

        // what the engine would have found on the tracker
        require_once TM_ROOT.'/src/bootstrap.php';
        foreach (array(951 => 'Первая', 952 => 'Вторая', 953 => 'Третья') as $topicId => $title)
            \TorrentMonitor\Data\Users::addFound($userId, 'rutracker.org', 'Раздел', (string)$topicId, $title, '2026-11-0'.($topicId - 950));

        $found = Env::ui('overview')['json']['users'][0]['found'];
        $this->assertSame(array('Третья', 'Вторая', 'Первая'), array_column($found, 'title'), 'newest first');
        $this->assertSame(self::TOPIC.'953', $found[0]['url']);

        Env::ui('found.track', array('id' => $found[0]['id']));
        Env::ui('found.download', array('id' => $found[1]['id']));
        $left = Env::ui('found.dismiss', array('id' => $found[2]['id']))['json'];

        $this->assertCount(0, $left['users'][0]['found']);
        $this->assertSame(array('953'), array_column(AppState::topics(), 'topicId'), 'tracked permanently');
        $state = json_decode(AppState::file('state.json'), true)['found'];
        $this->assertSame(array(false, true, false), array_column($state, 'accepted'), 'the second one is downloaded once at the next check');

        Env::ui('user.delete', array('id' => $userId));
        $this->assertCount(0, Env::ui('overview')['json']['users']);
        $this->assertCount(0, json_decode(AppState::file('state.json'), true)['found']);
    }

    public function testUnknownActionsAndMethodsAreRefused()
    {
        $this->assertSame(404, Env::ui('no.such.action')['status']);
        $this->assertSame(404, Env::ui('handle')['status']);
        $this->assertSame(404, Env::ui('Overview')['status']);
        $this->assertSame(405, Http::get(Env::APP.'/ui/overview')['status']);
    }

    // A browser keeps scripts for a long time. Their address has to change when the files change,
    // also when the version number does not (a copy installed from the version in development).
    public function testAddressOfTheScriptsChangesWithTheFiles()
    {
        $address = function () {
            preg_match('~assets/app\.js\?v=([^"]+)~', Http::get(Env::APP.'/')['body'], $m);
            return isset($m[1]) ? $m[1] : null;
        };
        $before = $address();
        $this->assertNotNull($before);
        $this->assertStringStartsWith(rawurlencode(AppState::version()).'-', $before);
        $this->assertSame($before, $address(), 'the same files: the same address');

        touch('/app/public/assets/app.js', time() + 100);
        clearstatcache();
        $this->assertNotSame($before, $address(), 'a changed file with the same version number');
    }
}
