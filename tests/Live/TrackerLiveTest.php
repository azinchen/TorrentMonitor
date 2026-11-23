<?php
use PHPUnit\Framework\TestCase;

// Real trackers with real accounts from tests/env/secrets/trackers.env.
//
// For every tracker: log in, pick something to watch (a fresh topic from the tracker's own
// listing or a series from its feed, unless one is fixed in the secrets), let the engine
// download its .torrent and hand it to qBittorrent (which keeps it stopped), check that a
// second run downloads nothing, then turn the stored update mark back and check that the
// torrent is downloaded and delivered again.
//
// A tracker without an account in the secrets is skipped; trackers that need no account always run.
// Each run downloads two torrent files per tracker (three or four if the first topics are unusable).
final class TrackerLiveTest extends TestCase
{
    // how many topics of the listing are tried before the tracker is reported as failing
    const ATTEMPTS = 3;

    protected function setUp(): void
    {
        if ( ! Env::isLive())
            $this->markTestSkipped('live tests run only through "tests/run.sh live"');
        Env::resetApp();
        AppState::setSettings(array('flaresolverrUrl' => (string)getenv('TM_SOLVER'), 'httpTimeout' => '30'));
        AppState::useQbittorrent();
        Qbt::keepStopped();
        Qbt::removeAll();
    }

    protected function tearDown(): void
    {
        if (Env::isLive())
            Qbt::removeAll();
    }

    public function trackers()
    {
        return Live::names();
    }

    private function torrentFiles()
    {
        return glob('/app/torrents/*.torrent');
    }

    private function assertTorrentFile($file)
    {
        $torrent = file_get_contents($file);
        $this->assertStringStartsWith('d', $torrent);
        $this->assertStringContainsString('4:info', $torrent);
    }

    private function clearTorrentFiles()
    {
        foreach ($this->torrentFiles() as $file)
            unlink($file);
    }

