<?php
// The log of a check run and the journal of events.
final class LoggingTest extends IntegrationTestCase
{
    private function log()
    {
        return (string)file_get_contents(getenv('TM_DATA_DIR').'/engine.log');
    }

    public function testRunLogStartsWithTheEnvironmentAndEndsWithTheResult()
    {
        $this->loginToTracker();
        Env::setTopic(801, 'Тема с обновлением', '21-Ноя-26 10:00', 1);
        Env::setTopic(802, 'Тема без изменений', '21-Ноя-26 10:00', 1);
        $changed = $this->trackTopic(801);
        $same = $this->trackTopic(802);
        Env::runEngine();
        // the second run: one topic has a new date, the other has not
        Env::setTopic(801, 'Тема с обновлением', '22-Ноя-26 10:00', 2);
        $output = Env::runEngine();

        $lines = explode("\n", trim($this->log()));
        $this->assertMatchesRegularExpression('/^\[[^\]]+\] TorrentMonitor '.preg_quote(AppState::version(), '/').', PHP \d+\.\d+\.\d+, запуск (по расписанию|из командной строки)$/u', $lines[0]);
        $this->assertStringContainsString('Часовой пояс сервера:', $lines[1]);
        $this->assertStringContainsString('Torrent-клиент: не используется', $this->log());
        $this->assertStringContainsString('Решатель Cloudflare: не задан', $this->log());
        $this->assertStringContainsString('Тем: 2 на трекерах: rutracker.org 2', $this->log());

        // the usual log holds what happened: the updated topic, not the unchanged one
        $this->assertStringContainsString('Тема с обновлением на трекере rutracker.org', $this->log());
        $this->assertStringNotContainsString('Тема без изменений', $this->log());
        $this->assertStringNotContainsString('GET https://rutracker.org', $this->log());
        $this->assertStringContainsString('Проверено тем: 2 — обновлено 1, без изменений 1, с ошибками 0.', $this->log());
        $this->assertStringContainsString('Готово за', end($lines));
        $this->assertSame(trim($this->log()), trim($output), 'the output of the run is its log');

        // the result is shown above the log and goes to the journal
        $status = Env::uiOpen()['json']['status'];
        $this->assertStringStartsWith('Проверено тем: 2 — обновлено 1, без изменений 1, с ошибками 0.', $status['lastRunSummary']);
        $runs = AppState::journal('run');
        $this->assertCount(2, $runs);
        $this->assertMatchesRegularExpression('/^Проверка \((по расписанию|из командной строки)\), \d+ с\. Проверено тем: 2 — обновлено 1, без изменений 1, с ошибками 0\./u', $runs[1]);
        $this->assertNotNull(AppState::topic($same));
        $this->assertNotNull(AppState::topic($changed));
    }

    public function testDetailedLogNamesEveryTopicAndRequest()
    {
        $this->loginToTracker();
        Env::setTopic(803, 'Тема без изменений', '21-Ноя-26 10:00', 1);
        $this->trackTopic(803);
        Env::runEngine();
        AppState::setSettings(array('debug' => '1'));
        Env::runEngine();

        $this->assertStringContainsString('Тема без изменений на трекере rutracker.org', $this->log());
        $this->assertMatchesRegularExpression('~GET https://rutracker\.org/forum/viewtopic\.php\?t=803 -> 200, \d+ байт, [\d.]+ с~u', $this->log());
        $this->assertStringContainsString('Проверено тем: 1 — обновлено 0, без изменений 1, с ошибками 0.', $this->log());
        $this->assertStringNotContainsString(Env::TRACKER_PASS, $this->log());
    }

    public function testErrorsOfARunAreInItsResultAndInTheJournal()
    {
        $this->loginToTracker();
        Env::setTopic(804, 'Тема', '21-Ноя-26 10:00', 1);
        $this->trackTopic(804);
        $this->trackTopic(805);     // the tracker has no such topic
        Env::runEngine();

        $this->assertStringContainsString('Проверено тем: 2 — обновлено 1, без изменений 0, с ошибками 1.', $this->log());
        $this->assertMatchesRegularExpression('/Ошибки: rutracker\.org — [^.]+\./u', $this->log());
        $run = AppState::journal('run')[0];
        $this->assertStringContainsString('с ошибками 1.', $run);
        $this->assertStringContainsString('Ошибки: rutracker.org — ', $run);
    }

