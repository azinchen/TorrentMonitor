<?php
// Included by all entry points: class loading and config.php.
require_once __DIR__.'/autoload.php';
require_once __DIR__.'/Config.php';

// Old config.php files include class/Config.class.php, which no longer exists: no warning about it is needed
if (is_file(dirname(__DIR__).'/config.php'))
    @include_once dirname(__DIR__).'/config.php';
