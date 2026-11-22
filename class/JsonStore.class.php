<?php
//Ошибка хранилища: файл данных повреждён, недоступен или создан более новой версией.
class StorageException extends RuntimeException
{
}

//Один JSON-файл с данными.
//Чтение: файл читается целиком при каждом обращении (он небольшой), разбор JSON
//повторяется только если содержимое изменилось.
//Запись: под эксклюзивной блокировкой файл перечитывается, изменяется и заменяется
//атомарно (запись во временный файл и rename), поэтому одновременная работа
//веб-интерфейса и engine.php не приводит к потере изменений или битому файлу.
class JsonStore
{
    private $file;
    private $defaults;
    private $version;
    private $migrations;
    private $backup;

    //последнее прочитанное содержимое файла и результат его разбора
    private $cacheRaw = NULL;
    private $cache = NULL;

    //$defaults   - содержимое нового файла (без поля version)
    //$version    - текущая версия формата файла
    //$migrations - array(версия => function(array $data): array), переводит данные с этой версии на следующую
    //$backup     - хранить ли предыдущую версию файла рядом (<файл>.bak)
    public function __construct($file, array $defaults, $version = 1, array $migrations = array(), $backup = TRUE)
    {
        $this->file = $file;
        $this->defaults = $defaults;
        $this->version = $version;
        $this->migrations = $migrations;
        $this->backup = $backup;
    }

    public function file()
    {
        return $this->file;
    }

    public function exists()
    {
        clearstatcache(TRUE, $this->file);
        return is_file($this->file);
    }

    //данные файла (значения по умолчанию, если файла ещё нет)
    public function read()
    {
        $data = $this->load();
        if ($data['version'] < $this->version)
        {
            //формат устарел: обновляем под блокировкой
            $this->update(function (&$data) { return TRUE; });
            return $this->cache;
        }
        return $data;
    }

    //изменяет данные: $change(array &$data) вызывается под блокировкой со свежими данными.
    //Если $change вернула FALSE, файл не перезаписывается. Возвращает то, что вернула $change.
    public function update($change)
    {
        $dir = dirname($this->file);
        if ( ! is_dir($dir) && ! @mkdir($dir, 0700, TRUE) && ! is_dir($dir))
            throw new StorageException('Не удалось создать каталог данных: '.$dir);

        $lock = @fopen($this->file.'.lock', 'c');
        if ($lock === FALSE || ! flock($lock, LOCK_EX))
            throw new StorageException('Нет прав на запись в каталог данных: '.$dir);

        try
        {
            $data = $this->load();
            $migrated = FALSE;
            while ($data['version'] < $this->version)
            {
                if (isset($this->migrations[$data['version']]))
                    $data = call_user_func($this->migrations[$data['version']], $data);
                $data['version']++;
                $migrated = TRUE;
            }

            $result = $change($data);
            if ($result !== FALSE || $migrated)
                $this->save($data);

            return $result;
        }
        finally
        {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function load()
    {
        clearstatcache(TRUE, $this->file);
        if ( ! is_file($this->file))
            return array('version' => $this->version) + $this->defaults;

        $raw = @file_get_contents($this->file);
        if ($raw === FALSE)
            throw new StorageException('Не удалось прочитать файл данных: '.$this->file);
        if ($raw === $this->cacheRaw)
            return $this->cache;

        $data = json_decode($raw, TRUE);
        if ( ! is_array($data) || ! isset($data['version']) || ! is_int($data['version']))
            throw new StorageException('Файл данных повреждён: '.$this->file
                .($this->backup && is_file($this->file.'.bak') ? '. Предыдущая версия файла сохранена рядом: '.basename($this->file).'.bak' : ''));

        if ($data['version'] > $this->version)
            throw new StorageException('Файл данных '.$this->file.' создан более новой версией TorrentMonitor (формат '
                .$data['version'].', эта версия понимает формат '.$this->version.'). Обновите TorrentMonitor.');

        //разделы, появившиеся в новых версиях, берутся из значений по умолчанию
        $data = $data + $this->defaults;
        $this->cacheRaw = $raw;
        $this->cache = $data;
        return $data;
    }

    private function save(array $data)
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === FALSE)
            throw new StorageException('Не удалось подготовить данные для записи: '.json_last_error_msg());

        $dir = dirname($this->file);
        $tmp = $this->file.'.tmp.'.getmypid();
        if (@file_put_contents($tmp, $json."\n") === FALSE)
            throw new StorageException('Нет прав на запись в каталог данных: '.$dir);

        //файлы содержат пароли: доступ только владельцу (и группе, если так настроен каталог)
        $dirMode = @fileperms($dir);
        @chmod($tmp, ($dirMode !== FALSE && ($dirMode & 0020)) ? 0660 : 0600);
        //engine.php, запущенный от root, не должен отбирать файлы у пользователя веб-сервера
        if (function_exists('posix_geteuid') && posix_geteuid() === 0)
        {
            @chown($tmp, fileowner($dir));
            @chgrp($tmp, filegroup($dir));
        }

        if ($this->backup && is_file($this->file))
            @copy($this->file, $this->file.'.bak');

        if ( ! @rename($tmp, $this->file))
        {
            @unlink($tmp);
            throw new StorageException('Не удалось записать файл данных: '.$this->file);
        }
        $this->cacheRaw = $json."\n";
        $this->cache = $data + $this->defaults;
    }
}
?>
