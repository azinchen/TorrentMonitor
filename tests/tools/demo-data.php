<?php
// Fills the application of the test stack with made-up data for the screenshots in
// wiki/images. Run by: tests/run.sh screenshots
require '/app/src/bootstrap.php';

use TorrentMonitor\Data\Accounts;
use TorrentMonitor\Data\Settings;
use TorrentMonitor\Data\State;
use TorrentMonitor\Data\Topics;
use TorrentMonitor\Data\Users;
use TorrentMonitor\Data\Warnings;

Settings::setMany(array(
    'auth' => '0', 'serverAddress' => 'http://tm.local/', 'ApiKey' => 'demo', 'useTorrent' => '1',
));
\TorrentMonitor\Client\Clients::save(array('name' => 'qBittorrent', 'type' => 'qBittorrent', 'address' => 'http://qbittorrent:8080', 'login' => 'tm',
    'password' => 'tm-test-pass', 'path' => '/downloads/'));
foreach (array('rutracker.org', 'kinozal.me', 'lostfilm.tv', 'nnmclub.to') as $tracker)
    Accounts::set($tracker, array('login' => 'demo', 'password' => 'demo'));

$day = function ($ago, $time) { return date('Y-m-d', time() - $ago * 86400).' '.$time; };
Topics::add(array('tracker' => 'rutracker.org', 'topicId' => '1001', 'name' => 'Ubuntu 26.04 LTS Desktop amd64', 'updatedAt' => $day(1, '10:05:00'),
    'category' => 'linux', 'path' => '/downloads/iso/', 'autoRename' => TRUE));
Topics::add(array('tracker' => 'lostfilm.tv', 'name' => 'Pioneer One', 'quality' => '1080', 'episode' => 'S01E06', 'updatedAt' => $day(4, '21:00:00')));
Topics::add(array('tracker' => 'nnmclub.to', 'topicId' => '1002', 'name' => 'Debian 13 netinst', 'updatedAt' => $day(21, '12:00:00')));
Topics::add(array('tracker' => 'kinozal.me', 'topicId' => '1003', 'name' => 'Big Buck Bunny / 2008 / BDRip 1080p', 'updatedAt' => $day(50, '08:00:00'), 'paused' => TRUE));
$broken = Topics::add(array('tracker' => 'rutracker.org', 'topicId' => '1004', 'name' => 'Linux Mint 22 Cinnamon x64', 'updatedAt' => $day(110, '08:00:00'), 'error' => TRUE));
Warnings::add('rutracker.org', 'cant_find_date', $broken);

$user = Users::add('rutracker.org', 'releaser');
Users::addFound($user, 'rutracker.org', 'Операционные системы', '1005', 'Fedora Workstation 43 Live x86_64', date('Y-m-d'));
State::setLastRun(date('Y-m-d H:i:s', time() - 900));
echo "demo data written\n";
