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

    // A new version is out, or was installed
    public static function version(string $text): void
    {
        self::deliver('sendUpdate', 'sendUpdateService', 'Новая версия.', htmlspecialchars($text, ENT_QUOTES));
    }

    // Arbitrary update message (a topic was added through the API)
    public static function info(string $source, string $message): void
    {
        self::deliver('sendUpdate', 'sendUpdateService', 'Обновление.', self::body(date('Y-m-d H:i:s'), $source, $message, ''));
    }

    // Test message through the given service; TRUE if the service accepted it
    public static function test(string $service, string $address): bool
    {
        return self::check($service, $address)[0];
    }

    // Sends a test message and tells how it went: array(accepted, text). There is no other way to
    // find out whether an address and its keys work: the services have no call that only checks them.
    public static function check(string $service, string $address): array
    {
        $notifier = Services::get($service);
        if ($notifier === NULL)
            return array(FALSE, 'сервис не выбран');
        if (trim($address) === '')
            return array(FALSE, 'адрес или ключи не указаны');
        Services::$delivered = FALSE;
        Services::$reason = '';
        try
        {
            $notifier->send($address, 'Проверка.', self::body(date('Y-m-d H:i:s'), 'TorrentMonitor', 'Проверка уведомлений: всё работает.', ''));
        }
        catch (\Throwable $e)
        {
            return array(FALSE, 'сообщение не отправлено');
        }
        if (Services::$delivered)
            return array(TRUE, $service == 'E-mail' ? 'сообщение передано почтовой программе сервера' : 'сообщение отправлено');
        return array(FALSE, 'сервис не принял сообщение'.(Services::$reason !== '' ? ' — '.Services::$reason : ''));
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
