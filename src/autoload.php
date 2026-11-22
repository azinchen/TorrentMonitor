<?php
//Загрузка классов TorrentMonitor: TorrentMonitor\Http\Client -> src/Http/Client.php
spl_autoload_register(function ($class) {
    $prefix = 'TorrentMonitor\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0)
        return;
    $file = __DIR__.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($file))
        require $file;
});
