<?php
namespace TorrentMonitor\Client;

// The torrent client that TorrentMonitor passes downloaded torrent files to.
interface TorrentClient
{
    // Adds a torrent. $file - path to the torrent file.
    // $options: path - download directory, category - category, name - topic title,
    // url - address of the same file on the TorrentMonitor server (for clients that accept only a link).
    // Returns the torrent's id in the client (usually the info-hash).
    public function add(string $file, array $options): string;

    // removes the torrent; $deleteData - together with the downloaded files (if the client can do it)
    public function remove(string $id, bool $deleteData): void;

    // Details of the torrent in the client: array('comment' => comment from the torrent file (trackers put
    // the topic address there), 'path' => download directory, 'category' => category,
    // 'content' => path of the downloaded file or folder, '' if the client does not tell).
    // NULL if there is no such torrent or the client cannot do this.
    public function info(string $id): ?array;

    // The state of every torrent the client has, in one request: array(info-hash in lower case =>
    // array('state' => downloading | seeding | stopped | queued | checking | error,
    // 'progress' => percent done, 0..100, 'speed' => download speed, bytes per second,
    // 'size' => bytes, 'ratio' => uploaded to downloaded, 'message' => the client's error text or '')).
    // NULL if the client cannot tell (it has no list of torrents, or does not know them by hash).
    public function statuses(): ?array;

    // What the client says about itself: array('version' => '5.3.0', 'details' => 'libtorrent 2.1.2.0').
    // Both are '' when the client does not tell. Who packaged the client (an image of linuxserver.io,
    // for one) is not something a client reports.
    public function about(): array;
}
