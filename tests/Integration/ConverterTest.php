<?php
// tools/convert-db.php: one-time transfer of a 3.x database into JSON files.
// The old databases are built from the schemas of version 3.3.6 (tests/Fixtures/legacy) and of the
// layout version 2.0 had (tests/Fixtures/legacy-2.0: the schemas of 1.8.9.5, the last ones published
// before 2.0; the layout did not change until 3.0).
final class ConverterTest extends IntegrationTestCase
{
    const KEY = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

    private $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = sys_get_temp_dir().'/tm-converted-'.bin2hex(random_bytes(4));
        // an old config.php without database settings: everything comes from the command line
        file_put_contents('/tmp/no-settings.php', "<?php\n");
    }

    // value as version 3.x stored it when encryption.key was set
    private static function encrypted($plain)
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', hex2bin(self::KEY), OPENSSL_RAW_DATA, $iv, $tag);
        return 'enc:'.base64_encode($iv.$tag.$cipher);
    }

    private static function sqlite($file, $layout = 'legacy')
    {
        @unlink($file);
        $pdo = new PDO('sqlite:'.$file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(file_get_contents(TESTS_DIR.'/Fixtures/'.$layout.'/sqlite.sql'));
        return $pdo;
    }

    private static function mysql($layout = 'legacy')
    {
        $pdo = new PDO('mysql:host=mariadb;dbname=torrentmonitor;charset=utf8', 'tm', 'tm-test-pass');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET sql_mode = ""');
        $pdo->exec(file_get_contents(TESTS_DIR.'/Fixtures/'.$layout.'/mysql.sql'));
        return $pdo;
    }

    private static function pgsql($layout = 'legacy')
    {
        $pdo = new PDO('pgsql:host=postgres;dbname=torrentmonitor', 'tm', 'tm-test-pass');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
        $pdo->exec(file_get_contents(TESTS_DIR.'/Fixtures/'.$layout.'/postgresql.sql'));
        return $pdo;
    }

    // the same installation in every database: settings, logins (encrypted), topics, queues
    private static function fill(PDO $pdo, $q)
    {
        $n = function ($name) use ($q) { return $q.$name.$q; };
        $run = function ($sql, $params = array()) use ($pdo) { $pdo->prepare($sql)->execute($params); };
        $setting = function ($key, $val) use ($run, $n) {
            $run('UPDATE settings SET '.$n('val').' = ? WHERE '.$n('key').' = ?', array($val, $key));
        };

        $setting('serverAddress', 'http://app/');
        $setting('auth', '0');
        $setting('ApiKey', Env::API_TOKEN);
        $setting('useTorrent', '1');
        $setting('torrentClient', 'qBittorrent');
        $setting('torrentAddress', Env::QBT);
        $setting('torrentLogin', Env::CLIENT_USER);
        $setting('torrentPassword', self::encrypted(Env::CLIENT_PASS));
        $setting('pathToDownload', '/downloads/qbt/');
        $setting('sendUpdateService', '11');
        $setting('cf_userAgent', 'Remembered Agent/1.0');
        // an installation that was upgraded twice has the same key in two rows
        $run('INSERT INTO settings (id, '.$n('key').', '.$n('val').') VALUES (?, ?, ?)', array(99, 'flaresolverrUrl', 'http://solver:8191'));

        $run('UPDATE credentials SET log = ?, pass = ?, cookie = ? WHERE tracker = ?',
            array(Env::TRACKER_USER, self::encrypted(Env::TRACKER_PASS), self::encrypted('bb_session=old-session;'), 'rutracker.org'));
        $run('UPDATE credentials SET log = ?, pass = ?, passkey = ? WHERE tracker = ?', array('Вася', 'plain-pass', 'plain-passkey', 'baibako.tv'));
        $run('UPDATE notifications SET address = ? WHERE id = ?', array('123456:telegram-token', 11));

        $run('INSERT INTO torrent (id, tracker, name, hd, path, torrent_id, ep, '.$n('timestamp').', auto_update, hash, script, pause, error, closed, category) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array(7, 'rutracker.org', 'Сериал / Serial "S01"', 0, '/media/tv/', '801', '', '2026-01-08 19:45:00', 1, 'aabbccddeeff00112233445566778899aabbccdd', '', 0, 0, 0, 'tv-sonarr'));
        $run('INSERT INTO torrent (id, tracker, name, hd, path, torrent_id, ep, '.$n('timestamp').', auto_update, hash, script, pause, error, closed, category) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array(12, 'lostfilm.tv', 'House', 2, '', '', 'S02E05', '2026-02-01 00:00:00', 0, '', 'echo.sh', 1, 1, 0, ''));
        $run('INSERT INTO watch (id, tracker, name) VALUES (?,?,?)', array(3, 'rutracker.org', 'releaser'));
        $run('INSERT INTO buffer (id, user_id, section, threme_id, threme, '.$n('timestamp').', accept, downloaded, new, tracker) VALUES (?,?,?,?,?,?,?,?,?,?)',
            array(5, 3, 'Кино', 900, 'Новый фильм', '2026-03-01', 0, 0, 1, 'rutracker.org'));
        $run('INSERT INTO warning (id, '.$n('time').', '.$n('where').', reason, t_id) VALUES (?,?,?,?,?)', array(4, '2026-03-02 10:00:00', 'lostfilm.tv', 'credential_wrong', 12));
        $run('INSERT INTO news (id, '.$n('text').', new) VALUES (?,?,?)', array('updated_3.3.6', '<b>Обновление</b>', 0));
        $run('INSERT INTO temp (id, name, path, tracker, '.$n('date').') VALUES (?,?,?,?,?)', array(7, 'Сериал', '/app/torrents/x.torrent', 'rutracker.org', '8 Янв 2026'));
    }

    private function convert($arguments, $expectCode = 0)
    {
        exec('TM_DATA_DIR= php /app/tools/convert-db.php '.$arguments.' 2>&1', $output, $code);
        $text = implode("\n", $output);
        $this->assertSame($expectCode, $code, $text);
        return $text;
    }

    private function converted($name)
    {
        return json_decode(file_get_contents($this->out.'/'.$name), true);
    }

    public function databases()
    {
        return array(
            'sqlite'     => array('sqlite', '"', '--type=sqlite --db=/tmp/legacy.sqlite'),
            'mysql'      => array('mysql', '`', '--type=mysql --host=mariadb --db=torrentmonitor --user=tm --password=tm-test-pass --charset=utf8'),
            'postgresql' => array('pgsql', '"', '--type=pgsql --host=postgres --port=5432 --db=torrentmonitor --user=tm --password=tm-test-pass'),
        );
    }

    /** @dataProvider databases */
    public function testEverythingIsTransferred($type, $quote, $connection)
    {
        $pdo = $type == 'sqlite' ? self::sqlite('/tmp/legacy.sqlite') : self::$type();
        self::fill($pdo, $quote);

        $report = $this->convert($connection.' --key='.self::KEY.' --data='.escapeshellarg($this->out).' --config=/tmp/no-settings.php');

        $config = $this->converted('config.json');
        $this->assertSame(1, $config['version']);
        $this->assertSame('http://app/', $config['settings']['serverAddress']);
        // the one torrent client of the old version is the first client of the list, and the default one
        $this->assertSame(array('id' => 1, 'name' => 'qBittorrent', 'type' => 'qBittorrent', 'address' => Env::QBT, 'login' => Env::CLIENT_USER,
            'password' => Env::CLIENT_PASS, 'path' => '/downloads/qbt/', 'category' => ''), $config['clients'][0], 'client password is decrypted');
        $this->assertSame(1, $config['defaultClient']);
        $this->assertArrayNotHasKey('torrentPassword', $config['settings']);
        $this->assertArrayNotHasKey('torrentClient', $config['settings']);
        $this->assertStringContainsString('torrent-клиентов: 1 (qBittorrent)', $report);
        $this->assertSame('http://solver:8191', $config['settings']['flaresolverrUrl'], 'of two rows with one key, the filled one wins');
        $this->assertStringContainsString('"flaresolverrUrl" записана в базе несколько раз', $report);
        $this->assertArrayNotHasKey('cf_userAgent', $config['settings']);
        $this->assertSame(array('login' => Env::TRACKER_USER, 'password' => Env::TRACKER_PASS, 'passkey' => ''), $config['trackers']['rutracker.org']);
        $this->assertSame(array('login' => 'Вася', 'password' => 'plain-pass', 'passkey' => 'plain-passkey'), $config['trackers']['baibako.tv']);
        $this->assertArrayNotHasKey('kinozal.me', $config['trackers'], 'trackers without a login are not written');
        $this->assertArrayNotHasKey('rutor.is', $config['trackers'], 'trackers that need no login are not written');
        $this->assertSame(array('11' => '123456:telegram-token'), $config['notifications']);

        $torrents = $this->converted('torrents.json');
        $this->assertSame(13, $torrents['nextId'], 'new topics continue the old numbering');
        $this->assertCount(2, $torrents['torrents']);
        $topic = $torrents['torrents'][0];
        $this->assertSame(7, $topic['id']);
        $this->assertSame('Сериал / Serial "S01"', $topic['name']);
        $this->assertSame('801', $topic['torrent_id']);
        $this->assertSame('2026-01-08 19:45:00', $topic['timestamp']);
        $this->assertSame('2026-01-08 19:45:00', $topic['lastActivityAt']);
        $this->assertSame('tv-sonarr', $topic['category']);
        $this->assertSame(1, $topic['auto_update']);
        $series = $torrents['torrents'][1];
        $this->assertSame(array(12, 'House', 2, 'S02E05', 'echo.sh', 1, 1), array($series['id'], $series['name'], $series['hd'], $series['ep'], $series['script'], $series['pause'], $series['error']));
        $this->assertSame(array(array('id' => 3, 'tracker' => 'rutracker.org', 'name' => 'releaser')), $torrents['users']);
        $this->assertSame(4, $torrents['nextUserId']);

        $state = $this->converted('state.json');
        $this->assertSame('Remembered Agent/1.0', $state['settings']['cf_userAgent']);
        $this->assertSame(array('rutracker.org' => 'bb_session=old-session;'), $state['cookies']);
        $this->assertSame('credential_wrong', $state['warnings'][0]['reason']);
        $this->assertSame(12, $state['warnings'][0]['t_id']);
        $this->assertSame(5, $state['nextWarningId']);
        $this->assertSame('Новый фильм', $state['buffer'][0]['threme']);
        $this->assertArrayNotHasKey('news', $state, 'news of the old version are not carried over');
        $this->assertSame('8 Янв 2026', $state['temp']['7']['date']);

        $this->assertStringNotContainsString(Env::TRACKER_PASS, $report, 'the report never shows passwords');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->out.'/config.json')), -4));
        $this->assertStringContainsString(', версия 3.0 или новее', $report);
        $this->assertStringNotContainsString('Warning: ', $report);
    }

    // A database that was last updated by version 2.0: no category of a topic, fewer settings,
    // a tracker under its former name, topics of trackers version 4 does not have.
    /** @dataProvider databases */
    public function testDatabaseOfVersion2IsTransferred($type, $quote, $connection)
    {
        $pdo = $type == 'sqlite' ? self::sqlite('/tmp/legacy.sqlite', 'legacy-2.0') : self::$type('legacy-2.0');
        $n = function ($name) use ($quote) { return $quote.$name.$quote; };
        $run = function ($sql, $params = array()) use ($pdo) { $pdo->prepare($sql)->execute($params); };
        $run('UPDATE settings SET '.$n('val').' = ? WHERE '.$n('key').' = ?', array('http://app/', 'serverAddress'));
        $run('UPDATE settings SET '.$n('val').' = ? WHERE '.$n('key').' = ?', array('11', 'sendUpdateService'));
        $run('UPDATE notifications SET address = ? WHERE id = ?', array('123456:telegram-token', 11));
        $run('UPDATE credentials SET log = ?, pass = ? WHERE tracker = ?', array(Env::TRACKER_USER, Env::TRACKER_PASS, 'rutracker.org'));
        $run('UPDATE credentials SET log = ?, pass = ? WHERE tracker = ?', array('old-name-user', 'old-name-pass', 'rutor.info'));
        $topic = 'INSERT INTO torrent (id, tracker, name, hd, path, torrent_id, ep, '.$n('timestamp').', auto_update, hash, script, pause, error, closed) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $run($topic, array(3, 'rutracker.org', 'Сериал', 0, '/media/tv/', '801', '', '2023-01-08 19:45:00', 1, 'aabb', '', 0, 0, 0));
        $run($topic, array(4, 'rutor.info', 'Фильм', 0, '', '555', '', '2023-01-09 10:00:00', 0, '', '', 1, 0, 0));
        $run($topic, array(5, 'tfile.cc', 'Старая тема', 0, '', '777', '', '2022-05-01 10:00:00', 0, '', '', 0, 0, 1));
        $run($topic, array(6, 'baibako.tv', 'Show', 1, '', '', 'S01E02', '2022-06-01 00:00:00', 0, '', '', 0, 0, 0));
        $run('INSERT INTO watch (id, tracker, name) VALUES (?,?,?)', array(2, 'rutor.info', 'releaser'));
        $run('INSERT INTO warning (id, '.$n('time').', '.$n('where').', reason, t_id) VALUES (?,?,?,?,?)', array(1, '2023-01-10 10:00:00', 'rutor.info', 'cant_find_date', 4));

        $report = $this->convert($connection.' --data='.escapeshellarg($this->out).' --config=/tmp/no-settings.php');
        $this->assertStringNotContainsString('Warning: ', $report, 'columns this version did not have are not an error');
        $this->assertStringContainsString(', версия до 2.1.7', $report);
        $this->assertStringNotContainsString('старше версии 2.0', $report);
        $this->assertStringContainsString('трекер rutor.info теперь называется rutor.is', $report);
        $this->assertStringContainsString('версия 4 не работает с трекерами: ', $report);
        $this->assertStringContainsString('tfile.cc — 1', $report);
        $this->assertStringContainsString('baibako.tv — 1', $report);

        $config = $this->converted('config.json');
        $this->assertSame('http://app/', $config['settings']['serverAddress']);
        $this->assertArrayNotHasKey('userAgent', $config['settings'], 'a setting this version did not have takes its default later');
        $this->assertSame(array('login' => 'old-name-user', 'password' => 'old-name-pass', 'passkey' => ''), $config['trackers']['rutor.is']);
        $this->assertArrayNotHasKey('rutor.info', $config['trackers']);
        $this->assertSame(array('11' => '123456:telegram-token'), $config['notifications']);

        $torrents = $this->converted('torrents.json');
        $this->assertSame(array('rutracker.org', 'rutor.is', 'tfile.cc', 'baibako.tv'), array_column($torrents['torrents'], 'tracker'));
        $this->assertSame('', $torrents['torrents'][0]['category']);
        $this->assertSame(array(1, 0, 1), array($torrents['torrents'][1]['pause'], $torrents['torrents'][1]['closed'], $torrents['torrents'][2]['closed']));
        $this->assertSame('rutor.is', $torrents['users'][0]['tracker']);
        $this->assertSame('rutor.is', $this->converted('state.json')['warnings'][0]['where']);

        // the program works with the result: defaults for what is missing, the renamed tracker is known
        exec('cp -a '.escapeshellarg($this->out).'/. '.escapeshellarg(getenv('TM_DATA_DIR')).'/');
        $this->assertSame('rutor.is', AppState::topic(4)['tracker']);
        $this->assertSame('', AppState::topic(3)['category']);
        $this->assertTrue(Env::uiOpen()['json']['ok'], 'the interface opens on the converted data');
        $this->assertSame(15, (int)AppState::setting('httpTimeout'));
        $this->assertNotSame('', AppState::setting('userAgent'));
    }

    // The trackers the converter knows are the ones the program has
    public function testConverterKnowsTheTrackersOfTheProgram()
    {
        $source = file_get_contents('/app/tools/convert-db.php');
        $this->assertSame(1, preg_match('/const SUPPORTED_TRACKERS = array\((.*?)\);/s', $source, $m));
        preg_match_all("/'([^']+)'/", $m[1], $names);
        $known = array_keys((new ReflectionClassConstant('TorrentMonitor\\Tracker\\Registry', 'TRACKERS'))->getValue());
        sort($names[1]);
        sort($known);
        $this->assertSame($known, $names[1]);
    }

    public function testWithoutTheKeyEncryptedValuesAreListedForReentry()
    {
        self::fill(self::sqlite('/tmp/legacy.sqlite'), '"');

        $report = $this->convert('--type=sqlite --db=/tmp/legacy.sqlite --data='.escapeshellarg($this->out).' --config=/tmp/no-settings.php');

        $this->assertStringContainsString('пароль трекера rutracker.org', $report);
        $this->assertStringContainsString('пароль torrent-клиента', $report);
        $config = $this->converted('config.json');
        $this->assertSame('', $config['trackers']['rutracker.org']['password']);
        $this->assertSame(Env::TRACKER_USER, $config['trackers']['rutracker.org']['login'], 'the login is kept');
        $this->assertSame('plain-pass', $config['trackers']['baibako.tv']['password'], 'values that were never encrypted are kept');
    }

    public function testWrongKeyIsTreatedAsMissing()
    {
        self::fill(self::sqlite('/tmp/legacy.sqlite'), '"');

        $report = $this->convert('--type=sqlite --db=/tmp/legacy.sqlite --key='.str_repeat('ab', 32).' --data='.escapeshellarg($this->out).' --config=/tmp/no-settings.php');

        $this->assertStringContainsString('Не удалось расшифровать', $report);
        $this->assertSame('', $this->converted('config.json')['clients'][0]['password']);
    }

    public function testDryRunWritesNothingAndExistingDataIsProtected()
    {
        self::fill(self::sqlite('/tmp/legacy.sqlite'), '"');
        $arguments = '--type=sqlite --db=/tmp/legacy.sqlite --key='.self::KEY.' --data='.escapeshellarg($this->out).' --config=/tmp/no-settings.php';

        $report = $this->convert($arguments.' --dry-run');
        $this->assertStringContainsString('тем: 2', $report);
        $this->assertDirectoryDoesNotExist($this->out);

        $this->convert($arguments);
        file_put_contents($this->out.'/torrents.json', '{"version":1,"mine":true}');
        $refused = $this->convert($arguments, 1);
        $this->assertStringContainsString('--force', $refused);
        $this->assertSame('{"version":1,"mine":true}', file_get_contents($this->out.'/torrents.json'));

        $this->convert($arguments.' --force');
        $this->assertCount(2, $this->converted('torrents.json')['torrents']);
        $this->assertSame('{"version":1,"mine":true}', file_get_contents($this->out.'/torrents.json.bak'), 'replaced file is kept as .bak');
    }

    public function testDatabaseIsNotChanged()
    {
        self::fill(self::sqlite('/tmp/legacy.sqlite'), '"');
        $before = md5_file('/tmp/legacy.sqlite');

        $this->convert('--type=sqlite --db=/tmp/legacy.sqlite --key='.self::KEY.' --data='.escapeshellarg($this->out).' --config=/tmp/no-settings.php');

        $this->assertSame($before, md5_file('/tmp/legacy.sqlite'));
    }

    public function testClearErrorsForBadInput()
    {
        $this->assertStringContainsString('не указан тип базы данных', $this->convert('--config=/tmp/no-settings.php', 1));
        $this->assertStringContainsString('файл базы данных не найден', $this->convert('--config=/tmp/no-settings.php --type=sqlite --db=/tmp/no-such.sqlite', 1));
        $this->assertStringContainsString('64 шестнадцатеричных', $this->convert('--config=/tmp/no-settings.php --type=sqlite --db=/tmp/legacy.sqlite --key=short', 1));
        file_put_contents('/tmp/not-tm.sqlite', '');
        $this->assertStringContainsString('не база TorrentMonitor', $this->convert('--config=/tmp/no-settings.php --type=sqlite --db=/tmp/not-tm.sqlite', 1));
        $this->assertStringContainsString('--dry-run', $this->convert('--help'));
    }

    public function testUpgradedInstallationIsToldToConvertAndThenWorks()
    {
        // an installation as version 3.x left it: config.php with database settings, config.xml, no data directory
        self::fill(self::sqlite('/tmp/legacy.sqlite'), '"');
        Env::sh('/src/tests/env/app/reset-data.sh');
        $oldConfig = "<?php\ninclude_once dirname(__FILE__).'/class/Config.class.php';\nConfig::extended();\n"
            ."#Config::write('db.type', 'mysql');\nConfig::write('db.type', 'sqlite');\nConfig::write('db.basename', '/tmp/legacy.sqlite');\n"
            ."Config::write('encryption.key', '".self::KEY."');\nConfig::write('update.api', 'http://fake:8080/github');\n?>";
        file_put_contents('/app/config.php', $oldConfig);
        file_put_contents('/app/config.xml', '<?xml version="1.0"?><body><proxy><lostfilm.tv><use>yes</use><type>socks5</type><address>192.168.1.220:8118</address></lostfilm.tv><rutor.is><use>no</use></rutor.is></proxy></body>');

        // the page itself opens; its first request gets the reason why nothing works
        $page = Env::uiOpen();
        $page['body'] = (string)$page['json']['message'];
        $this->assertSame(503, $page['status']);
        $this->assertStringContainsString('php tools/convert-db.php', $page['body']);
        $this->assertSame(503, Env::api('GET', 'torrents')['status']);
        $this->assertStringContainsString('php tools/convert-db.php', Env::runEngineExpectingFailure());
        $this->assertSame(array(), glob(getenv('TM_DATA_DIR').'/*.json'), 'nothing is created before the conversion');

        // no arguments: everything is taken from the old config.php
        exec('cd /app && php tools/convert-db.php 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
        unlink('/app/config.xml');

        $this->assertSame(200, Http::get(Env::APP.'/')['status']);
        $list = Env::api('GET', 'torrents')['json']['data'];
        $this->assertSame(array('', '801'), array_column($list, 'topicId'));
        $this->assertSame(
            array('lostfilm.tv' => array('use' => 'yes', 'address' => '192.168.1.220:8118', 'type' => 'SOCKS5'), 'rutor.is' => array('use' => 'no')),
            json_decode(AppState::file('config.json'), true)['trackerProxy']
        );

        // the converted login and client settings work: the engine updates the old topic
        Env::setTopic(801, 'Сериал / Serial "S01"', '15-Янв-26 20:00', 2);
        Env::runEngine();

        $topic = AppState::topic(7);
        $this->assertSame('2026-01-15 20:00:00', $topic['timestamp']);
        $this->assertSame(TorrentBuilder::infoHash(801, 2), strtolower($topic['hash']));
        $this->assertTrue(Qbt::has(TorrentBuilder::infoHash(801, 2)));
        $this->assertSame('tv-sonarr', Qbt::torrents()[TorrentBuilder::infoHash(801, 2)]['category']);
    }
}
