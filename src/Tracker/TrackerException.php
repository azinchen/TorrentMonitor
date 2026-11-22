<?php
namespace TorrentMonitor\Tracker;

//Трекер не смог выполнить действие. Сообщение исключения - код ошибки (см. TorrentMonitor\Messages),
//например credential_wrong или cant_find_date.
class TrackerException extends \RuntimeException
{
}
