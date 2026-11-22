<?php
// Reports which trackers answer from where the test stack is running (directly or
// through the VPN), whether they show a Cloudflare challenge and whether the solver passes it.
// Run with: tests/run.sh reach
require '/src/tests/bootstrap.php';
require '/app/src/bootstrap.php';

use TorrentMonitor\App;
use TorrentMonitor\Data\Settings;
use TorrentMonitor\Http\Client;
use TorrentMonitor\Http\Request;
use TorrentMonitor\Tracker\Registry;

$solver = (string)getenv('TM_SOLVER');
Settings::set('flaresolverrUrl', '');
$exit = App::http()->send(Request::get('https://ifconfig.co/country-iso')->timeout(15));
echo 'exit country: '.(trim($exit->body) ?: 'unknown')."\n";

$requests = array();
foreach (Registry::names() as $name)
    $requests[$name] = Request::get(Registry::get($name)->base().'/')->follow()->tracker($name)->timeout(20);
$direct = App::http()->sendAll($requests);

foreach ($direct as $name => $response)
{
    if ($response->status == 0)
        $result = 'no answer ('.$response->error.')';
    elseif ($response->challenged || Client::isChallengePage($response->status, $response->headers, $response->body))
    {
        $result = 'Cloudflare challenge';
        if ($solver !== '')
        {
            Settings::set('flaresolverrUrl', $solver);
            App::resetHttp();
            $solved = App::http()->send(Request::get(Registry::get($name)->base().'/')->follow()->tracker($name)->timeout(20));
            $result .= $solved->challenged ? ', NOT solved' : ', solved'.($solved->solved ? ' (page from the solver)' : ' (direct request with clearance)');
        }
    }
    else
        $result = 'HTTP '.$response->status;
    printf("%-18s %-34s %s\n", $name, Registry::get($name)->base(), $result);
}