    /** @dataProvider trackers */
    public function testUpdateIsDownloadedAndDownloadedAgain($name)
    {
        $account = Live::account($name);
        $missing = Live::missing($account);
        if ($missing !== NULL)
            $this->markTestSkipped($missing);
        $tracker = $account['tracker'];
        $hide = Live::secretsOf($account);
        Live::configure($account);
        $series = \TorrentMonitor\Tracker\Registry::kind($tracker) == 'series';

        // ---- choose what to watch and run the first check
        $log = array();
        $topic = NULL;
        if ($series)
        {
            $choice = Live::series($account);
            $this->assertNotNull($choice, 'no suitable series in the feed of '.$tracker.' ('.Live::$listing.')');
            $created = Env::api('POST', 'torrents', array('json' => array('tracker' => $tracker, 'name' => $choice[0], 'quality' => $choice[1])));
            $this->assertSame(201, $created['status'], Scrubber::scrub($created['body'], $hide));
            // only the newest episode is new: the episode before it counts as downloaded
            $id = AppState::topics()[0]['id'];
            \TorrentMonitor\Data\Topics::update($id, array('episode' => $choice[2]));
            $output = Env::runEngine();
            $log[] = $choice[0].' '.$choice[1].' after '.($choice[2] ?: 'nothing').":\n".$output;
            if (AppState::warningReasons() === array() && count($this->torrentFiles()) >= 1)
                $topic = AppState::topic($id);
            $rewind = array('episode' => $choice[2]);
        }
        else
        {
            $urls = Live::topicUrls($account, self::ATTEMPTS);
            $this->assertNotEmpty($urls, 'no topics found on the listing pages of '.$tracker.' ('.Live::$listing.')');
            foreach ($urls as $url)
            {
                $created = Env::api('POST', 'torrents', array('json' => array('url' => $url)));
                $this->assertSame(201, $created['status'], $url.': '.Scrubber::scrub($created['body'], $hide));
                $id = AppState::topics()[0]['id'];
                $output = Env::runEngine();
                $log[] = $url.":\n".$output;
                if (AppState::warningReasons() === array() && count($this->torrentFiles()) == 1)
                {
                    $topic = AppState::topic($id);
                    break;
                }
                // not every topic of a listing has a torrent: try the next one
                \TorrentMonitor\Data\Topics::delete($id);
                foreach (array_keys(\TorrentMonitor\Data\Warnings::counts()) as $source)
                    \TorrentMonitor\Data\Warnings::clear($source);
                $this->clearTorrentFiles();
            }
            $rewind = array('updatedAt' => '2000-01-01 00:00:00');
        }
        $this->assertNotNull($topic, "nothing could be checked and downloaded on $tracker:\n".Scrubber::scrub(implode("\n\n", $log), $hide));

        // ---- what the first run must have produced
        $this->assertNotSame('', $topic['updatedAt'], 'date of the release was read');
        $this->assertNotSame('', $topic['name'], 'name was read');
        if ($series)
            $this->assertMatchesRegularExpression('/^S\d{2}E\d{2}$/', $topic['episode']);
        foreach ($this->torrentFiles() as $file)
            $this->assertTorrentFile($file);
        $this->assertNotSame('', $topic['hash'], 'the torrent was handed to the client');
        $this->assertTrue(Qbt::has($topic['hash']), 'qBittorrent has the torrent');
        $session = \TorrentMonitor\Data\Accounts::cookie($tracker);
        if (\TorrentMonitor\Tracker\Registry::needsAccount($tracker))
            $this->assertNotSame('', $session, 'the session is saved');

        // ---- a second run finds nothing new and does not log in again
        $this->clearTorrentFiles();
        $output = Env::runEngine();
        $this->assertSame(array(), AppState::warningReasons(), Scrubber::scrub($output, $hide));
        $this->assertSame(array(), $this->torrentFiles(), 'nothing is downloaded when nothing changed');
        $this->assertSame($topic['updatedAt'], AppState::topic($topic['id'])['updatedAt']);
        $this->assertSame($session, \TorrentMonitor\Data\Accounts::cookie($tracker), 'the saved session is used again');

        // ---- the stored mark is turned back: the torrent is downloaded and delivered again
        \TorrentMonitor\Data\Topics::update($topic['id'], $rewind);
        $output = Env::runEngine();
        $again = AppState::topic($topic['id']);
        $this->assertSame(array(), AppState::warningReasons(), Scrubber::scrub($output, $hide));
        $this->assertNotEmpty($this->torrentFiles(), 'the torrent file is downloaded again');
        foreach ($this->torrentFiles() as $file)
            $this->assertTorrentFile($file);
        $this->assertSame($topic['updatedAt'], $again['updatedAt'], 'the date is the one of the tracker again');
        if ($series)
            $this->assertSame($topic['episode'], $again['episode']);
        $this->assertNotSame('', $again['hash']);
        $this->assertTrue(Qbt::has($again['hash']), 'qBittorrent has the torrent after the repeated download');
        $this->assertCount(1, Qbt::torrents(), 'the old torrent is replaced, not kept next to the new one');
    }

