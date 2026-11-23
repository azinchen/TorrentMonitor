<?php
namespace TorrentMonitor\Client;

use TorrentMonitor\App;
use TorrentMonitor\Data\Settings;

// Selects the torrent client according to the settings.
final class Clients
{
    const CLASSES = array(
        'qBittorrent'  => QBittorrent::class,
        'Transmission' => Transmission::class,
        'Deluge'       => Deluge::class,
        'SynologyDS'   => SynologyDS::class,
        'TorrServer'   => TorrServer::class,
    );

    // name of the selected client, or NULL if passing torrents to a client is turned off
    public static function currentName(): ?string
    {
        $name = Settings::get('torrentClient');
        return Settings::on('useTorrent') && isset(self::CLASSES[$name]) ? $name : NULL;
    }

    public static function current(): ?TorrentClient
    {
        $name = self::currentName();
        if ($name === NULL)
            return NULL;
        $class = self::CLASSES[$name];
        return new $class(App::http(), (string)Settings::get('torrentAddress'), (string)Settings::get('torrentLogin'), (string)Settings::get('torrentPassword'));
    }
}
