<?php
// Torrent clients and notification services that can run locally without an account:
// Deluge, TorrServer, Gotify and e-mail (messages are saved to files instead of being sent).
final class ServicesTest extends IntegrationTestCase
{
    private function useClient($name, $address, $password = '', $path = '')
    {
        AppState::useClient($name, $address, '', $password, $path);
    }

    // the application's own client object, to ask the client what it has
    private function client()
    {
        require_once TM_ROOT.'/src/bootstrap.php';
        \TorrentMonitor\App::resetHttp();
        return \TorrentMonitor\Client\Clients::open(\TorrentMonitor\Client\Clients::fallback());
    }

    // ---- Deluge

    public function testTopicIsSentToDelugeAndReplacedOnUpdate()
    {
        $this->loginToTracker();
        @mkdir(Env::DOWNLOADS.'/deluge', 0777, true);
        @chmod(Env::DOWNLOADS.'/deluge', 0777);
        $this->useClient('Deluge', Env::DELUGE, Env::DELUGE_PASS, Env::DOWNLOADS.'/deluge/');
        Env::setTopic(501, 'Сериал', '01-Янв-26 12:00', 1);
        $id = $this->trackTopic(501);

        $output = Env::runEngine();

        $first = TorrentBuilder::infoHash(501, 1);
        $this->assertSame(array(), AppState::warningReasons(), $output);
        $this->assertSame($first, strtolower(AppState::topic($id)['hash']));
        $info = $this->client()->info($first);
        $this->assertNotNull($info, 'Deluge has the torrent');
        $this->assertSame(Env::DOWNLOADS.'/deluge', rtrim($info['path'], '/'));

        // the page of topics is told what the client is doing with the torrent
        $status = Env::ui('client.status', array('id' => 1))['json'];
        $this->assertTrue($status['supported'] && $status['answered'], json_encode($status));
        $torrent = $status['torrents'][(string)$id];
        $this->assertContains($torrent['state'], array('downloading', 'seeding', 'stopped', 'queued', 'checking', 'error'), json_encode($torrent));
        $this->assertGreaterThanOrEqual(0, $torrent['progress']);
        $this->assertLessThanOrEqual(100, $torrent['progress']);
        $this->assertGreaterThan(0, $torrent['size']);

        Env::setTopic(501, 'Сериал', '08-Янв-26 19:45', 2);
        Env::runEngine();

        $second = TorrentBuilder::infoHash(501, 2);
        $this->assertNoWarnings();
        $this->assertSame($second, strtolower(AppState::topic($id)['hash']));
        $client = $this->client();
        $this->assertNotNull($client->info($second));
        $this->assertNull($client->info($first), 'old torrent is removed');

        $client->remove($second, true);
    }

    public function testWrongDelugePasswordIsReported()
    {
        $this->loginToTracker();
        $this->useClient('Deluge', Env::DELUGE, 'wrong');
        Env::setTopic(502, 'Сериал', '01-Янв-26 12:00', 1);
        $this->trackTopic(502);

        Env::runEngine();

        $this->assertContains('Deluge:log_passwd', AppState::warningReasons());
    }

    // ---- TorrServer

    private function torrServerHashes()
    {
        $list = (array)Http::postJson(Env::TORRSERVER.'/torrents', array('action' => 'list'))['json'];
        return array_map(function ($t) { return strtolower($t['hash']); }, $list);
    }

    public function testTopicIsSentToTorrServerAndReplacedOnUpdate()
    {
        $this->loginToTracker();
        $this->useClient('TorrServer', Env::TORRSERVER);
        Env::setTopic(511, 'Сериал', '01-Янв-26 12:00', 1);
        $id = $this->trackTopic(511);

        $output = Env::runEngine();

        $first = TorrentBuilder::infoHash(511, 1);
        $this->assertSame(array(), AppState::warningReasons(), $output);
        $this->assertSame($first, strtolower(AppState::topic($id)['hash']));
        $this->assertContains($first, $this->torrServerHashes());

        Env::setTopic(511, 'Сериал', '08-Янв-26 19:45', 2);
        Env::runEngine();

        $second = TorrentBuilder::infoHash(511, 2);
        $this->assertNoWarnings();
        $this->assertContains($second, $this->torrServerHashes());
        $this->assertNotContains($first, $this->torrServerHashes(), 'old torrent is removed');

        $this->client()->remove($second, false);
    }

    public function testClientsWithoutAListOfTorrentsHaveNoStatus()
    {
        $this->useClient('TorrServer', Env::TORRSERVER, '', '');
        $status = Env::ui('client.status', array('id' => 1))['json'];
        $this->assertFalse($status['supported']);
        $this->assertFalse($status['answered']);
        $this->assertSame('', $status['error']);

        // no client at all
        AppState::setSettings(array('useTorrent' => '0'));
        $none = Env::ui('client.status', array('id' => 1))['json'];
        $this->assertNull($none['client']);
        $this->assertFalse($none['supported']);
    }

    public function testClientCheckTellsAWorkingTorrServerFromAWrongAddress()
    {
        $this->useClient('TorrServer', Env::TORRSERVER);
        $this->assertTrue(Env::ui('client.test', array('id' => 1))['json']['ok']);

        $this->useClient('TorrServer', 'http://torrserver:1');
        $this->assertFalse(Env::ui('client.test', array('id' => 1))['json']['ok'], 'a wrong address is not reported as working');

        // some other web server answers at the address
        $this->useClient('TorrServer', Env::GOTIFY.'/nothing-here');
        $this->assertFalse(Env::ui('client.test', array('id' => 1))['json']['ok'], 'another service is not taken for TorrServer');

        $this->useClient('Deluge', Env::DELUGE, Env::DELUGE_PASS);
        $this->assertTrue(Env::ui('client.test', array('id' => 1))['json']['ok']);
        $this->useClient('Deluge', Env::DELUGE, 'wrong');
        $this->assertFalse(Env::ui('client.test', array('id' => 1))['json']['ok']);
    }

