<?php
namespace TorrentMonitor\Tracker;

//Новая раздача, найденная трекером для темы.
final class Found
{
    //содержимое torrent-файла
    public string $torrent;
    //дата раздачи на трекере (Y-m-d H:i:s): запоминается в теме
    public string $date;
    //та же дата в виде, принятом на трекере: для уведомления
    public string $dateText;
    //часть имени сохраняемого файла: [трекер]_<fileId>.torrent
    public string $fileId;
    //сообщение об обновлении
    public string $message;
    //новое название темы (NULL - не менять)
    public ?string $name = NULL;
    //серия вида S01E02 (для сериалов)
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
