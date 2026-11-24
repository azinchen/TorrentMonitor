<?php
use PHPUnit\Framework\TestCase;

// Real notification services and a real Synology NAS.
//   - made-up keys: need no secrets; every real service is asked and must refuse them, and the
//     interface must show the service's own answer;
//   - real keys from tests/env/secrets/services.env: one message is sent through the saved
//     settings (whether it reached the phone is for a person to see), then a spoiled key is refused.
//     A service that is not filled in is skipped.
final class ServicesLiveTest extends TestCase
{
    private $secrets;

    protected function setUp(): void
    {
        if ( ! Env::isLive())
            $this->markTestSkipped('live tests run only through "tests/run.sh live"');
        Env::resetApp();
        $this->secrets = Env::secrets('services');
    }

    private function secret($key)
    {
        return isset($this->secrets[$key]) ? $this->secrets[$key] : '';
    }

    // service => keys of the secrets that make its address (joined with ";"), a made-up address,
    // and what the real service answers to it
    public function services()
    {
        return array(
            'Telegram'   => array('Telegram', array('TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT'), '123456:made-up-token;1', '/HTTP 40[14]: (Unauthorized|Not Found)/'),
            'Pushover'   => array('Pushover', array('PUSHOVER_USER', 'PUSHOVER_TOKEN'), 'madeupuser;madeuptoken', '/HTTP 400: .*token is invalid/'),
            'Pushbullet' => array('Pushbullet', array('PUSHBULLET_TOKEN'), 'made-up-token', '/HTTP 401: Access token is missing or invalid/'),
            'Prowl'      => array('Prowl', array('PROWL_KEY'), str_repeat('0', 40), '/HTTP 401: Invalid API key/'),
            'Pushall'    => array('Pushall', array('PUSHALL_ID', 'PUSHALL_KEY'), '1;made-up-key', '/HTTP 200: wrong key/'),
            'Gotify'     => array('Gotify', array('GOTIFY_ADDRESS', 'GOTIFY_TOKEN'), null, '/HTTP 401/'),
        );
    }

    // saves the address for error notifications and presses «Сохранить и проверить»
    private function check($service, $address)
    {
        $saved = Env::ui('settings.save', array('send' => true, 'sendUpdate' => false, 'sendWarning' => true,
            'notifyWarningService' => $service, 'notifyWarningAddress' => $address))['json'];
        $this->assertTrue($saved['ok'], 'the settings were saved');
        return Env::ui('notify.check')['json'];
    }

    // Made-up keys: needs no secrets. The real service must refuse them, and its own answer must
    // be what the interface shows. Nothing is delivered to anyone.
    /** @dataProvider services */
    public function testMadeUpKeysAreRefusedWithTheAnswerOfTheService($service, $keys, $fake, $answer)
    {
        if ($fake === null)
            $this->markTestSkipped($service.' has no public server to refuse a made-up key: the integration tests cover it');
        $refused = $this->check($service, $fake);
        $this->assertFalse($refused['ok'], $service.' reported success with a made-up key');
        $this->assertStringStartsWith('Об ошибках ('.$service.'): сервис не принял сообщение — ', $refused['message']);
        $this->assertMatchesRegularExpression($answer, $refused['message']);

        // an address that lacks a part is not sent anywhere
        if (strpos($fake, ';') !== false)
        {
            $incomplete = $this->check($service, explode(';', $fake)[0]);
            $this->assertFalse($incomplete['ok']);
            $this->assertStringContainsString('адрес указан не полностью', $incomplete['message']);
        }
    }

