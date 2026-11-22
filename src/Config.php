<?php
// Optional installation settings from config.php (see config.php.example).
// All data and regular settings are stored in the data directory.
// The class has no namespace: that is how existing config.php files refer to it.
class Config
{
    private static $confArray = array();

    // Kept for compatibility with old config.php files: config.xml is no longer used
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
