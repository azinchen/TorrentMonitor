<?php
namespace TorrentMonitor\Storage;

// Storage error: a data file is corrupted, inaccessible or was created by a newer version.
class StorageException extends \RuntimeException
{
}
