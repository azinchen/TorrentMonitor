<?php
namespace TorrentMonitor\Client;

//Ошибка torrent-клиента. Сообщение исключения - код ошибки (см. TorrentMonitor\Messages):
//log_passwd, connect_fail, add_fail, torrent_file_fail, no_response, duplicate_torrent, unknown.
class ClientException extends \RuntimeException
{
}
