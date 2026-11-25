<?php
namespace TorrentMonitor\Storage;

// A single JSON data file.
//
// Any version of the program must be able to work with a file written by any other version.
// That holds as long as the format only grows: new sections and fields are added with a
// default for files that do not have them, nothing is renamed or removed, and whoever
// rewrites the file keeps what it does not know. Two numbers are stored in the file:
//   version    - the format the file was written in; an older file is migrated when first opened;
//   compatible - the oldest format a program must understand to work with the file. It changes
//                only if a change that cannot be made by adding is ever needed: a program
//                older than that refuses the file instead of misreading it.
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
    private $compatible;
    private $migrations;
    private $backup;
    private $repair;
    private $recover;
    // What happened to a damaged file that was started anew: the name it was put aside under, or NULL
    public $recovered = NULL;

    // The last read file contents and the result of parsing them
    private $cacheRaw = NULL;
    private $cache = NULL;

    // $defaults   - contents of a new file (without the version field)
    // $version    - current version of the file format
    // $migrations - array(version => function(array $data): array), migrates data from that version to the next one
    // $backup     - whether to keep the previous version of the file next to it (<file>.bak)
    // $compatible - the oldest format that can still read what this version writes (NULL - $version)
    // $repair     - function(array $data): array that checks what the file holds and puts it right
    //               (see Repair); it throws Damaged when a part cannot be guessed
    // $recover    - the file holds nothing that cannot be found out again: a damaged one is put
    //               aside (<file>.broken) and started anew instead of stopping the program
    public function __construct($file, array $defaults, $version = 1, array $migrations = array(), $backup = TRUE, $compatible = NULL, $repair = NULL, $recover = FALSE)
    {
        $this->repair = $repair;
        $this->recover = $recover;
        $this->file = $file;
        $this->defaults = $defaults;
        $this->version = $version;
        $this->compatible = $compatible === NULL ? $version : $compatible;
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
            if ($migrated && $this->repair !== NULL)
            {
                try
                {
                    $data = call_user_func($this->repair, $data) + $this->defaults;
                }
                catch (Damaged $e)
                {
                    throw new StorageException(self::damagedText($this->file, $e->getMessage(), $this->backup));
                }
            }

            // the format numbers cannot be changed by the caller
            $version = $data['version'];
            $compatible = isset($data['compatible']) ? $data['compatible'] : NULL;
            $result = $change($data);
            $data['version'] = $version;
            // A file written in a newer format keeps its numbers: this version only changed what it knows
            if ($version <= $this->version)
                $compatible = $this->compatible;
            unset($data['compatible']);
            if ($compatible !== NULL)
                $data = array('version' => $version, 'compatible' => $compatible) + $data;
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
            return $this->damaged('');

        // A file from a newer version is used as it is, unless it says that this version cannot read it
        if (isset($data['compatible']) && is_int($data['compatible']) && $data['compatible'] > $this->version)
            throw new StorageException('Файл данных '.$this->file.' создан более новой версией TorrentMonitor и этой версией не читается (нужен формат '
                .$data['compatible'].', эта версия понимает формат '.$this->version.'). Обновите TorrentMonitor.');

        // What the file holds is checked and put right; a file of an earlier format is checked
        // after it has been migrated (see update())
        if ($this->repair !== NULL && $data['version'] >= $this->version)
        {
            try
            {
                $data = call_user_func($this->repair, $data);
            }
            catch (Damaged $e)
            {
                return $this->damaged($e->getMessage());
            }
        }

        // Sections introduced in newer versions are taken from the defaults
        $data = $data + $this->defaults;
        $this->cacheRaw = $raw;
        $this->cache = $data;
        return $data;
    }

    // Copies kept next to the file: .bak - the file before the last save; .bak.1 and .bak.2 - older
    // ones, about a day and about two days old. Copies of the last saves alone would all be minutes
    // old - a check run saves many times - and of no use after a mistake noticed the next day.
    const COPIES = array('bak', 'bak.1', 'bak.2');
    const COPY_AGE = 86400;

    // Once a day the copy of the last save becomes the copy of the day, and that one the copy of the day before
    private function age(): void
    {
        $last = $this->file.'.bak';
        $day = $this->file.'.bak.1';
        clearstatcache(TRUE, $day);
        if ( ! is_file($last) || (is_file($day) && time() - (int)@filemtime($day) < self::COPY_AGE))
            return;
        if (is_file($day))
            @rename($day, $this->file.'.bak.2');
        @rename($last, $day);
    }

    // The file cannot be used. $part - what is wrong in it ('' - it cannot be read at all).
    // A file that holds nothing lasting is put aside and started anew; otherwise the program stops.
    private function damaged(string $part)
    {
        if ($this->recover)
        {
            $aside = $this->file.'.broken';
            if (@rename($this->file, $aside))
            {
                $this->recovered = basename($aside);
                $this->cacheRaw = NULL;
                return array('version' => $this->version) + $this->defaults;
            }
        }
        throw new StorageException(self::damagedText($this->file, $part, $this->backup));
    }

    public static function damagedText(string $file, string $part, bool $backup): string
    {
        return 'Файл данных повреждён: '.$file.($part !== '' ? ' ('.$part.')' : '')
            .($backup && is_file($file.'.bak') ? '. Предыдущая версия файла сохранена рядом: '.basename($file).'.bak' : '');
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

        Files::protect($tmp);

        if ($this->backup && is_file($this->file))
        {
            $this->age();
            if (@copy($this->file, $this->file.'.bak'))
                Files::protect($this->file.'.bak');
        }

        if ( ! @rename($tmp, $this->file))
        {
            @unlink($tmp);
            throw new StorageException('Не удалось записать файл данных: '.$this->file);
        }
        $this->cacheRaw = $json."\n";
        $this->cache = $data + $this->defaults;
    }
}
