<?php
// Settings saved through the web interface.
final class SettingsTest extends IntegrationTestCase
{
    public function testActionsNeedTheCsrfToken()
    {
        $response = Http::postJson(Env::APP.'/ui/settings.save', array('useTorrent' => true));
        $this->assertSame(403, $response['status']);
        $this->assertFalse($response['json']['ok']);
        $this->assertSame('0', AppState::setting('useTorrent'));
    }

    public function testTorrentClientsAreAddedChangedAndRemoved()
    {
        // the first client: its proposed name can be kept, and it becomes the default one
        $added = Env::ui('client.save', array('name' => 'qBittorrent', 'type' => 'qBittorrent', 'address' => Env::QBT.'/', 'login' => 'someone',
            'password' => 'pa$$ word', 'path' => '/downloads/x', 'category' => 'tv'));
        $this->assertTrue($added['json']['ok'], $added['body']);
        $first = $added['json']['saved'];
        $client = AppState::client($first);
        $this->assertSame(Env::QBT, $client['address'], 'trailing slash is removed');
        $this->assertSame('/downloads/x/', $client['path'], 'trailing slash is added');
        $this->assertSame(array('pa$$ word', 'tv', 'someone'), array($client['password'], $client['category'], $client['login']));
        $listed = $added['json']['clients'];
        $this->assertCount(1, $listed);
        $this->assertTrue($listed[0]['default']);
        $this->assertTrue($listed[0]['hasPassword']);
        $this->assertArrayNotHasKey('password', $listed[0], 'the password is not sent to the browser');

        // a second one of the same kind, on another server; names do not repeat and are not empty
        $same = Env::ui('client.save', array('name' => 'QBITTORRENT', 'type' => 'qBittorrent', 'address' => 'http://other:8080'));
        $this->assertFalse($same['json']['ok']);
        $this->assertStringContainsString('уже есть', $same['json']['message']);
        $this->assertFalse(Env::ui('client.save', array('name' => ' ', 'type' => 'qBittorrent', 'address' => 'http://other:8080'))['json']['ok']);
        $this->assertFalse(Env::ui('client.save', array('name' => 'X', 'type' => 'Nope', 'address' => 'http://other:8080'))['json']['ok']);
        $this->assertFalse(Env::ui('client.save', array('name' => 'X', 'type' => 'qBittorrent', 'address' => ''))['json']['ok']);
        $second = Env::ui('client.save', array('name' => 'NAS', 'type' => 'qBittorrent', 'address' => 'http://other:8080'))['json']['saved'];
        $this->assertNotSame($first, $second);
        $clients = Env::ui('overview')['json']['clients'];
        $this->assertSame(array(true, false), array_column($clients, 'default'), 'the first one stays the default');

        // an empty password field keeps the saved password; the box removes it
        Env::ui('client.save', array('id' => $first, 'name' => 'Дом', 'type' => 'qBittorrent', 'address' => Env::QBT, 'password' => ''));
        $this->assertSame('pa$$ word', AppState::client($first)['password']);
        $this->assertSame('Дом', AppState::client($first)['name']);
        Env::ui('client.save', array('id' => $first, 'name' => 'Дом', 'type' => 'qBittorrent', 'address' => Env::QBT, 'password' => '', 'clearPassword' => true));
        $this->assertSame('', AppState::client($first)['password']);

        // another default
        $this->assertTrue(Env::ui('client.default', array('id' => $second))['json']['ok']);
        $this->assertSame(array(false, true), array_column(Env::ui('overview')['json']['clients'], 'default'));
        $this->assertFalse(Env::ui('client.default', array('id' => 999))['json']['ok']);

        // the journal: added, renamed, made the default - with the address inside a mark and no password
        $journal = implode("\n", AppState::journal('service'));
        $this->assertStringContainsString('Torrent-клиент «qBittorrent» добавлен (интерфейс): qBittorrent, {{a:'.Env::QBT.'}}', $journal);
        $this->assertStringContainsString('Torrent-клиент «qBittorrent» (интерфейс): название: qBittorrent → Дом', $journal);
        $this->assertStringContainsString('пароль удалён', $journal);
        $this->assertStringContainsString('Основной torrent-клиент (интерфейс): «NAS»', $journal);
        $this->assertStringNotContainsString('pa$$ word', $journal);

        // a client that topics name cannot go until they are given to another one
        $this->loginToTracker();
        AppState::setSettings(array('useTorrent' => '1'));
        $id = $this->trackTopic(120);
        AppState::setTopic($id, array('client' => $first));
        $refused = Env::ui('client.delete', array('id' => $first));
        $this->assertSame(409, $refused['status']);
        $this->assertStringContainsString('привязаны темы: 1', $refused['json']['message']);
        $this->assertTrue(Env::ui('client.delete', array('id' => $first, 'moveTo' => $second))['json']['ok']);
        $this->assertNull(AppState::client($first));
        $this->assertSame($second, AppState::topic($id)['client']);
        // the last one goes without questions: its topics followed the default
        AppState::setTopic($id, array('client' => null));
        $this->assertTrue(Env::ui('client.delete', array('id' => $second))['json']['ok']);
        $this->assertSame(array(), Env::ui('overview')['json']['clients']);

        // what applies to every client is still a setting
        Env::ui('settings.save', array('useTorrent' => true, 'deleteDistribution' => false, 'deleteOldFiles' => true, 'deleteFilesOnRemove' => true));
        $this->assertSame(array('1', '0', '1', '1'), array(AppState::setting('useTorrent'), AppState::setting('deleteDistribution'), AppState::setting('deleteOldFiles'), AppState::setting('deleteFilesOnRemove')));
    }

