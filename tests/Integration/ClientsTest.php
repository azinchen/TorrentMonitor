<?php
// Several torrent clients: each topic goes to its own client, one client is the default.
final class ClientsTest extends IntegrationTestCase
{
    //qBittorrent as the default client and Transmission as the second one; returns their ids
    private function twoClients()
    {
        @mkdir(Env::DOWNLOADS.'/transmission', 0777, true);
        AppState::useQbittorrent();
        $second = Env::ui('client.save', array('name' => 'NAS', 'type' => 'Transmission', 'address' => Env::TRANSMISSION,
            'login' => Env::CLIENT_USER, 'password' => Env::CLIENT_PASS, 'path' => Env::DOWNLOADS.'/transmission/'));
        $this->assertTrue($second['json']['ok'], $second['body']);
        return array(1, $second['json']['saved']);
    }

    public function testEveryTopicGoesToItsOwnClient()
    {
        list($qbt, $nas) = $this->twoClients();
        $this->loginToTracker();
        Env::setTopic(131, 'Тема основного клиента', '21-Ноя-26 10:00', 1);
        Env::setTopic(132, 'Тема второго клиента', '21-Ноя-26 10:00', 1);
        $a = $this->trackTopic(131);
        $b = $this->trackTopic(132);
        $saved = Env::ui('topic.save', array('id' => $b, 'name' => 'Тема второго клиента', 'client' => $nas));
        $this->assertTrue($saved['json']['ok'], $saved['body']);
        $this->assertFalse(Env::ui('topic.save', array('id' => $b, 'name' => 'x', 'client' => 999))['json']['ok'], 'a client that does not exist');

        $output = Env::runEngine();
        $this->assertTrue(Qbt::has(TorrentBuilder::infoHash(131, 1)), 'a topic that names no client goes to the default one');
        $this->assertFalse(Qbt::has(TorrentBuilder::infoHash(132, 1)));
        $this->assertTrue(Tr::has(TorrentBuilder::infoHash(132, 1)), 'a topic goes to the client it names');
        $this->assertFalse(Tr::has(TorrentBuilder::infoHash(131, 1)));
        $this->assertSame(Env::DOWNLOADS.'/transmission', rtrim(Tr::torrents()[TorrentBuilder::infoHash(132, 1)]['downloadDir'], '/'), 'the folder of its client');
        $this->assertStringContainsString('Передано в torrent-клиент: 2.', $output);
        $this->assertNoWarnings();

        // the list of topics names the client of each; every client tells about its own torrents
        $topics = array_column(Env::ui('overview')['json']['topics'], null, 'id');
        $this->assertSame(array(null, $qbt, 'qBittorrent'), array($topics[$a]['client'], $topics[$a]['clientId'], $topics[$a]['clientName']));
        $this->assertSame(array($nas, $nas, 'NAS'), array($topics[$b]['client'], $topics[$b]['clientId'], $topics[$b]['clientName']));
        $first = Env::ui('client.status', array('id' => $qbt))['json'];
        $second = Env::ui('client.status', array('id' => $nas))['json'];
        $this->assertSame(array($a), array_keys($first['torrents']));
        $this->assertSame(array($b), array_keys($second['torrents']));
        $this->assertSame('NAS', $second['client']);

        // an update of a topic goes to the same client
        Env::setTopic(132, 'Тема второго клиента', '22-Ноя-26 10:00', 2);
        Env::runEngine();
        $this->assertTrue(Tr::has(TorrentBuilder::infoHash(132, 2)));
        $this->assertFalse(Tr::has(TorrentBuilder::infoHash(132, 1)), 'the old torrent is removed from that client');
        $this->assertFalse(Qbt::has(TorrentBuilder::infoHash(132, 2)));

        // the log of a detailed run opens with both clients; the health check asks both
        AppState::setSettings(array('debug' => '1'));
        $log = Env::runEngine();
        $this->assertStringContainsString('Torrent-клиент «qBittorrent» (основной): ', $log);
        $this->assertStringContainsString('Torrent-клиент «NAS»: ', $log);
        $health = json_encode(Env::ui('health')['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('«qBittorrent» (qBittorrent, '.Env::qbt().'), основной отвечает', $health);
        $this->assertStringContainsString('«NAS» (Transmission, '.Env::TRANSMISSION.') отвечает', $health);
    }

    public function testTopicIsMovedToAnotherClient()
    {
        list($qbt, $nas) = $this->twoClients();
        $this->loginToTracker();
        Env::setTopic(133, 'Переезд', '21-Ноя-26 10:00', 1);
        $id = $this->trackTopic(133);
        Env::runEngine();
        $hash = TorrentBuilder::infoHash(133, 1);
        $this->assertTrue(Qbt::has($hash));

        // the torrent stays in the former client unless the form says to remove it
        $moved = Env::ui('topic.save', array('id' => $id, 'name' => 'Переезд', 'client' => $nas));
        $this->assertTrue($moved['json']['ok'], $moved['body']);
        $this->assertStringContainsString('Раздача передана в «NAS»', $moved['json']['message']);
        $this->assertTrue(Tr::has($hash), 'the torrent is passed to the new client from the saved file');
        $this->assertTrue(Qbt::has($hash), 'and is left in the former one');
        $this->assertSame($hash, strtolower(AppState::topic($id)['hash']));
        $this->assertSame($nas, AppState::topic($id)['client']);
        $this->assertContains('Тема «Переезд» перенесена (интерфейс): «qBittorrent» → «NAS». Раздача передана в «NAS».', AppState::journal('topic'));

        // back, removing it from the client it leaves
        $back = Env::ui('topic.save', array('id' => $id, 'name' => 'Переезд', 'client' => '', 'moveRemove' => true, 'moveDeleteData' => true));
        $this->assertTrue($back['json']['ok'], $back['body']);
        $this->assertStringContainsString('Раздача удалена из «NAS»', $back['json']['message']);
        $this->assertFalse(Tr::has($hash));
        $this->assertTrue(Qbt::has($hash));
        $this->assertNull(AppState::topic($id)['client'], 'no client of its own: the default one');
        $this->assertNoWarnings();
    }

    public function testApiAndSonarrUseTheDefaultClientOrTheNamedOne()
    {
        list($qbt, $nas) = $this->twoClients();
        $this->loginToTracker();
        Env::setTopic(134, 'Через API', '21-Ноя-26 10:00', 1);
        Env::setTopic(135, 'Через API по имени', '21-Ноя-26 10:00', 1);

        // REST API: without a client - the default one; by name whatever the case, or by number
        $plain = Env::api('POST', 'torrents', array('json' => array('url' => self::TOPIC.'134')));
        $named = Env::api('POST', 'torrents', array('json' => array('url' => self::TOPIC.'135', 'client' => 'nas')));
        $this->assertSame(201, $plain['status'], $plain['body']);
        $this->assertSame(201, $named['status'], $named['body']);
        $unknown = Env::api('POST', 'torrents', array('json' => array('url' => self::TOPIC.'136', 'client' => 'Nowhere')));
        $this->assertSame(422, $unknown['status']);
        $this->assertStringContainsString('Нет torrent-клиента «Nowhere». Есть: qBittorrent, NAS', $unknown['json']['msg'] ?? $unknown['json']['message'] ?? $unknown['body']);
        $listed = array_column(Env::api('GET', 'torrents')['json']['data'], null, 'topicId');
        $this->assertSame(array('qBittorrent', $qbt), array($listed['134']['client'], $listed['134']['clientId']));
        $this->assertSame(array('NAS', $nas), array($listed['135']['client'], $listed['135']['clientId']));
        Env::runEngine();
        $this->assertTrue(Qbt::has(TorrentBuilder::infoHash(134, 1)));
        $this->assertTrue(Tr::has(TorrentBuilder::infoHash(135, 1)));

        // Sonarr: the release is looked for in the default client...
        Qbt::addFile(TorrentBuilder::torrent(137, 1, self::TOPIC.'137'), Env::DOWNLOADS.'/qbt', 'tv-sonarr');
        $hash = TorrentBuilder::infoHash(137, 1);
        Env::waitFor(function () use ($hash) { return Qbt::has($hash); }, 'torrent in qBittorrent');
        $grab = function ($hash, $query) {
            return Http::postJson(Env::APP.'/api/sonarr?token='.Env::API_TOKEN.$query, array('eventType' => 'Grab', 'downloadId' => strtoupper($hash), 'series' => array('id' => 5, 'title' => 'Сериал')));
        };
        $this->assertTrue($grab($hash, '')['json']['ok']);
        Env::waitFor(function () { return count(array_filter(AppState::topics(), function ($t) { return $t['torrent_id'] == '137'; })) == 1; }, 'topic added by the webhook');
        $topic = array_values(array_filter(AppState::topics(), function ($t) { return $t['torrent_id'] == '137'; }))[0];
        $this->assertSame($qbt, $topic['client'], 'the topic belongs to the client the release was found in');

        // ...or in the one the address of the webhook names; an unknown name is refused
        $refused = $grab($hash, '&client=Nowhere');
        $this->assertSame(422, $refused['status']);
        $this->assertStringContainsString('Нет torrent-клиента «Nowhere»', $refused['body']);
        // the torrent of topic 135 is in the second client: a grab that names it finds it there
        $known = TorrentBuilder::infoHash(135, 1);
        $this->assertTrue($grab($known, '&client=NAS')['json']['ok']);
        Env::waitFor(function () { $t = array_values(array_filter(AppState::topics(), function ($t) { return $t['torrent_id'] == '135'; })); return $t && $t[0]['sonarrSeries'] === 5; }, 'the known topic linked to the series');
    }

    public function testOneClientThatIsDownDoesNotHoldBackTheOthers()
    {
        list($qbt, $nas) = $this->twoClients();
        $this->loginToTracker();
        Env::setTopic(138, 'Основной лежит', '21-Ноя-26 10:00', 1);
        Env::setTopic(139, 'Второй работает', '21-Ноя-26 10:00', 1);
        $a = $this->trackTopic(138);
        $b = $this->trackTopic(139);
        Env::ui('topic.save', array('id' => $b, 'name' => 'Второй работает', 'client' => $nas));
        AppState::setClient(array('address' => 'http://127.0.0.1:9'), $qbt);

        $output = Env::runEngine();
        $this->assertTrue(Tr::has(TorrentBuilder::infoHash(139, 1)), 'the client that works gets its torrent');
        $this->assertSame('', AppState::topic($a)['hash']);
        $this->assertStringContainsString('Передано в torrent-клиент: 1.', $output);
        // the error names the client; the page says which client does not answer
        $this->assertContains('qBittorrent', array_column(Env::ui('overview')['json']['warnings'], 'source'));
        $down = Env::ui('client.status', array('id' => $qbt))['json'];
        $this->assertSame('«qBittorrent» не отвечает: состояние раздач неизвестно.', $down['error']);
        $this->assertTrue(Env::ui('client.status', array('id' => $nas))['json']['answered']);

        // the client is back: the waiting file goes to it, and only to it
        AppState::setClient(array('address' => Env::qbt()), $qbt);
        Env::runEngine();
        $this->assertTrue(Qbt::has(TorrentBuilder::infoHash(138, 1)));
        $this->assertFalse(Tr::has(TorrentBuilder::infoHash(138, 1)));
        $this->assertNotSame('', AppState::topic($a)['hash']);
    }

    public function testInstallationWithoutClientsOnlySavesFiles()
    {
        // passing is switched on, and no client is set: what an installation of an earlier 4.0 version comes to
        AppState::setSettings(array('useTorrent' => '1'));
        $this->loginToTracker();
        Env::setTopic(140, 'Без клиента', '21-Ноя-26 10:00', 1);
        $id = $this->trackTopic(140);
        $output = Env::runEngine();
        $this->assertStringContainsString('И сохранён.', $output);
        $this->assertSame('', AppState::topic($id)['hash']);
        $this->assertNoWarnings();
        $this->assertStringContainsString('Ни одного torrent-клиента не задано', json_encode(Env::ui('health')['json'], JSON_UNESCAPED_UNICODE));
        $this->assertNull(Env::api('GET', 'torrents')['json']['data'][0]['client']);
    }
}