    public function testRunsStartedFromTheInterfaceAndTheApiSaySo()
    {
        $this->loginToTracker();
        Env::setTopic(806, 'Тема', '21-Ноя-26 10:00', 1);
        $id = $this->trackTopic(806);

        $this->assertTrue(Env::ui('run')['json']['ok']);
        Env::waitFor(function () { return count(AppState::journal('run')) == 1; }, 'the run started from the interface');
        $this->assertStringStartsWith('Проверка (вручную), ', AppState::journal('run')[0]);

        Env::waitFor(function () { return ! Env::ui('run.log')['json']['running']; }, 'the run to finish');
        $this->assertSame(202, Env::api('POST', 'run')['status']);
        Env::waitFor(function () { return count(AppState::journal('run')) == 2; }, 'the run started through the API');
        $this->assertStringStartsWith('Проверка (через API), ', AppState::journal('run')[1]);

        Env::waitFor(function () { return ! Env::ui('run.log')['json']['running']; }, 'the run to finish');
        $this->assertTrue(Env::ui('topic.check', array('id' => $id))['json']['ok']);
        Env::waitFor(function () { return count(AppState::journal('run')) == 3; }, 'the check of one topic');
        $this->assertStringStartsWith('Проверка (вручную, только тема '.$id.'), ', AppState::journal('run')[2]);
    }

    public function testSettingsAndTrackerChangesAreJournalledWithoutSecrets()
    {
        $this->assertTrue(Env::ui('settings.save', array('useTorrent' => true, 'torrentClient' => 'Transmission', 'torrentAddress' => 'http://user:secret@transmission:9091',
            'torrentLogin' => 'someone', 'torrentPassword' => 'Client-Secret-1', 'httpTimeout' => '20'))['json']['ok']);
        $line = AppState::journal('settings')[0];
        $this->assertStringStartsWith('Настройки (интерфейс): ', $line);
        $this->assertStringContainsString('время ожидания ответа: 15 → 20', $line);
        $this->assertStringContainsString('передача в torrent-клиент: включено', $line);
        $this->assertStringContainsString('адрес клиента изменён', $line);
        $this->assertStringNotContainsString('transmission:9091', $line, 'addresses of services are private');
        $this->assertStringContainsString('логин клиента изменён', $line);
        $this->assertStringContainsString('пароль клиента изменён', $line);
        foreach (array('Client-Secret-1', 'someone', 'secret@') as $secret)
            $this->assertStringNotContainsString($secret, implode("\n", AppState::journal()));

        // another client
        Env::ui('settings.save', array('torrentClient' => 'qBittorrent'));
        $this->assertStringContainsString('torrent-клиент: Transmission → qBittorrent', AppState::journal('settings')[1]);
        // saving without a change writes nothing
        Env::ui('settings.save', array('torrentClient' => 'qBittorrent', 'httpTimeout' => '20'));
        $this->assertCount(2, AppState::journal('settings'));

        // a tracker's account
        Env::ui('tracker.save', array('name' => 'rutracker.org', 'login' => 'tracker-user', 'password' => 'Tracker-Secret-1', 'address' => 'rutracker.example'));
        $line = AppState::journal('tracker')[0];
        $this->assertSame('Трекер rutracker.org (интерфейс): логин изменён, пароль изменён, адрес: по умолчанию → rutracker.example', $line);
        Env::ui('tracker.forget', array('name' => 'rutracker.org'));
        $this->assertSame('Трекер rutracker.org (интерфейс): учётные данные удалены', AppState::journal('tracker')[1]);
        foreach (array('Tracker-Secret-1', 'tracker-user') as $secret)
            $this->assertStringNotContainsString($secret, implode("\n", AppState::journal()));

        // keys and the password of the interface
        Env::ui('settings.apikey');
        Env::ui('settings.password', array('password' => 'New-Pass-1'));
        $this->assertSame(array('Ключ API создан заново', 'Пароль входа изменён'), AppState::journal('access'));
    }