    public function testTrackerTimeZoneIsSavedAndUsedForTheTimesShown()
    {
        // the default: trackers show Moscow time
        $this->assertSame('Europe/Moscow', AppState::setting('trackerTimezone'));
        AppState::setCredentials('rutracker.org', Env::TRACKER_USER, Env::TRACKER_PASS);
        $created = Env::api('POST', 'torrents', array('json' => array('url' => 'https://rutracker.org/forum/viewtopic.php?t=501')));
        $this->assertSame(201, $created['status'], $created['body']);
        $id = AppState::topics()[0]['id'];
        \TorrentMonitor\Data\Topics::update($id, array('updatedAt' => '2026-11-23 14:23:00'));

        $topic = Env::ui('overview')['json']['topics'][0];
        $this->assertSame('2026-11-23 14:23:00', $topic['updatedAt'], 'the stored date is the clock of the tracker, unchanged');
        $this->assertSame('2026-11-23T11:23:00Z', $topic['updatedAtUtc']);
        $this->assertSame('МСК', $topic['zone']);
        $this->assertSame('2026-11-23T11:23:00Z', Env::api('GET', 'torrents/'.$id)['json']['data']['updatedAtUtc']);

        // another zone for all trackers
        $this->assertTrue(Env::ui('settings.save', array('trackerTimezone' => 'Europe/Kaliningrad'))['json']['ok']);
        $this->assertSame('2026-11-23T12:23:00Z', Env::ui('overview')['json']['topics'][0]['updatedAtUtc']);

        // a zone of its own for one tracker wins
        $saved = Env::ui('tracker.save', array('name' => 'rutracker.org', 'login' => Env::TRACKER_USER, 'timezone' => 'Asia/Yekaterinburg'));
        $this->assertTrue($saved['json']['ok'], $saved['body']);
        $topic = Env::ui('overview')['json']['topics'][0];
        $this->assertSame(array('2026-11-23T09:23:00Z', 'UTC+5'), array($topic['updatedAtUtc'], $topic['zone']));
        $tracker = current(array_filter($saved['json']['trackers'], function ($t) { return $t['name'] == 'rutracker.org'; }));
        $this->assertSame('Asia/Yekaterinburg', $tracker['timezone']);

        // back to the general setting: nothing is left in the tracker's record
        Env::ui('tracker.save', array('name' => 'rutracker.org', 'login' => Env::TRACKER_USER, 'timezone' => ''));
        $this->assertSame('2026-11-23T12:23:00Z', Env::ui('overview')['json']['topics'][0]['updatedAtUtc']);

        // unknown zones are refused
        $this->assertFalse(Env::ui('settings.save', array('trackerTimezone' => 'Mars/Olympus'))['json']['ok']);
        $this->assertFalse(Env::ui('tracker.save', array('name' => 'rutracker.org', 'login' => Env::TRACKER_USER, 'timezone' => 'Mars/Olympus'))['json']['ok']);
        $this->assertSame('Europe/Kaliningrad', AppState::setting('trackerTimezone'));
    }

    public function testRequestLimitsAreSavedWithinBounds()
    {
        $this->assertSame(array('', '3'), array(AppState::setting('httpConcurrency'), AppState::setting('httpSites')), 'defaults: 10 per tracker, three trackers');
        $this->assertTrue(Env::ui('settings.save', array('httpConcurrency' => '4', 'httpSites' => '1'))['json']['ok']);
        $this->assertSame(array('4', '1'), array(AppState::setting('httpConcurrency'), AppState::setting('httpSites')));
        // out of bounds: brought back in
        Env::ui('settings.save', array('httpConcurrency' => '500', 'httpSites' => '99'));
        $this->assertSame(array('50', '10'), array(AppState::setting('httpConcurrency'), AppState::setting('httpSites')));
        Env::ui('settings.save', array('httpConcurrency' => '0', 'httpSites' => '0'));
        $this->assertSame(array('1', '3'), array(AppState::setting('httpConcurrency'), AppState::setting('httpSites')));
        // empty: the default again
        Env::ui('settings.save', array('httpConcurrency' => ''));
        $this->assertSame('', AppState::setting('httpConcurrency'));
    }

