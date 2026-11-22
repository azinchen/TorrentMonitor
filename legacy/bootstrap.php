<?php
//Прежний веб-интерфейс работает поверх нового ядра через классы совместимости
//(Database, Sys, Errors, Url). Каталог legacy будет удалён вместе с этим интерфейсом.
require_once dirname(__DIR__).'/src/bootstrap.php';
require_once __DIR__.'/Database.php';
require_once __DIR__.'/Sys.php';

class_alias('TorrentMonitor\Update\Release', 'Release');
class_alias('TorrentMonitor\Update\Updater', 'Update');
class_alias('TorrentMonitor\Storage\Files', 'Storage');
class_alias('TorrentMonitor\Storage\StorageException', 'StorageException');
