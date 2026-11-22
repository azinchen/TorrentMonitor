<?php
use PHPUnit\Framework\TestCase;

// Real trackers with real accounts from tests/env/secrets/trackers.env.
// Each test logs in, checks one topic and downloads its .torrent; nothing is
// sent to a torrent client. A tracker without a login in the secrets is skipped.
final class TrackerLiveTest extends TestCase
{
    protected function setUp(): void
    {
        if ( ! Env::isLive())
            $this->markTestSkipped('live tests run only through "tests/run.sh live"');
        Env::resetApp();
        AppState::setSettings(array('flaresolverrUrl' => (string)getenv('TM_SOLVER')));
    }

    // only names here: PHPUnit prints data set values, and they must not contain logins
    public function trackers()
    {
        return array('rutracker' => array('rutracker'), 'kinozal' => array('kinozal'));
    }

    private function account($name)
    {
        $s = Env::secrets('trackers');
        $get = function ($key) use ($s) { return isset($s[$key]) ? $s[$key] : ''; };
        if ($name == 'rutracker')
            return array('rutracker.org', $get('RUTRACKER_LOGIN'), $get('RUTRACKER_PASSWORD'), $get('RUTRACKER_TOPIC'),
                'https://rutracker.org/forum/viewtopic.php?t=');
        $kinozal = $get('KINOZAL_DOMAIN') ?: 'kinozal.me';
        return array($kinozal, $get('KINOZAL_LOGIN'), $get('KINOZAL_PASSWORD'), $get('KINOZAL_TOPIC'),
            'https://'.$kinozal.'/details.php?id=');
    }

    /** @dataProvider trackers */
    public function testTopicIsCheckedAndTorrentDownloaded($name)
    {
        list($tracker, $login, $password, $topic, $urlPrefix) = $this->account($name);
        if ($login === '' || $password === '' || $topic === '')
            $this->markTestSkipped('no login, password or topic for '.$tracker.' in tests/env/secrets/trackers.env');

        AppState::setCredentials($tracker, $login, $password);
        $created = Env::api('POST', 'torrents', array('json' => array('url' => $urlPrefix.$topic)));
        $this->assertSame(201, $created['status'], $created['body']);

        $output = Env::runEngine();

        $stored = AppState::topics()[0];
        $this->assertSame(array(), AppState::warningReasons(), "engine output:\n".Scrubber::scrub($output, array('LOGIN' => $login, 'PASSWORD' => $password)));
        $this->assertNotSame('', $stored['timestamp'], 'date of the torrent was read from the page');
        $this->assertNotSame('', $stored['name'], 'topic name was read from the page');

        $files = glob('/app/torrents/*.torrent');
        $this->assertCount(1, $files, 'one .torrent file was saved');
        $torrent = file_get_contents($files[0]);
        $this->assertStringStartsWith('d', $torrent);
        $this->assertStringContainsString('4:info', $torrent);

        // a second run with the stored session must not download again
        Env::runEngine();
        $this->assertSame(array(), AppState::warningReasons());
        $this->assertSame($stored['timestamp'], AppState::topics()[0]['timestamp']);
    }
}
