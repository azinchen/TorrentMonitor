<?php
namespace TorrentMonitor\Notify;

//Сервис уведомлений.
interface Notifier
{
    //$address - адрес или ключи сервиса из настроек; $html - текст с тегами <b>, <i> и переводами строк
    public function send(string $address, string $title, string $html): void;
}
