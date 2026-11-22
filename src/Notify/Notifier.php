<?php
namespace TorrentMonitor\Notify;

// Notification service.
interface Notifier
{
    // $address - the service's address or keys from the settings; $html - text with <b>, <i> tags and line breaks
    public function send(string $address, string $title, string $html): void;
}