    // ---- Gotify

    private function gotify($method, $path, $body = null)
    {
        $options = array('auth' => 'admin:'.Env::CLIENT_PASS);
        if ($body !== null)
            $options += array('body' => json_encode($body), 'headers' => array('Content-Type: application/json'));
        return Http::request($method, Env::GOTIFY.$path, $options);
    }

    public function testGotifyReceivesTheTestMessage()
    {
        $application = $this->gotify('POST', '/application', array('name' => 'tm-'.uniqid()))['json'];
        $this->assertNotEmpty($application['token']);

        $sent = Env::ui('notify.test', array('service' => 'Gotify', 'address' => Env::GOTIFY.';'.$application['token']))['json'];
        $this->assertTrue($sent['ok'], json_encode($sent, JSON_UNESCAPED_UNICODE));

        $messages = $this->gotify('GET', '/application/'.$application['id'].'/message')['json']['messages'];
        $this->assertCount(1, $messages);
        $this->assertSame('Проверка.', $messages[0]['title']);
        $this->assertStringContainsString('Проверка уведомлений', $messages[0]['message']);

        $refused = Env::ui('notify.test', array('service' => 'Gotify', 'address' => Env::GOTIFY.';wrong-token'))['json'];
        $this->assertFalse($refused['ok'], 'a wrong token is reported');
    }

    public function testSavedNotificationSettingsAreChecked()
    {
        // off: nothing to check
        $this->assertFalse(Env::ui('notify.check')['json']['ok']);

        $application = $this->gotify('POST', '/application', array('name' => 'tm-'.uniqid()))['json'];
        $address = Env::GOTIFY.';'.$application['token'];
        $save = function ($values) { return Env::ui('settings.save', $values)['json']['ok']; };

        // both kinds go to one place: one test message
        $this->assertTrue($save(array('send' => true, 'sendUpdate' => true, 'notifyUpdateService' => 'Gotify', 'notifyUpdateAddress' => $address,
            'sendWarning' => true, 'notifyWarningService' => 'Gotify', 'notifyWarningAddress' => $address)));
        $checked = Env::ui('notify.check')['json'];
        $this->assertTrue($checked['ok'], json_encode($checked, JSON_UNESCAPED_UNICODE));
        $this->assertSame('Об обновлениях (Gotify): сообщение отправлено. Об ошибках (Gotify): сообщение отправлено.', $checked['message']);
        $this->assertCount(1, $this->gotify('GET', '/application/'.$application['id'].'/message')['json']['messages']);

        // a wrong token for one kind: the answer of the service is shown, the other kind still reported
        $this->assertTrue($save(array('notifyWarningService' => 'Gotify', 'notifyWarningAddress' => Env::GOTIFY.';wrong-token')));
        $checked = Env::ui('notify.check')['json'];
        $this->assertFalse($checked['ok']);
        $this->assertStringContainsString('Об обновлениях (Gotify): сообщение отправлено.', $checked['message']);
        $this->assertMatchesRegularExpression('/Об ошибках \(Gotify\): сервис не принял сообщение — HTTP 401: Unauthorized/u', $checked['message']);

        // a server that is not there, an address without its token, e-mail
        $this->assertTrue($save(array('notifyWarningService' => 'Gotify', 'notifyWarningAddress' => 'http://127.0.0.1:9;token')));
        $this->assertStringContainsString('сервис не отвечает', Env::ui('notify.check')['json']['message']);
        $this->assertTrue($save(array('notifyWarningService' => 'Gotify', 'notifyWarningAddress' => Env::GOTIFY)));
        $this->assertStringContainsString('Об ошибках (Gotify): сервис не принял сообщение — адрес указан не полностью.', Env::ui('notify.check')['json']['message']);
        $this->assertTrue($save(array('sendUpdate' => false, 'notifyWarningService' => 'E-mail', 'notifyWarningAddress' => 'someone@example.invalid')));
        $this->assertSame('Об ошибках (E-mail): сообщение передано почтовой программе сервера.', Env::ui('notify.check')['json']['message']);

        // the checks are in the journal, without the token
        $lines = array_values(array_filter(AppState::journal('service'), function ($l) { return strpos($l, 'Уведомления проверены') === 0; }));
        $this->assertCount(5, $lines);
        $this->assertStringNotContainsString($application['token'], implode("\n", AppState::journal()));
    }

    // ---- e-mail

    public function testEmailIsHandedToTheMailSystem()
    {
        foreach ((array)glob(Env::MAIL_DIR.'/*.eml') as $file)
            unlink($file);

        $sent = Env::ui('notify.test', array('service' => 'E-mail', 'address' => 'someone@example.invalid'))['json'];
        $this->assertTrue($sent['ok'], json_encode($sent, JSON_UNESCAPED_UNICODE));

        $files = glob(Env::MAIL_DIR.'/*.eml');
        $this->assertCount(1, $files);
        $mail = file_get_contents($files[0]);
        $this->assertStringContainsString('To: someone@example.invalid', $mail);
        $this->assertStringContainsString('=?UTF-8?B?'.base64_encode('TorrentMonitor: Проверка.').'?=', $mail);
        $this->assertStringContainsString('Content-type: text/html; charset=utf-8', $mail);
        $this->assertStringContainsString('Проверка уведомлений', $mail);
    }
}
