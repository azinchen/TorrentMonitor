<?php
use PHPUnit\Framework\TestCase;

// Every integration test starts from a fresh application, an empty fake internet
// and empty torrent clients.
abstract class IntegrationTestCase extends TestCase
{
    const TOPIC = 'https://rutracker.org/forum/viewtopic.php?t=';

    protected function setUp(): void
    {
        Env::resetApp();
        Qbt::removeAll();
        Tr::removeAll();
        foreach (array('qbt', 'transmission') as $dir)
        {
            @mkdir(Env::DOWNLOADS.'/'.$dir, 0777, true);
            @chmod(Env::DOWNLOADS.'/'.$dir, 0777);
            foreach (glob(Env::DOWNLOADS.'/'.$dir.'/tm-test-*') as $file)
                @unlink($file);
        }
    }

    protected function loginToTracker()
    {
        AppState::setCredentials('rutracker.org', Env::TRACKER_USER, Env::TRACKER_PASS);
    }

    // adds a topic through the REST API and returns its id in TorrentMonitor
    protected function trackTopic($topicId)
    {
        $response = Env::api('POST', 'torrents', array('json' => array('url' => self::TOPIC.$topicId)));
        $this->assertSame(201, $response['status'], $response['body']);
        foreach (AppState::topics() as $topic)
            if ($topic['torrent_id'] == (string)$topicId)
                return (int)$topic['id'];
        $this->fail('topic '.$topicId.' was not stored');
    }

    // puts the payload of a topic revision where the client will look for it
    protected function placePayload($clientDir, $topicId, $revision)
    {
        $file = Env::DOWNLOADS.'/'.$clientDir.'/'.TorrentBuilder::fileName($topicId, $revision);
        file_put_contents($file, TorrentBuilder::payload($topicId, $revision));
        chmod($file, 0666);
        return $file;
    }

    protected function assertNoWarnings()
    {
        $this->assertSame(array(), AppState::warningReasons());
    }
}
