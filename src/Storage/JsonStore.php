<?php
namespace TorrentMonitor\Storage;

// A single JSON data file.
// Reading: the file is read in full on every access (it is small); JSON parsing
// is repeated only if the contents have changed.
// Writing: under an exclusive lock the file is re-read, modified and replaced
// atomically (write to a temporary file and rename), so the web interface and
// engine.php working at the same time do not cause lost changes or a broken file.
class JsonStore
{
    private $file;
    private $defaults;
    private $version;
    private $migrations;
    private $backup;

    // The last read file contents and the result of parsing them
    private $cacheRaw = NULL;
    private $cache = NULL;

    // $defaults   - contents of a new file (without the version field)
    // $version    - current version of the file format
    // $migrations - array(version => function(array $data): array), migrates data from that version to the next one
    // $backup     - whether to keep the previous version of the file next to it (<file>.bak)
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

    // The file's data (defaults if the file does not exist yet)
    public function read()
    {
        $data = $this->load();
        if ($data['version'] < $this->version)
        {
            // The format is outdated: upgrade under the lock
            $this->update(function (&$data) { return TRUE; });
            return $this->cache;
        }
        return $data;
    }

    // Modifies the data: $change(array &$data) is called under the lock with fresh data.
    // If $change returned FALSE, the file is not rewritten. Returns what $change returned.
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

        // Sections introduced in newer versions are taken from the defaults
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

        // The files contain passwords: access for the owner only (and the group, if the directory is set up that way)
        $dirMode = @fileperms($dir);
        @chmod($tmp, ($dirMode !== FALSE && ($dirMode & 0020)) ? 0660 : 0600);
        // engine.php run as root must not take the files away from the web server user
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
