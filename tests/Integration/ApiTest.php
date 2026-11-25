<?php
// REST API: authentication and topic management.
final class ApiTest extends IntegrationTestCase
{
    public function testRequestWithoutTokenIsRejected()
    {
        $this->assertSame(401, Http::get(Env::APP.'/api/torrents')['status']);
        $this->assertSame(401, Http::get(Env::APP.'/api/torrents?token=wrong')['status']);
        $this->assertSame(401, Http::get(Env::APP.'/api/torrents', array('headers' => array('Authorization: Bearer wrong')))['status']);
    }

    public function testTokenIsAcceptedInHeaderAndInQuery()
    {
        $this->assertSame(200, Env::api('GET', 'torrents')['status']);
        $this->assertSame(200, Http::get(Env::APP.'/api/torrents?token='.Env::API_TOKEN)['status']);
    }

    public function testEmptyStoredTokenDisablesTheApi()
    {
        AppState::setSettings(array('ApiKey' => ''));
        $this->assertSame(401, Http::get(Env::APP.'/api/torrents?token=')['status']);
    }

    public function testTopicLifecycle()
    {
        $this->loginToTracker();

        $created = Env::api('POST', 'torrents', array('json' => array('url' => self::TOPIC.'501', 'category' => 'tv')));
        $this->assertSame(201, $created['status']);
        $this->assertSame(array('tracker' => 'rutracker.org', 'topicId' => '501'), array_intersect_key($created['json']['data'], array('tracker' => 1, 'topicId' => 1)));

        $duplicate = Env::api('POST', 'torrents', array('json' => array('url' => self::TOPIC.'501')));
        $this->assertSame(409, $duplicate['status']);

        $list = Env::api('GET', 'torrents')['json'];
        $this->assertTrue($list['ok']);
        $this->assertCount(1, $list['data']);
        $this->assertSame('rutracker.org', $list['data'][0]['tracker']);
        $this->assertSame('501', $list['data'][0]['topicId']);
        $id = $list['data'][0]['id'];

        $one = Env::api('GET', 'torrents/'.$id)['json'];
        $this->assertSame('501', $one['data']['topicId']);
        $this->assertSame('tv', $one['data']['category']);

        $this->assertSame(200, Env::api('DELETE', 'torrents/'.$id)['status']);
        $this->assertSame(404, Env::api('GET', 'torrents/'.$id)['status']);
        $this->assertSame(404, Env::api('DELETE', 'torrents/'.$id)['status']);
        $this->assertCount(0, Env::api('GET', 'torrents')['json']['data']);
    }

    public function testListCanBeFilteredByTracker()
    {
        $this->loginToTracker();
        Env::api('POST', 'torrents', array('json' => array('url' => self::TOPIC.'502')));

        $this->assertCount(1, Env::api('GET', 'torrents?tracker=rutracker.org')['json']['data']);
        $this->assertCount(0, Env::api('GET', 'torrents?tracker=kinozal.me')['json']['data']);
    }

    public function testTopicNeedsTrackerCredentialsRow()
    {
        // NNM-Club writes the address of a post into its torrent files: the topic is found by it
        $post = Env::api('POST', 'torrents', array('json' => array('url' => 'https://nnmclub.to/forum/viewtopic.php?p=13160487')));
        $this->assertSame(201, $post['status'], $post['body']);
        $this->assertSame('nnmclub.to', $post['json']['data']['tracker']);
        $this->assertSame('13161487', $post['json']['data']['topicId']);

        $response = Env::api('POST', 'torrents', array('json' => array('url' => 'https://unknown-tracker.example/forum/viewtopic.php?t=1')));
        $this->assertSame(422, $response['status']);
        $this->assertFalse($response['json']['ok']);
    }

    public function testBadUrlIsRejected()
    {
        $this->assertSame(422, Env::api('POST', 'torrents', array('json' => array('url' => 'not a url')))['status']);
        $this->assertSame(422, Env::api('POST', 'torrents', array('json' => array()))['status']);
    }

    public function testErrorsAreListed()
    {
        AppState::setCredentials('rutracker.org', Env::TRACKER_USER, 'wrong-password');
        Env::setTopic(503, 'Фильм', '20-Окт-26 08:30', 1);
        $id = $this->trackTopic(503);
        Env::runEngine();

        $errors = Env::api('GET', 'errors')['json'];
        $this->assertTrue($errors['ok']);
        $this->assertNotEmpty($errors['data']);
        $this->assertSame(200, Env::api('GET', 'errors/'.$id)['status']);
        $this->assertSame(404, Env::api('GET', 'errors/99999')['status']);
    }

    public function testUnknownResource()
    {
        $this->assertSame(404, Env::api('GET', 'nothing')['status']);
    }
}
