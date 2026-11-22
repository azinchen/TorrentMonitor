<?php
//Подключается всеми точками входа: загрузка классов и config.php.
require_once __DIR__.'/autoload.php';
require_once __DIR__.'/Config.php';

//старые config.php подключают class/Config.class.php, которого больше нет: предупреждение об этом не нужно
if (is_file(dirname(__DIR__).'/config.php'))
    @include_once dirname(__DIR__).'/config.php';