    // Real keys from tests/env/secrets/services.env: one message is really sent through the saved
    // settings, then the key is spoiled and the service must refuse it.
    /** @dataProvider services */
    public function testRealKeysDeliverAMessageAndASpoiledKeyIsRefused($service, $keys, $fake, $answer)
    {
        $values = array_map(array($this, 'secret'), $keys);
        if (in_array('', $values, true))
            $this->markTestSkipped('no '.implode(', ', $keys).' in tests/env/secrets/services.env');
        $hide = array_combine($keys, $values);

        $sent = $this->check($service, implode(';', $values));
        $this->assertTrue($sent['ok'], Scrubber::scrub(json_encode($sent, JSON_UNESCAPED_UNICODE), $hide));
        $this->assertSame('Об ошибках ('.$service.'): сообщение отправлено.', $sent['message']);

        // the last value is the key or the chat: spoiled, it must be reported as a failure with a reason
        $values[count($values) - 1] = 'wrong'.substr(md5($values[count($values) - 1]), 0, 12);
        $refused = $this->check($service, implode(';', $values));
        $this->assertFalse($refused['ok'], $service.' reported success with a wrong key');
        $this->assertMatchesRegularExpression('/сервис не принял сообщение — HTTP \d+/u', Scrubber::scrub($refused['message'], $hide));

        // nothing of the keys is in the journal of events
        $journal = (string)@file_get_contents(getenv('TM_DATA_DIR').'/journal.log');
        foreach ($hide as $value)
            $this->assertStringNotContainsString($value, $journal);
    }

    // a link to a small legal torrent the NAS can fetch from the internet
    private function torrentUrl()
    {
        if ($this->secret('SYNOLOGY_TORRENT_URL') !== '')
            return $this->secret('SYNOLOGY_TORRENT_URL');
        $folder = 'https://cdimage.debian.org/debian-cd/current/amd64/bt-cd/';
        $listing = Http::get($folder, array('timeout' => 30))['body'];
        if ( ! preg_match('/href="(debian-[^"]+netinst\.iso\.torrent)"/', $listing, $m))
            $this->fail('no torrent found at '.$folder.': set SYNOLOGY_TORRENT_URL');
        return $folder.$m[1];
    }

    public function testSynologyTakesATorrentByLinkAndRemovesIt()
    {
        if ($this->secret('SYNOLOGY_ADDRESS') === '' || $this->secret('SYNOLOGY_LOGIN') === '')
            $this->markTestSkipped('no SYNOLOGY_ADDRESS and SYNOLOGY_LOGIN in tests/env/secrets/services.env');
        $hide = array('SYNOLOGY_LOGIN' => $this->secret('SYNOLOGY_LOGIN'), 'SYNOLOGY_PASSWORD' => $this->secret('SYNOLOGY_PASSWORD'));
        AppState::setSettings(array('useTorrent' => '1', 'torrentClient' => 'SynologyDS', 'torrentAddress' => $this->secret('SYNOLOGY_ADDRESS'),
            'torrentLogin' => $this->secret('SYNOLOGY_LOGIN'), 'torrentPassword' => $this->secret('SYNOLOGY_PASSWORD')));

        $check = Env::ui('client.test')['json'];
        $this->assertTrue($check['ok'], Scrubber::scrub(json_encode($check, JSON_UNESCAPED_UNICODE), $hide));

        require_once TM_ROOT.'/src/bootstrap.php';
        \TorrentMonitor\App::resetHttp();
        $client = \TorrentMonitor\Client\Clients::current();
        $url = $this->torrentUrl();
        $id = $client->add('', array('url' => $url, 'path' => $this->secret('SYNOLOGY_PATH'), 'name' => 'tm-live-test'));
        try
        {
            $this->assertNotSame('', $id, 'the id of the created task was found by its link');
            try
            {
                $client->add('', array('url' => $url, 'path' => $this->secret('SYNOLOGY_PATH'), 'name' => 'tm-live-test'));
                $this->fail('the same link was accepted twice');
            }
            catch (\TorrentMonitor\Client\ClientException $e)
            {
                $this->assertSame('duplicate_torrent', $e->getMessage());
            }
        }
        finally
        {
            if ($id !== '')
                $client->remove($id, true);
        }
        // the link can be added again only if the task is really gone
        $again = $client->add('', array('url' => $url, 'path' => $this->secret('SYNOLOGY_PATH'), 'name' => 'tm-live-test'));
        $client->remove($again, true);
        $this->assertNotSame('', $again, 'the task was removed');
    }
}
