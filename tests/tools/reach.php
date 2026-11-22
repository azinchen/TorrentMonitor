<?php
// Reports which trackers answer from where the test stack is running (directly or
// through the VPN), and whether they show a Cloudflare challenge.
// Run with: tests/run.sh reach
require '/src/tests/bootstrap.php';
chdir('/app');
include_once '/app/config.php';
include_once '/app/class/System.class.php';
include_once '/app/class/Database.class.php';

function fetch($url, $timeout = 20)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => (string)Database::getSetting('userAgent'),
    ));
    $start = microtime(true);
    $body = curl_exec($ch);
    return array(
        'code'  => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'error' => curl_error($ch),
        'body'  => is_string($body) ? $body : '',
        'time'  => microtime(true) - $start,
        'final' => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
    );
}

// where the requests come out (country only; the address itself is not printed)
$trace = fetch('https://www.cloudflare.com/cdn-cgi/trace', 10);
preg_match('/^loc=(\w+)/m', $trace['body'], $loc);
echo 'Exit country: '.(isset($loc[1]) ? $loc[1] : 'unknown (no internet?)')."\n\n";

$solver = (string)getenv('TM_SOLVER');
printf("%-20s %-34s %-22s %s\n", 'TRACKER', 'ADDRESS', 'RESULT', 'TIME');
foreach (Database::getAllCredentials() as $row)
{
    $tracker = $row['tracker'];
    $url = Sys::getTrackerCheckUrl($tracker);
    $r = fetch($url);

    if ($r['error'] !== '')
        $result = 'FAILED: '.preg_replace('/ after \d+ ms.*/', '', $r['error']);
    elseif (Sys::isCloudflarePage($r['body']))
    {
        $result = 'Cloudflare challenge';
        if ($solver !== '')
        {
            Database::updateSettings('flaresolverrUrl', $solver);
            $solved = Sys::getViaFlareSolverr($url);
            $result .= ($solved !== null && ! Sys::isCloudflarePage($solved['body'])) ? ', solved' : ', NOT solved';
        }
    }
    else
        $result = 'HTTP '.$r['code'];

    printf("%-20s %-34s %-22s %.1fs\n", $tracker, $url, $result, $r['time']);
}
