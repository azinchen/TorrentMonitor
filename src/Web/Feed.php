<?php
namespace TorrentMonitor\Web;

use TorrentMonitor\App;
use TorrentMonitor\Data\Settings;
use TorrentMonitor\Engine\Delivery;

//RSS feed of the most recently downloaded torrent files (/rss).
final class Feed
{
    const LIMIT = 20;

    public static function handle(): void
    {
        if (App::storageProblem() !== NULL || ! Settings::on('rss'))
        {
            http_response_code(404);
            return;
        }
        header('Content-Type: text/xml; charset=utf-8');

        $files = array();
        foreach ((array)glob(App::torrentsDir().'/*.torrent') as $path)
            $files[basename($path)] = filemtime($path);
        arsort($files);

        $xml = new \DOMDocument('1.0', 'utf-8');
        $xml->formatOutput = TRUE;
        $rss = $xml->appendChild($xml->createElement('rss'));
        $rss->setAttribute('version', '2.0');
        $channel = $rss->appendChild($xml->createElement('channel'));
        $add = function ($parent, $name, $text) use ($xml) {
            return $parent->appendChild($xml->createElement($name))->appendChild($xml->createTextNode($text));
        };
        $add($channel, 'title', 'TorrentMonitor RSS');
        $add($channel, 'link', rtrim((string)Settings::get('serverAddress'), '/').'/rss/');
        $add($channel, 'description', 'Torrent-файлы, скачанные TorrentMonitor');
        $add($channel, 'language', 'ru');

        foreach (array_slice($files, 0, self::LIMIT, TRUE) as $file => $time)
        {
            $item = $channel->appendChild($xml->createElement('item'));
            $add($item, 'title', preg_replace('/\.torrent$/', '', $file));
            $add($item, 'link', Delivery::fileUrl($file));
            $add($item, 'guid', $file.'-'.$time);
            $add($item, 'pubDate', date('r', $time));
        }
        echo $xml->saveXML();
    }

    //serves a saved torrent file (/torrents/<name>.torrent)
    public static function torrent(string $name): void
    {
        $name = basename($name);
        $path = App::torrentsDir().'/'.$name;
        if ( ! preg_match('/\.torrent$/', $name) || ! is_file($path))
        {
            http_response_code(404);
            return;
        }
        header('Content-Type: application/x-bittorrent');
        header('Content-Length: '.filesize($path));
        header('Content-Disposition: attachment; filename="'.str_replace(array('"', '\\'), '', $name).'"');
        readfile($path);
    }
}
