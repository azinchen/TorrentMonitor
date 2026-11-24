<?php
// The Sonarr of the test stack (tests/env/compose.sonarr.yml), driven through its API the way
// a person would click through its interface.
class Sonarr
{
    const URL = 'http://sonarr:8989';
    const KEY = '0123456789abcdef0123456789abcdef';   // throwaway test value, set in compose.sonarr.yml
    const ROOT = '/downloads/tv';

    public static function call($method, $path, $body = null, $timeout = 120)
    {
        $options = array('headers' => array('X-Api-Key: '.self::KEY), 'timeout' => $timeout);
        if ($body !== null)
        {
            $options['body'] = json_encode($body);
            $options['headers'][] = 'Content-Type: application/json';
        }
        $response = Http::request($method, self::URL.'/api/v3/'.ltrim($path, '/'), $options);
        if ($response['status'] >= 400)
            throw new RuntimeException('Sonarr '.$method.' '.$path.' -> HTTP '.$response['status'].': '.substr($response['body'], 0, 600));
        return $response['json'];
    }

    // a new indexer, download client or notification from Sonarr's own template for it
    private static function create($resource, $implementation, $name, array $fields, array $extra = array())
    {
        foreach (self::call('GET', $resource.'/schema') as $schema)
        {
            if ($schema['implementation'] != $implementation)
                continue;
            foreach ($schema['fields'] as &$field)
                if (array_key_exists($field['name'], $fields))
                    $field['value'] = $fields[$field['name']];
            unset($field);
            // forceSave: Sonarr's own test of the connection is not what is being tested here
            return self::call('POST', $resource.'?forceSave=true', array('name' => $name, 'enable' => true) + $extra + $schema);
        }
        throw new RuntimeException('Sonarr has no '.$resource.' implementation '.$implementation);
    }

    // removes everything earlier tests set up
    public static function reset()
    {
        foreach (self::call('GET', 'series') as $series)
            self::call('DELETE', 'series/'.$series['id'].'?deleteFiles=true');
        foreach (array('notification', 'indexer', 'downloadclient') as $resource)
            foreach (self::call('GET', $resource) as $item)
                self::call('DELETE', $resource.'/'.$item['id']);
    }

    // First setup of a fresh Sonarr: where series live, where to search, which torrent client
    // gets the torrents (kept stopped: nothing is downloaded), and the webhook of TorrentMonitor.
    // $client: qBittorrent, Transmission or Deluge
    public static function setup($client)
    {
        @mkdir(self::ROOT, 0777, true);
        @chmod(self::ROOT, 0777);
        if (count(self::call('GET', 'rootfolder')) == 0)
            self::call('POST', 'rootfolder', array('path' => self::ROOT));

        self::create('indexer', 'Torznab', 'TorrentMonitor test indexer',
            array('baseUrl' => Env::FAKE.'/torznab', 'apiPath' => '/api', 'categories' => array(5000, 5040), 'minimumSeeders' => 0),
            array('enableRss' => false, 'enableAutomaticSearch' => true, 'enableInteractiveSearch' => true, 'priority' => 25));

        if ($client == 'qBittorrent')
            self::create('downloadclient', 'QBittorrent', 'qBittorrent', array('host' => 'qbittorrent', 'port' => 8080,
                'username' => Env::CLIENT_USER, 'password' => Env::CLIENT_PASS, 'tvCategory' => 'tv-sonarr', 'initialState' => 2));
        elseif ($client == 'Transmission')
            self::create('downloadclient', 'Transmission', 'Transmission', array('host' => 'transmission', 'port' => 9091,
                'username' => Env::CLIENT_USER, 'password' => Env::CLIENT_PASS, 'tvCategory' => '', 'addPaused' => true));
        else
            self::create('downloadclient', 'Deluge', 'Deluge', array('host' => 'deluge', 'port' => 8112,
                'password' => Env::DELUGE_PASS, 'tvCategory' => '', 'addPaused' => true));

        self::create('notification', 'Webhook', 'TorrentMonitor',
            array('url' => Env::APP_FROM_NET.'api/sonarr?token='.Env::API_TOKEN, 'method' => 1),
            array('onGrab' => true, 'onSeriesDelete' => true, 'onSeriesAdd' => true));
    }

    // adds a series by its TVDB number, without searching for anything; waits for its episode list
    public static function addSeries($tvdbId)
    {
        $found = self::call('GET', 'series/lookup?term='.rawurlencode('tvdb:'.$tvdbId));
        if (empty($found[0]))
            throw new RuntimeException('Sonarr did not find the series tvdb:'.$tvdbId.' (it needs the internet for that)');
        $profiles = self::call('GET', 'qualityprofile');
        $series = self::call('POST', 'series', array(
            'qualityProfileId' => $profiles[0]['id'], 'rootFolderPath' => self::ROOT, 'monitored' => true, 'seasonFolder' => true,
            'addOptions' => array('searchForMissingEpisodes' => false, 'searchForCutoffUnmetEpisodes' => false, 'monitor' => 'all'),
        ) + $found[0]);
        Env::waitFor(function () use ($series) {
            return count(Sonarr::call('GET', 'episode?seriesId='.$series['id'])) > 0;
        }, 'Sonarr to load the episodes of '.$series['title'], 120);
        return $series;
    }

    // what the indexer offers for a season, as the "interactive search" of Sonarr shows it
    public static function releases($seriesId, $season)
    {
        return self::call('GET', 'release?seriesId='.$seriesId.'&seasonNumber='.$season, null, 180);
    }

    // "Grab" of one found release: Sonarr sends the torrent to the client and calls the webhook
    public static function grab(array $release)
    {
        return self::call('POST', 'release', array('guid' => $release['guid'], 'indexerId' => $release['indexerId']), 180);
    }

    public static function deleteSeries($seriesId, $deleteFiles = false)
    {
        self::call('DELETE', 'series/'.$seriesId.'?deleteFiles='.($deleteFiles ? 'true' : 'false'));
    }
}
