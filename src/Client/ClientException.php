<?php
namespace TorrentMonitor\Client;

// A torrent client error. The exception message is an error code (see TorrentMonitor\Messages):
//log_passwd, connect_fail, add_fail, torrent_file_fail, no_response, duplicate_torrent, unknown.
class ClientException extends \RuntimeException
{
}
