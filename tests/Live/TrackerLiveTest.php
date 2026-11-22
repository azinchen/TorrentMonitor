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

    public function trackers()
    {
        $s = Env::secrets('trackers');
        $get = function ($key) use ($s) { return isset($s[$key]) ? $s[$key] : ''; };
        $kinozal = $get('KINOZAL_DOMAIN') ?: 'kinozal.me';
        return array(
            'rutracker' => array('rutracker.org', $get('RUTRACKER_LOGIN'), $get('RUTRACKER_PASSWORD'), $get('RUTRACKER_TOPIC'),
                'https://rutracker.org/forum/viewtopic.php?t='),
            'kinozal'   => array($kinozal, $get('KINOZAL_LOGIN'), $get('KINOZAL_PASSWORD'), $get('KINOZAL_TOPIC'),
                'https://'.$kinozal.'/details.php?id='),
        );
    }

    /** @dataProvider trackers */
    public function testTopicIsCheckedAndTorrentDownloaded($tracker, $login, $password, $topic, $urlPrefix)
    {
        if ($login === '' || $password === '' || $topic === '')
            $this->markTestSkipped('no account for '.$tracker.' in tests/env/secrets/trackers.env');

        AppState::setCredentials($tracker, $login, $password);
        $created = Env::api('POST', 'torrents', array('json' => array('url' => $urlPrefix.$topic)));
        $this->assertSame(201, $created['status'], $created['body']);

        $output = Env::runEngine();

        $stored = AppState::topics()[0];
        $this->assertSame(array(), AppState::warningReasons(), "engine output:\n".Scrubber::scrub($output, Env::secrets('trackers')));
        $this->assertNotSame('0000-00-00 00:00:00', $stored['timestamp'], 'date of the torrent was read from the page');
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
