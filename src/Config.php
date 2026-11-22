<?php
//Необязательные настройки установки из config.php (см. config.php.example).
//Все данные и обычные настройки хранятся в каталоге данных.
//Класс без пространства имён: так к нему обращаются существующие config.php.
class Config
{
    private static $confArray = array();

    //оставлено для совместимости со старыми config.php: config.xml больше не используется
    public static function extended($config = NULL)
    {
    }

    public static function read($name)
    {
        return self::$confArray[$name] ?? NULL;
    }

    public static function write($name, $value)
    {
        self::$confArray[$name] = $value;
    }
}