    public function testTopicsAddedAndRemovedAreJournalledWithWhoDidIt()
    {
        $this->loginToTracker();
        Env::setTopic(807, 'Тема', '21-Ноя-26 10:00', 1);
        $id = $this->trackTopic(807);
        $this->assertSame(array('Тема добавлена (API): тема 807, rutracker.org'), AppState::journal('topic'));
        Env::runEngine();
        $this->assertTrue(Env::ui('topic.delete', array('id' => $id))['json']['ok']);
        $this->assertSame('Тема удалена (интерфейс): «Тема», rutracker.org', AppState::journal('topic')[1]);
    }

    public function testWrongPasswordsAreCountedInOneLine()
    {
        Env::ui('settings.password', array('password' => 'Right-Pass'));
        Env::uiClose();
        for ($i = 0; $i < 3; $i++)
            $this->assertFalse(Env::ui('login', array('password' => 'wrong-'.$i))['json']['ok']);
        $lines = array_values(array_filter(AppState::journal('access'), function ($l) { return strpos($l, 'Неверный пароль') === 0; }));
        $this->assertCount(1, $lines);
        $this->assertMatchesRegularExpression('/^Неверный пароль при входе, адрес [0-9a-f.:]+ ×3$/u', $lines[0]);
        $this->assertStringNotContainsString('wrong-', implode("\n", AppState::journal()));
    }

    public function testVersionOfTheClientIsRememberedWhenTheConnectionIsChecked()
    {
        AppState::useQbittorrent();
        // not asked yet: the log says so and asks nothing
        Env::runEngine();
        $this->assertStringContainsString('Torrent-клиент: qBittorrent (версия неизвестна: связь ещё не проверялась)', $this->log());

        $checked = Env::ui('client.test');
        $this->assertTrue($checked['json']['ok'], $checked['body']);
        $this->assertMatchesRegularExpression('/^Подключение к qBittorrent \d+\.\d+[\d.]* работает\.$/u', $checked['json']['message']);
        $seen = AppState::state()['services']['client'];
        $this->assertSame('qBittorrent', $seen['name']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $seen['version']);
        $this->assertMatchesRegularExpression('/^libtorrent [\d.]+, web API [\d.]+$/', $seen['details']);
        $this->assertMatchesRegularExpression('/^Torrent-клиент: qBittorrent \d+\.\d+[\d.]* \(libtorrent [\d.]+, web API [\d.]+\)$/u', AppState::journal('service')[0]);

        // the next run prints what is remembered
        Env::runEngine();
        $this->assertMatchesRegularExpression('/Torrent-клиент: qBittorrent \d+\.\d+[\d.]* \(libtorrent [\d.]+, web API [\d.]+\), проверено \d{4}-\d\d-\d\d \d\d:\d\d/u', $this->log());
        // where the client is stays out of the log, the detailed one too
        AppState::setSettings(array('debug' => '1'));
        Env::runEngine();
        $this->assertStringNotContainsString(preg_replace('~^\w+://~', '', Env::qbt()), $this->log());
        AppState::setSettings(array('debug' => '0'));
        // the same version again is not an event
        Env::ui('client.test');
        $this->assertCount(1, AppState::journal('service'));

        // the client switched off: what was known about it is forgotten
        Env::ui('settings.save', array('useTorrent' => false));
        $this->assertArrayNotHasKey('client', AppState::state()['services']);
    }

    public function testVersionOfTheSolverIsRememberedByTheNetworkCheck()
    {
        Env::ui('settings.save', array('flaresolverrUrl' => Env::SOLVER));
        $checked = Env::ui('network.test');
        $this->assertTrue($checked['json']['ok'], $checked['body']);
        $this->assertStringContainsString('Решатель Cloudflare '.Env::SOLVER.' отвечает: FlareSolverr 9.8.7.', $checked['json']['message']);
        $this->assertSame('Решатель Cloudflare: FlareSolverr 9.8.7', AppState::journal('service')[0]);
        Env::runEngine();
        $this->assertStringContainsString('Решатель Cloudflare: FlareSolverr 9.8.7, проверено ', $this->log());
        $this->assertStringNotContainsString(Env::SOLVER, $this->log().implode("\n", AppState::journal()));
    }

