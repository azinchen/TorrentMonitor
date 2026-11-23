<?php
use PHPUnit\Framework\TestCase;

// Real notification services and a real Synology NAS, with the keys from
// tests/env/secrets/services.env. A service that is not filled in is skipped.
// Every notification test sends one message; whether it reached the phone is for a person to see.
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

    // service => keys that make its address, joined with ";"
    public function services()
    {
        return array(
            'Telegram'   => array('Telegram', array('TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT')),
            'Pushover'   => array('Pushover', array('PUSHOVER_USER', 'PUSHOVER_TOKEN')),
            'Pushbullet' => array('Pushbullet', array('PUSHBULLET_TOKEN')),
            'Prowl'      => array('Prowl', array('PROWL_KEY')),
            'Pushall'    => array('Pushall', array('PUSHALL_ID', 'PUSHALL_KEY')),
            'Gotify'     => array('Gotify', array('GOTIFY_ADDRESS', 'GOTIFY_TOKEN')),
        );
    }

    /** @dataProvider services */
    public function testServiceAcceptsAMessageAndRefusesAWrongKey($service, $keys)
    {
        $values = array_map(array($this, 'secret'), $keys);
        if (in_array('', $values, true))
            $this->markTestSkipped('no '.implode(', ', $keys).' in tests/env/secrets/services.env');
        $hide = array_combine($keys, $values);

        $sent = Env::ui('notify.test', array('service' => $service, 'address' => implode(';', $values)))['json'];
        $this->assertTrue($sent['ok'], Scrubber::scrub(json_encode($sent, JSON_UNESCAPED_UNICODE), $hide));

        // the last value is the key or the chat: spoiled, it must be reported as a failure
        $values[count($values) - 1] = 'wrong'.substr(md5($values[count($values) - 1]), 0, 12);
        $refused = Env::ui('notify.test', array('service' => $service, 'address' => implode(';', $values)))['json'];
        $this->assertFalse($refused['ok'], $service.' reported success with a wrong key');
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