    // For trackers that have no account in the secrets: what a visitor without an account can
    // see is still read by the tracker's own code - the release date and the name on a topic page,
    // the link to the torrent file, the series feed. Nothing is downloaded.
    // TM_LIVE_GUEST=1 runs it for the trackers that have an account too.
    /** @dataProvider trackers */
    public function testPublicPagesAreReadWithoutAnAccount($name)
    {
        $account = Live::account($name);
        if ($account['skip'])
            $this->markTestSkipped('skipped in tests/env/secrets/trackers.env');
        $tracker = $account['tracker'];
        require_once TM_ROOT.'/src/bootstrap.php';
        if ( ! \TorrentMonitor\Tracker\Registry::needsAccount($tracker))
            $this->markTestSkipped($tracker.' needs no account: the full test covers it');
        if (Live::missing($account) === NULL && getenv('TM_LIVE_GUEST') !== '1')
            $this->markTestSkipped($tracker.' has an account: the full test covers it (TM_LIVE_GUEST=1 runs this one too)');
        $guest = Live::guest($tracker);
        if ($guest['no'] !== NULL)
            $this->markTestSkipped($tracker.': '.$guest['no']);

        // no account at all, whatever the secrets hold
        $account = array('login' => '', 'password' => '', 'passkey' => '', 'cookie' => '', 'topic' => '') + $account;
        Live::configure($account);

        if (\TorrentMonitor\Tracker\Registry::kind($tracker) == 'series')
        {
            $choice = Live::series($account, TRUE);
            $this->assertNotNull($choice, 'no suitable series in the public feed of '.$tracker.' ('.Live::$listing.')');
            $this->assertNotSame('', $choice[0]);
            $this->assertContains($choice[1], array('sd', '720', '1080'));
            return;
        }

        $urls = Live::topicUrls($account, 6, TRUE);
        $this->assertNotEmpty($urls, 'no topics found on the public listing pages of '.$tracker.' ('.Live::$listing.')');
        $seen = array();
        $good = NULL;
        foreach ($urls as $url)
        {
            $read = Live::guestTopic($tracker, $url);
            $seen[] = $url.': HTTP '.$read['status'].($read['challenged'] ? ', Cloudflare challenge' : '').', date '.($read['date'] ? 'found' : 'not found')
                .', name '.($read['title'] ? 'found' : 'not found').', torrent link '.($read['download'] ? 'found' : 'not found');
            // not every topic of a listing has a torrent: the first one that reads completely decides
            if (( ! $guest['date'] || $read['date'] !== NULL) && ! empty($read['title']) && ( ! $guest['download'] || $read['download']))
            {
                $good = $read;
                break;
            }
        }
        $this->assertNotNull($good, 'no topic of '.$tracker." could be read without an account:\n".implode("\n", $seen));
        $this->assertNotSame('', trim($good['title']));
        if ($guest['date'])
        {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $good['date'][0]);
            $this->assertGreaterThan('2005-01-01 00:00:00', $good['date'][0]);
            $this->assertLessThan(date('Y-m-d H:i:s', time() + 2 * 86400), $good['date'][0]);
        }
    }

    /** @dataProvider trackers */
    public function testWatchedUser($name)
    {
        $account = Live::account($name);
        $missing = Live::missing($account);
        if ($missing !== NULL)
            $this->markTestSkipped($missing);
        if ($account['watchUser'] === '')
            $this->markTestSkipped('no *_WATCH_USER for '.$account['tracker'].' in tests/env/secrets/trackers.env');
        Live::configure($account);
        $tracker = \TorrentMonitor\Tracker\Registry::get($account['tracker']);
        $tracker->login();
        $topics = $tracker->userTopics($account['watchUser']);
        $this->assertNotNull($topics, $account['tracker'].' cannot watch users');
        $this->assertNotEmpty($topics, 'topics of the user were found');
        foreach (array('section', 'topicId', 'title', 'date') as $field)
            $this->assertNotSame('', (string)$topics[0][$field], $field);
    }

    // Off by default: repeated failed logins make some trackers ask for a captcha.
    // Run with TM_LIVE_WRONG_PASSWORD=1.
    /** @dataProvider trackers */
    public function testWrongPasswordIsReportedAsSuch($name)
    {
        if (getenv('TM_LIVE_WRONG_PASSWORD') !== '1')
            $this->markTestSkipped('set TM_LIVE_WRONG_PASSWORD=1 to run');
        $account = Live::account($name);
        $missing = Live::missing($account);
        if ($missing !== NULL || ! \TorrentMonitor\Tracker\Registry::needsAccount($account['tracker']) || $account['login'] === '')
            $this->markTestSkipped($missing ?: 'no login to try a wrong password with');
        $account['password'] .= '-wrong';
        $account['cookie'] = '';
        Live::configure($account);
        try
        {
            \TorrentMonitor\Tracker\Registry::get($account['tracker'])->login();
            $this->fail('login with a wrong password succeeded');
        }
        catch (\TorrentMonitor\Tracker\TrackerException $e)
        {
            $this->assertSame('credential_wrong', $e->getMessage());
        }
    }
}