    public function testAVersionInstalledOutsideTheProgramIsJournalled()
    {
        // the first start only remembers the version
        Env::runEngine();
        $before = AppState::version();
        $this->assertSame($before, AppState::state()['program']);
        $this->assertSame(array(), AppState::journal('update'));

        // the files are replaced by something else: a new Docker image, an archive unpacked by hand
        file_put_contents('/app/version.txt', json_encode(array('system' => '9.9.9', 'database' => '9.9.9')));
        Env::runEngine();
        Env::runEngine();

        $lines = AppState::journal('update');
        $this->assertCount(1, $lines, 'said once');
        $this->assertStringContainsString('Версия программы изменилась: '.$before.' → 9.9.9', $lines[0]);
        $this->assertSame('9.9.9', AppState::state()['program']);
        // the client and the solver the new version starts with are named after it
        $this->assertSame(array('Torrent-клиент: не используется', 'Решатель Cloudflare: не задан'), AppState::journal('service'));
    }

    public function testJournalKeepsTheNumberOfLinesItIsToldTo()
    {
        Env::ui('settings.save', array('journalSize' => '3'));
        for ($i = 1; $i <= 5; $i++)
            Env::ui('settings.save', array('httpTimeout' => (string)(20 + $i)));
        $lines = AppState::journal('settings');
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('24 → 25', end($lines));
        $shown = Env::ui('overview')['json']['journal'];
        $this->assertSame('settings', $shown[0]['kind']);
        $this->assertStringContainsString('24 → 25', $shown[0]['text'], 'the interface gets the newest first');
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $shown[0]['timeUtc']);

        // 0: no journal
        Env::ui('settings.save', array('journalSize' => '0'));
        Env::ui('settings.save', array('httpTimeout' => '40'));
        $this->assertCount(3, AppState::journal('settings'), 'nothing is added');
    }

    public function testEnvironmentTextForABugReportHasNoSecrets()
    {
        AppState::useQbittorrent();
        Env::ui('settings.save', array('proxy' => true, 'proxyAddress' => 'user:Proxy-Secret@127.0.0.1:1080', 'proxyType' => 'SOCKS5'));
        $text = Env::ui('about')['json']['text'];
        $this->assertStringStartsWith('TorrentMonitor '.AppState::version().', PHP ', $text);
        $this->assertStringContainsString('Прокси: включён, SOCKS5.', $text);
        $this->assertStringNotContainsString('127.0.0.1:1080', $text);
        $this->assertStringNotContainsString(preg_replace('~^\w+://~', '', Env::qbt()), $text);
        $this->assertStringContainsString('Обновления: вручную', $text);
        foreach (array('Proxy-Secret', Env::CLIENT_PASS) as $secret)
            $this->assertStringNotContainsString($secret, $text);
    }

    // config.php can name a file that gets the output of runs started from the interface:
    // in a container that is how they reach "docker logs"
    public function testOutputOfARunStartedFromTheInterfaceCanBeSentToAFile()
    {
        $config = file_get_contents('/app/config.php');
        @unlink('/tmp/run-output.log');
        file_put_contents('/app/config.php', $config."\nConfig::write('log.output', '/tmp/run-output.log');\n");
        try
        {
            $this->assertTrue(Env::ui('run')['json']['ok']);
            Env::waitFor(function () { return strpos((string)@file_get_contents('/tmp/run-output.log'), 'Готово за') !== false; }, 'the output of the run in the file');
            $this->assertStringContainsString('запуск вручную', file_get_contents('/tmp/run-output.log'));
        }
        finally
        {
            file_put_contents('/app/config.php', $config);
        }
    }

    public function testAnOutputFileThatCannotBeWrittenDoesNotStopTheRun()
    {
        $config = file_get_contents('/app/config.php');
        file_put_contents('/app/config.php', $config."\nConfig::write('log.output', '/proc/1/no-such-place/output');\n");
        try
        {
            $this->assertTrue(Env::ui('run')['json']['ok']);
            Env::waitFor(function () { return count(AppState::journal('run')) == 1; }, 'the run to happen without its output file');
        }
        finally
        {
            file_put_contents('/app/config.php', $config);
        }
    }
}
