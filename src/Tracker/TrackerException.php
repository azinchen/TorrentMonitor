<?php
namespace TorrentMonitor\Tracker;

// The tracker could not perform the action. The exception message is an error code (see TorrentMonitor\Messages),
// e.g. credential_wrong or cant_find_date.
class TrackerException extends \RuntimeException
{
}
