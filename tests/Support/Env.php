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

    // fresh database, fresh copy of the code, empty torrents/ and downloads
    public static function resetApp()
    {
        self::sh('/src/tests/env/app/sync.sh');
        self::sh('php /src/tests/env/app/reset-db.php');
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

    public static function uiOpen()
    {
        self::$jar = tempnam(sys_get_temp_dir(), 'tmjar');
        $page = Http::get(self::APP.'/', array('jar' => self::$jar));
        preg_match('/name="csrf-token" content="([^"]+)"/', $page['body'], $m);
        self::$csrf = isset($m[1]) ? $m[1] : '';
        return $page;
    }

    public static function uiGet($path)
    {
        if (self::$jar === null)
            self::uiOpen();
        return Http::get(self::APP.$path, array('jar' => self::$jar));
    }

    // POST to action.php as the web interface does
    public static function uiAction($fields)
    {
        if (self::$jar === null)
            self::uiOpen();
        $fields['csrf_token'] = self::$csrf;
        return Http::post(self::APP.'/action.php', $fields, array('jar' => self::$jar));
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
