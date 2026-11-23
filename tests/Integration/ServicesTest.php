<?php
// Torrent clients and notification services that can run locally without an account:
// Deluge, TorrServer, Gotify and e-mail (messages are saved to files instead of being sent).
final class ServicesTest extends IntegrationTestCase
{
    private function useClient($name, $address, $password = '', $path = '')
    {
        AppState::setSettings(array('useTorrent' => '1', 'torrentClient' => $name, 'torrentAddress' => $address,
            'torrentLogin' => '', 'torrentPassword' => $password, 'pathToDownload' => $path));
    }

    // the application's own client object, to ask the client what it has
    private function client()
    {
        require_once TM_ROOT.'/src/bootstrap.php';
        \TorrentMonitor\App::resetHttp();
        return \TorrentMonitor\Client\Clients::current();
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

    public function testClientCheckTellsAWorkingTorrServerFromAWrongAddress()
    {
        $this->useClient('TorrServer', Env::TORRSERVER);
        $this->assertTrue(Env::ui('client.test')['json']['ok']);

        $this->useClient('TorrServer', 'http://torrserver:1');
        $this->assertFalse(Env::ui('client.test')['json']['ok'], 'a wrong address is not reported as working');

        // some other web server answers at the address
        $this->useClient('TorrServer', Env::GOTIFY.'/nothing-here');
        $this->assertFalse(Env::ui('client.test')['json']['ok'], 'another service is not taken for TorrServer');

        $this->useClient('Deluge', Env::DELUGE, Env::DELUGE_PASS);
        $this->assertTrue(Env::ui('client.test')['json']['ok']);
        $this->useClient('Deluge', Env::DELUGE, 'wrong');
        $this->assertFalse(Env::ui('client.test')['json']['ok']);
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