    public function testNetworkSettingsAreChecked()
    {
        // nothing but the internet to check
        $checked = Env::ui('network.test');
        $this->assertTrue($checked['json']['ok'], $checked['body']);
        $this->assertStringContainsString('Подключение к интернету есть.', $checked['json']['message']);
        $this->assertStringContainsString('Решатель Cloudflare не задан.', $checked['json']['message']);

        // a solver that answers; no page is opened through it
        $this->assertTrue(Env::ui('settings.save', array('flaresolverrUrl' => Env::SOLVER))['json']['ok']);
        $checked = Env::ui('network.test');
        $this->assertTrue($checked['json']['ok'], $checked['body']);
        $this->assertStringContainsString('Решатель Cloudflare '.Env::SOLVER.' отвечает', $checked['json']['message']);
        $this->assertSame(array(), Env::fakeRequests('/solver/v1'), 'the solver is not asked to open anything');

        // one that is not there
        Env::ui('settings.save', array('flaresolverrUrl' => 'http://127.0.0.1:9'));
        $checked = Env::ui('network.test');
        $this->assertFalse($checked['json']['ok']);
        $this->assertStringContainsString('Решатель Cloudflare http://127.0.0.1:9 не отвечает', $checked['json']['message']);
        $this->assertStringContainsString('Подключение к интернету есть.', $checked['json']['message'], 'what works is reported too');

        // a proxy that does not answer
        Env::ui('settings.save', array('flaresolverrUrl' => '', 'proxy' => true, 'proxyAddress' => '127.0.0.1:1', 'proxyType' => 'HTTP'));
        $checked = Env::ui('network.test');
        $this->assertFalse($checked['json']['ok']);
        $this->assertStringContainsString('Через прокси 127.0.0.1:1 интернет недоступен', $checked['json']['message']);
    }

    public function testOnlyKnownSettingsAreAccepted()
    {
        $before = AppState::setting('ApiKey');
        Env::ui('settings.save', array('ApiKey' => 'stolen', 'password' => 'x', 'unknown' => '1', 'httpTimeout' => '0', 'stopAfterDays' => '-5'));

        $this->assertSame($before, AppState::setting('ApiKey'));
        $this->assertNull(AppState::setting('unknown'));
        $this->assertSame('1', AppState::setting('httpTimeout'), 'the timeout cannot be zero');
        $this->assertSame('0', AppState::setting('stopAfterDays'));
    }

    public function testQuotesInValuesSurvive()
    {
        $value = 'Agent "quoted" \'single\' <tag> &amp;';
        Env::ui('settings.save', array('userAgent' => $value));
        $this->assertSame($value, Env::ui('overview')['json']['settings']['userAgent']);
    }

    public function testNotificationServiceAndAddressAreSaved()
    {
        $response = Env::ui('settings.save', array('send' => true, 'sendUpdate' => true, 'notifyUpdateService' => 'Telegram', 'notifyUpdateAddress' => 'token;123',
            'sendWarning' => true, 'notifyWarningService' => 'Gotify', 'notifyWarningAddress' => 'http://gotify;abc'));

        $settings = $response['json']['settings'];
        $this->assertSame(array('Telegram', 'token;123', 'Gotify', 'http://gotify;abc'),
            array($settings['notifyUpdateService'], $settings['notifyUpdateAddress'], $settings['notifyWarningService'], $settings['notifyWarningAddress']));
        $config = json_decode(AppState::file('config.json'), true);
        $this->assertSame('11', $config['settings']['sendUpdateService'], 'stored as the service number, as before');
        $this->assertSame('token;123', $config['notifications']['11']);
    }

    public function testClientConnectionCheck()
    {
        AppState::useQbittorrent();
        $this->assertTrue(Env::ui('client.test', array('id' => 1))['json']['ok']);

        AppState::useQbittorrent(array('torrentPassword' => 'wrong'));
        $bad = Env::ui('client.test', array('id' => 1))['json'];
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('логин или пароль', $bad['message']);

        AppState::useTransmission();
        $this->assertTrue(Env::ui('client.test', array('id' => 1))['json']['ok']);
        AppState::useTransmission(array('torrentAddress' => 'http://127.0.0.1:1'));
        $this->assertFalse(Env::ui('client.test', array('id' => 1))['json']['ok']);
    }

    public function testApiKeyIsShownOnlyOnRequestAndCanBeReplaced()
    {
        $overview = Env::ui('overview');
        $this->assertStringNotContainsString(Env::API_TOKEN, $overview['body']);
        $this->assertSame(Env::API_TOKEN, Env::ui('settings.showkey')['json']['key']);

        $new = Env::ui('settings.apikey')['json']['key'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $new);
        $this->assertSame(401, Http::get(Env::APP.'/api/torrents?token='.Env::API_TOKEN)['status']);
        $this->assertSame(200, Http::get(Env::APP.'/api/torrents?token='.$new)['status']);
    }
}
