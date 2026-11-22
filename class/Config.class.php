<?php
//Необязательные настройки установки из config.php (см. config.php.example).
//Все данные и обычные настройки хранятся в каталоге данных (см. Storage).
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

if (is_file(dirname(__FILE__).'/../config.php'))
    include_once dirname(__FILE__).'/../config.php';
?>
