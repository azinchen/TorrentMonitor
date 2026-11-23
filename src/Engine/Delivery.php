<?php
namespace TorrentMonitor\Engine;

use TorrentMonitor\App;
use TorrentMonitor\Client\Bencode;
use TorrentMonitor\Client\ClientException;
use TorrentMonitor\Client\Clients;
use TorrentMonitor\Data\Pending;
use TorrentMonitor\Data\Settings;
use TorrentMonitor\Data\Topics;
use TorrentMonitor\Data\Warnings;
use TorrentMonitor\Notify\Notifications;
use TorrentMonitor\Tracker\Found;
use TorrentMonitor\Tracker\Registry;

// What happens to a found torrent: the torrent file is saved, handed to the torrent client,
// the user script is run, a notification is sent.
final class Delivery
{
    // Name of the torrent file being saved
    public static function fileName(string $tracker, string $fileId): string
    {
        return '['.$tracker.']_'.str_replace(array("'", ':', '/', '\\', "\0"), '', $fileId).'.torrent';
    }

    // Saves the torrent file; the file name or NULL on failure
    public static function save(string $tracker, string $fileId, string $torrent): ?string
    {
        $name = self::fileName($tracker, $fileId);
        $path = App::torrentsDir().'/'.$name;
        if (is_file($path))
            @unlink($path);
        return @file_put_contents($path, $torrent) ? $name : NULL;
    }

    // Address of the saved torrent file on the TorrentMonitor server
    public static function fileUrl(string $file): string
    {
        return rtrim((string)Settings::get('serverAddress'), '/').'/torrents/'.rawurlencode($file);
    }

    // Processes a new torrent of a topic. Returns the topic fields that need to be changed,
    // or NULL if the torrent file could not be saved.
    public static function deliver(array $topic, Found $found, callable $out): ?array
    {
        $tracker = $topic['tracker'];
        $file = self::save($tracker, $found->fileId, $found->torrent);
        if ($file === NULL)
            return NULL;

        $name = $found->name !== NULL ? $found->name : $topic['name'];
        $fields = array('updatedAt' => $found->date, 'error' => FALSE, 'closed' => FALSE);
        if ($found->name !== NULL)
            $fields['name'] = $found->name;
        if ($found->episode !== NULL)
            $fields['episode'] = $found->episode;

        $message = $found->message;
        if (Clients::currentName() === NULL)
            $message .= ' И сохранён.';
        else
        {
            $hash = self::toClient($topic, $name, $file, $found->dateText);
            if ($hash !== NULL)
            {
                $fields['hash'] = $hash;
                $message .= ' И добавлен в torrent-клиент.';
                self::script($topic, $name, $hash, $message, $found->dateText, $out);
            }
            else
                $message .= ' Но не добавлен в torrent-клиент и сохранён.';
        }

        $url = $topic['topicId'] !== '' && Registry::has($tracker) ? Registry::get($tracker)->topicUrl($topic['topicId']) : '';
        Notifications::update($tracker, $message, $found->dateText, $url);
        $out($message);
        return $fields;
    }

    // Hands the saved file to the torrent client; the topic's previous torrent is removed in the process.
    // Returns the torrent's identifier in the client or NULL (then the file is put in the retry queue).
    public static function toClient(array $topic, string $name, string $file, string $dateText): ?string
    {
        $clientName = Clients::currentName();
        // The same torrent is already in the client (for example, added by Sonarr): no need to touch it
        $known = Bencode::infoHash((string)@file_get_contents(App::torrentsDir().'/'.$file));
        if ($known !== NULL && $topic['hash'] !== '' && strcasecmp($known, $topic['hash']) == 0)
            return $topic['hash'];
        try
        {
            $client = Clients::current();
            if ($topic['hash'] !== '')
            {
                // For series every episode is a separate torrent: the previous one is removed only if configured so
                $series = Registry::kind($topic['tracker']) == 'series';
                if ( ! $series)
                    $client->remove($topic['hash'], FALSE);
                elseif (Settings::on('deleteDistribution'))
                    $client->remove($topic['hash'], Settings::on('deleteOldFiles'));
            }
            $hash = $client->add(App::torrentsDir().'/'.$file, array(
                'path'     => $topic['path'] !== '' ? $topic['path'] : (string)Settings::get('pathToDownload'),
                'category' => $topic['category'] !== '' ? $topic['category'] : (string)Settings::get('qbitCategory'),
                'name'     => $name,
                'url'      => self::fileUrl($file),
            ));
            Pending::remove($topic['id']);
            Warnings::clear($clientName);
            return $hash;
        }
        catch (ClientException $e)
        {
            if ( ! empty($topic['id']))
                Pending::add($topic['id'], $name, $file, $topic['tracker'], $dateText);
            Problems::report($clientName, $e->getMessage());
            return NULL;
        }
    }

    // Removes the topic's torrent from the torrent client (when the topic is deleted).
    // $deleteData: TRUE/FALSE - whether to delete the downloaded files, NULL - as set in the deleteFilesOnRemove setting.
    public static function removeFromClient(string $hash, ?bool $deleteData = NULL): bool
    {
        $clientName = Clients::currentName();
        if ($hash === '' || $clientName === NULL)
            return FALSE;
        try
        {
            Clients::current()->remove($hash, $deleteData === NULL ? Settings::on('deleteFilesOnRemove') : $deleteData);
            Warnings::clear($clientName);
            return TRUE;
        }
        catch (ClientException $e)
        {
            Problems::report($clientName, $e->getMessage());
            return FALSE;
        }
    }

    // The topic's user script
    public static function script(array $topic, string $name, string $hash, string $message, string $dateText, callable $out): void
    {
        if (empty($topic['script']) || ! function_exists('shell_exec'))
            return;
        $command = implode(' ', array_map('escapeshellarg', array($topic['script'], $topic['tracker'], $name, $hash, $message, $dateText)));
        $result = @shell_exec($command);
        if (is_string($result) && trim($result) !== '')
            $out(trim($result));
    }

    // Retries handing to the torrent client the files that could not be handed over earlier
    public static function retryPending(callable $out): void
    {
        if (Clients::currentName() === NULL)
            return;
        foreach (Pending::all() as $topicId => $item)
        {
            $topic = Topics::get($topicId);
            if ($topic === NULL || ! is_file(App::torrentsDir().'/'.$item['file']))
            {
                Pending::remove($topicId);
                continue;
            }
            $hash = self::toClient($topic, $item['name'], $item['file'], $item['date']);
            if ($hash === NULL)
                continue;
            Topics::update($topicId, array('hash' => $hash));
            $message = 'Torrent-клиент доступен и раздача '.$item['name'].' добавлена.';
            Notifications::update($item['tracker'], $message, $item['date']);
            self::script($topic, $item['name'], $hash, $message, $item['date'], $out);
            $out($message);
        }
    }

    // Removes old torrent files: the last 20 and those waiting to be handed to the client are kept
    public static function cleanFiles(int $keep = 20): void
    {
        $waiting = array_column(Pending::all(), 'file');
        $files = array();
        foreach ((array)glob(App::torrentsDir().'/*.torrent') as $path)
            if ( ! in_array(basename($path), $waiting, TRUE))
                $files[$path] = filemtime($path);
        arsort($files);
        foreach (array_slice(array_keys($files), $keep) as $path)
            @unlink($path);
    }
}
