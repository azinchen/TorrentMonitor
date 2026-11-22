<?php
namespace TorrentMonitor\Data;

use TorrentMonitor\Storage\Files;

//Настройки пользователя (config.json).
final class Settings
{
    /** @return array<string,string> все настройки с учётом значений по умолчанию */
    public static function all(): array
    {
        $config = Files::config()->read();
        return array_map('strval', $config['settings'] + Files::settingsDefaults());
    }

    public static function get(string $key): ?string
    {
        $all = self::all();
        return isset($all[$key]) ? $all[$key] : NULL;
    }

    public static function on(string $key): bool
    {
        return ! empty(self::get($key));
    }

    public static function set(string $key, $value): void
    {
        self::setMany(array($key => $value));
    }

    public static function setMany(array $values): void
    {
        Files::config()->update(function (&$data) use ($values) {
            $changed = FALSE;
            foreach ($values as $key => $value)
            {
                $value = (string)$value;
                if (isset($data['settings'][$key]) && $data['settings'][$key] === $value)
                    continue;
                $data['settings'][$key] = $value;
                $changed = TRUE;
            }
            return $changed;
        });
    }

    //сервисы уведомлений: номер => array(service, type). Номера используются в настройках
    //sendUpdateService / sendWarningService.
    public static function notificationServices(): array
    {
        $list = array();
        $id = 1;
        foreach (array('E-mail', 'Prowl', 'Pushbullet', 'Pushover', 'Pushall', 'Telegram', 'Gotify') as $service)
        {
            $list[$id++] = array('service' => $service, 'type' => 'notification');
            $list[$id++] = array('service' => $service, 'type' => 'warning');
        }
        return $list;
    }

    //сервис и адрес, выбранные в настройке $setting (sendUpdateService или sendWarningService)
    /** @return array{service:?string,address:string} */
    public static function notificationTarget(string $setting): array
    {
        $id = self::get($setting);
        $services = self::notificationServices();
        if ( ! isset($services[$id]))
            return array('service' => NULL, 'address' => '');
        $config = Files::config()->read();
        return array(
            'service' => $services[$id]['service'],
            'address' => isset($config['notifications'][$id]) ? (string)$config['notifications'][$id] : '',
        );
    }

    public static function notificationAddress($id): string
    {
        $config = Files::config()->read();
        return isset($config['notifications'][$id]) ? (string)$config['notifications'][$id] : '';
    }

    public static function setNotificationAddress($id, string $address): void
    {
        Files::config()->update(function (&$data) use ($id, $address) {
            $data['notifications'][(string)$id] = $address;
        });
    }
}
