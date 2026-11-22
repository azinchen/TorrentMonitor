<?php
// TM_ROOT is the code under test: /src (read-only checkout) for unit tests,
// /app (working copy inside the test stack) for integration tests.
define('TM_ROOT', rtrim(getenv('TM_ROOT') ?: '/src', '/'));
define('TESTS_DIR', __DIR__);

foreach (glob(__DIR__.'/Support/*.php') as $file)
{
    // test case base classes need PHPUnit; helper scripts load the rest without it
    if (substr($file, -12) == 'TestCase.php' && ! class_exists('PHPUnit\\Framework\\TestCase'))
        continue;
    require_once $file;
}
