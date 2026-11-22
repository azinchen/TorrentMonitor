<?php
namespace TorrentMonitor\Tracker;

// A new release found by the tracker for a topic.
final class Found
{
    // torrent file contents
    public string $torrent;
    // release date on the tracker (Y-m-d H:i:s): stored in the topic
    public string $date;
    // the same date in the tracker's own format: for the notification
    public string $dateText;
    // part of the saved file name: [tracker]_<fileId>.torrent
    public string $fileId;
    // update message
    public string $message;
    // new topic title (NULL - leave unchanged)
    public ?string $name = NULL;
    // episode in the S01E02 form (for series)
    public ?string $episode = NULL;

    public function __construct(string $torrent, string $date, string $dateText, string $fileId, string $message)
    {
        $this->torrent = $torrent;
        $this->date = $date;
        $this->dateText = $dateText;
        $this->fileId = $fileId;
        $this->message = $message;
    }
}
