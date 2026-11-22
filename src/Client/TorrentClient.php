<?php
namespace TorrentMonitor\Client;

//Torrent-клиент, в который TorrentMonitor передаёт скачанные torrent-файлы.
interface TorrentClient
{
    //Добавляет раздачу. $file - путь к torrent-файлу.
    //$options: path - каталог загрузки, category - категория, name - название темы,
    //url - адрес этого же файла на сервере TorrentMonitor (для клиентов, которые принимают только ссылку).
    //Возвращает идентификатор раздачи в клиенте (обычно info-hash).
    public function add(string $file, array $options): string;

    //удаляет раздачу; $deleteData - вместе со скачанными файлами (если клиент это умеет)
    public function remove(string $id, bool $deleteData): void;

    //Сведения о раздаче в клиенте: array('comment' => комментарий из torrent-файла (трекеры пишут
    //туда адрес темы), 'path' => каталог загрузки, 'category' => категория).
    //NULL, если раздачи нет или клиент этого не умеет.
    public function info(string $id): ?array;
}
