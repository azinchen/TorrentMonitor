<?php
// The test stack as seen from inside the "app" container.
class Env
{
    const APP          = 'http://127.0.0.1';
    const APP_FROM_NET = 'http://app/';          // how torrent clients reach the application
    const FAKE         = 'http://fake:8080';
    const SOLVER       = 'http://fake:8080/solver';
    const QBT          = 'http://qbittorrent:8080';
    const TRANSMISSION = 'http://transmission:9091';
    const CLIENT_USER  = 'tm';
    const CLIENT_PASS  = 'tm-test-pass';
    const TRACKER_USER = 'tm-user';
    const TRACKER_PASS = 'tm-pass';
    const API_TOKEN    = 'test-api-token';
    const DOWNLOADS    = '/downloads';

    // live mode: real trackers, no fake server
    public static function isLive()
    {
        return getenv('TM_LIVE') === '1';
    }

    // values from tests/env/secrets/<name>.env (mounted at /secrets), or an empty array
    public static function secrets($name)
    {
        $values = array();
        foreach ((array)@file('/secrets/'.$name.'.env', FILE_IGNORE_NEW_LINES) as $line)
        {
            if (preg_match('/^\s*([A-Z0-9_]+)=(.*)$/', $line, $m))
                $values[$m[1]] = trim($m[2]);
        }
        return $values;
    }

    // ---- fake internet ----

    public static function fakeReset()
    {
        Http::post(self::FAKE.'/__control/reset');
    }

    public static function fake($patch)
    {
        return Http::postJson(self::FAKE.'/__control/set', $patch)['json'];
    }

    // requests the fake received, optionally only those whose path contains $pathPart
    public static function fakeRequests($pathPart = null)
    {
        $rows = (array)Http::get(self::FAKE.'/__control/requests')['json'];
        if ($pathPart === null)
            return $rows;
        return array_values(array_filter($rows, function ($row) use ($pathPart) {
            return strpos($row['path'], $pathPart) !== false;
        }));
    }

    public static function setTopic($id, $title, $date, $revision)
    {
        self::fake(array('rutracker' => array('topics' => array((string)$id => array(
            'title' => $title, 'date' => $date, 'revision' => $revision,
        )))));
    }

    // ---- application under test ----

    // fresh data directory, fresh copy of the code, empty torrents/
    public static function resetApp()
    {
        self::sh('/src/tests/env/app/sync.sh');
        self::sh('/src/tests/env/app/reset-data.sh');
        self::uiClose();
        foreach (glob('/app/torrents/*.torrent') as $file)
            unlink($file);
        if ( ! self::isLive())
            self::fakeReset();
        AppState::baseline();
    }

    // runs the engine the way cron does; returns its output
    public static function runEngine()
    {
        return self::sh('cd /app && php engine.php 2>&1');
    }

    // a run that is expected to refuse to work: returns its output, fails if it reports success
    public static function runEngineExpectingFailure()
    {
        exec('cd /app && php engine.php 2>&1', $output, $code);
        if ($code === 0)
            throw new RuntimeException("engine was expected to fail but succeeded:\n".implode("\n", $output));
        return implode("\n", $output);
    }

    public static function sh($command)
    {
        exec($command, $output, $code);
        if ($code !== 0)
            throw new RuntimeException("command failed ($code): $command\n".implode("\n", $output));
        return implode("\n", $output);
    }

    // polls until $check returns a truthy value; fails the test otherwise
    public static function waitFor($check, $what, $seconds = 30)
    {
        $deadline = microtime(true) + $seconds;
        do
        {
            $value = $check();
            if ($value)
                return $value;
            usleep(300000);
        }
        while (microtime(true) < $deadline);
        throw new RuntimeException('timed out waiting for: '.$what);
    }

    // ---- web interface session ----

    private static $jar;
    private static $csrf;

    // opens the web interface in a new browser session; returns the answer to the first request of the page
    public static function uiOpen()
    {
        self::$jar = tempnam(sys_get_temp_dir(), 'tmjar');
        self::$csrf = '';
        $boot = Http::postJson(self::APP.'/ui/boot', array(), array('jar' => self::$jar));
        self::$csrf = isset($boot['json']['csrf']) ? $boot['json']['csrf'] : '';
        return $boot;
    }

    // one action of the web interface: POST /ui/<action> with a JSON body, as the page does
    public static function ui($action, $body = array())
    {
        if (self::$jar === null)
            self::uiOpen();
        return Http::postJson(self::APP.'/ui/'.$action, (object)$body, array('jar' => self::$jar, 'headers' => array('X-CSRF-Token: '.self::$csrf)));
    }

    // forgets the browser session (the next action opens a new one)
    public static function uiClose()
    {
        self::$jar = null;
        self::$csrf = '';
    }

    // ---- REST API ----

    public static function api($method, $path, $options = array())
    {
        $options['headers'] = array('Authorization: Bearer '.self::API_TOKEN);
        if (isset($options['json']))
        {
            $options['body'] = json_encode($options['json']);
            $options['headers'][] = 'Content-Type: application/json';
        }
        return Http::request($method, self::APP.'/api/'.ltrim($path, '/'), $options);
    }
}
