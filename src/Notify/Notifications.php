<?php
namespace TorrentMonitor\Notify;

use TorrentMonitor\Data\Settings;
use TorrentMonitor\Messages;

// Sending notifications to the user according to the settings.
final class Notifications
{
    // Topic update
    public static function update(string $tracker, string $message, string $date, string $topicUrl = ''): void
    {
        self::deliver('sendUpdate', 'sendUpdateService', 'Обновление.', self::body($date, $tracker, $message, $topicUrl));
    }

    // Runtime error
    public static function warning(string $source, string $code, string $topicUrl = ''): void
    {
        self::deliver('sendWarning', 'sendWarningService', 'Предупреждение.', self::body(date('Y-m-d H:i:s'), $source, Messages::text($code), $topicUrl));
    }

    // News (a new version is out)
    public static function news(string $text): void
    {
        self::deliver('sendUpdate', 'sendUpdateService', 'Новость.', htmlspecialchars($text, ENT_QUOTES));
    }

    // Arbitrary update message (a topic was added through the API)
    public static function info(string $source, string $message): void
    {
        self::deliver('sendUpdate', 'sendUpdateService', 'Обновление.', self::body(date('Y-m-d H:i:s'), $source, $message, ''));
    }

    // Test message through the given service; TRUE if the service accepted it
    public static function test(string $service, string $address): bool
    {
        $notifier = Services::get($service);
        if ($notifier === NULL || $address === '')
            return FALSE;
        Services::$delivered = FALSE;
        try
        {
            $notifier->send($address, 'Проверка.', self::body(date('Y-m-d H:i:s'), 'TorrentMonitor', 'Проверка уведомлений: всё работает.', ''));
        }
        catch (\Throwable $e)
        {
            return FALSE;
        }
        return Services::$delivered;
    }

    private static function body(string $date, string $source, string $message, string $url): string
    {
        $body = '<i>Дата:</i>  '.htmlspecialchars($date, ENT_QUOTES)."\n"
            .'<i>Трекер:</i>  '.htmlspecialchars($source, ENT_QUOTES)."\n"
            .'<i>Сообщение:</i>  '.htmlspecialchars($message, ENT_QUOTES)."\n";
        if ($url !== '')
            $body .= 'Ссылка на тему: '.htmlspecialchars($url, ENT_QUOTES)."\n";
        return $body;
    }

    private static function deliver(string $switch, string $serviceSetting, string $title, string $html): void
    {
        if ( ! Settings::on('send') || ! Settings::on($switch))
            return;
        $target = Settings::notificationTarget($serviceSetting);
        if ($target['service'] === NULL || $target['address'] === '')
            return;
        $notifier = Services::get($target['service']);
        if ($notifier === NULL)
            return;
        try
        {
            $notifier->send($target['address'], $title, $html);
        }
        catch (\Throwable $e)
        {
            // A notification must not stop the run
        }
    }
}
