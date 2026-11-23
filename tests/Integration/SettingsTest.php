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

    public function testTorrentClientSettingsAreSaved()
    {
        $response = Env::ui('settings.save', array(
            'useTorrent' => true, 'torrentClient' => 'qBittorrent',
            'torrentAddress' => Env::QBT.'/', 'torrentLogin' => 'someone', 'torrentPassword' => 'pa$$ word',
            'pathToDownload' => '/downloads/x', 'deleteDistribution' => false, 'deleteOldFiles' => true,
            'deleteFilesOnRemove' => true, 'qbitCategory' => 'tv',
        ));

        $this->assertTrue($response['json']['ok'], $response['body']);
        $this->assertSame('1', AppState::setting('useTorrent'));
        $this->assertSame(Env::QBT, AppState::setting('torrentAddress'), 'trailing slash is removed');
        $this->assertSame('/downloads/x/', AppState::setting('pathToDownload'), 'trailing slash is added');
        $this->assertSame('pa$$ word', AppState::setting('torrentPassword'));
        $this->assertSame('1', AppState::setting('deleteOldFiles'));
        $this->assertSame('0', AppState::setting('deleteDistribution'));
        $this->assertSame('1', AppState::setting('deleteFilesOnRemove'));
        $this->assertSame('tv', AppState::setting('qbitCategory'));
        $this->assertTrue($response['json']['settings']['hasTorrentPassword']);
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
        $this->assertTrue(Env::ui('client.test')['json']['ok']);

        AppState::useQbittorrent(array('torrentPassword' => 'wrong'));
        $bad = Env::ui('client.test')['json'];
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('логин или пароль', $bad['message']);

        AppState::useTransmission();
        $this->assertTrue(Env::ui('client.test')['json']['ok']);
        AppState::useTransmission(array('torrentAddress' => 'http://127.0.0.1:1'));
        $this->assertFalse(Env::ui('client.test')['json']['ok']);
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
