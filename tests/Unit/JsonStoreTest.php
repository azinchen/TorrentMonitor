<?php
use PHPUnit\Framework\TestCase;

require_once TM_ROOT.'/src/autoload.php';

use TorrentMonitor\Storage\JsonStore;
use TorrentMonitor\Storage\StorageException;

final class JsonStoreTest extends TestCase
{
    private $dir;
    private $file;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tm-store-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        $this->file = $this->dir.'/data.json';
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) as $name)
            if (is_file($this->dir.'/'.$name))
                unlink($this->dir.'/'.$name);
        rmdir($this->dir);
    }

    private function store($version = 1, $migrations = array(), $backup = true, $compatible = null)
    {
        return new JsonStore($this->file, array('items' => array(), 'name' => 'default'), $version, $migrations, $backup, $compatible);
    }

    private function write($data)
    {
        file_put_contents($this->file, is_string($data) ? $data : json_encode($data));
    }

    public function testMissingFileGivesDefaultsAndCreatesNothing()
    {
        $data = $this->store()->read();

        $this->assertSame(array('version' => 1, 'items' => array(), 'name' => 'default'), $data);
        $this->assertFileDoesNotExist($this->file);
    }

    public function testUpdateWritesReadableJson()
    {
        $store = $this->store();
        $store->update(function (&$data) { $data['items'][] = 'Сериал / "Serial"'; });

        $this->assertSame(array('Сериал / "Serial"'), $store->read()['items']);
        $raw = file_get_contents($this->file);
        $this->assertStringContainsString('Сериал / \"Serial\"', $raw, 'text is stored unescaped, slashes included');
        $this->assertStringContainsString("\n    \"items\"", $raw, 'file is indented for reading by a person');
        $this->assertSame(array(), glob($this->dir.'/*.tmp.*'), 'no temporary files are left');
    }

    public function testReturningFalseLeavesTheFileUntouched()
    {
        $store = $this->store();
        $store->update(function (&$data) { $data['name'] = 'first'; });
        $before = file_get_contents($this->file);
        touch($this->file, time() - 100);
        $mtime = filemtime($this->file);

        $result = $store->update(function (&$data) { $data['name'] = 'not saved'; return false; });

        $this->assertFalse($result);
        clearstatcache();
        $this->assertSame($mtime, filemtime($this->file));
        $this->assertSame($before, file_get_contents($this->file));
    }

    public function testPreviousVersionIsKeptAsBackup()
    {
        $store = $this->store();
        $store->update(function (&$data) { $data['name'] = 'first'; });
        $store->update(function (&$data) { $data['name'] = 'second'; });

        $this->assertSame('first', json_decode(file_get_contents($this->file.'.bak'), true)['name']);
        $this->assertSame('second', $store->read()['name']);
    }

    public function testBackupCanBeSwitchedOff()
    {
        $store = $this->store(1, array(), false);
        $store->update(function (&$data) { $data['name'] = 'first'; });
        $store->update(function (&$data) { $data['name'] = 'second'; });

        $this->assertFileDoesNotExist($this->file.'.bak');
    }

    public function testFileIsPrivate()
    {
        $this->store()->update(function (&$data) { $data['name'] = 'secret'; });
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->file)), -4));
    }

    public function testBackupIsPrivateToo()
    {
        $store = $this->store();
        $store->update(function (&$data) { $data['name'] = 'first'; });
        $store->update(function (&$data) { $data['name'] = 'second'; });
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->file.'.bak')), -4));
    }

    public function testGroupWritableDirectoryGivesGroupAccess()
    {
        chmod($this->dir, 0770);
        $this->store()->update(function (&$data) { $data['name'] = 'shared'; });
        $this->assertSame('0660', substr(sprintf('%o', fileperms($this->file)), -4));
    }

    public function testChangeMadeByAnotherProcessIsSeen()
    {
        $store = $this->store();
        $store->update(function (&$data) { $data['name'] = 'mine'; });
        $this->assertSame('mine', $store->read()['name']);

        // same size, same second: only the content tells the files apart
        $this->write(str_replace('mine', 'your', file_get_contents($this->file)));

        $this->assertSame('your', $store->read()['name']);
    }

    public function testUpdateStartsFromTheLatestFileContent()
    {
        $store = $this->store();
        $store->read();
        $this->write(array('version' => 1, 'items' => array('from another process'), 'name' => 'x'));

        $store->update(function (&$data) { $data['items'][] = 'mine'; });

        $this->assertSame(array('from another process', 'mine'), $store->read()['items']);
    }

    public function testSectionMissingInOldFileComesFromDefaults()
    {
        $this->write(array('version' => 1, 'name' => 'old file'));
        $data = $this->store()->read();
        $this->assertSame('old file', $data['name']);
        $this->assertSame(array(), $data['items']);
    }

    /** @dataProvider damagedFiles */
    public function testDamagedFileIsReportedAndNotOverwritten($content)
    {
        $this->write($content);
        $store = $this->store();

        try
        {
            $store->update(function (&$data) { $data['name'] = 'new'; });
            $this->fail('damaged file was accepted');
        }
        catch (StorageException $e)
        {
            $this->assertStringContainsString('повреждён', $e->getMessage());
        }
        $this->assertSame($content, file_get_contents($this->file), 'damaged file is left for the user to inspect');
    }

    public function damagedFiles()
    {
        return array(
            'cut off'       => array('{"version": 1, "items": ['),
            'empty'         => array(''),
            'not an object' => array('"text"'),
            'no version'    => array('{"items": []}'),
        );
    }

    public function testDamagedFileMessagePointsAtTheBackup()
    {
        $store = $this->store();
        $store->update(function (&$data) { $data['name'] = 'first'; });
        $store->update(function (&$data) { $data['name'] = 'second'; });
        $this->write('{broken');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('data.json.bak');
        $this->store()->read();
    }

    public function testFileFromNewerVersionIsUsedAndItsUnknownDataIsKept()
    {
        $this->write(array('version' => 5, 'compatible' => 2, 'items' => array('a'), 'future' => array('x' => 1)));
        $store = $this->store(2);

        $data = $store->read();
        $this->assertSame(array('a'), $data['items']);
        $this->assertSame('default', $data['name'], 'sections missing in the file come from the defaults');

        $store->update(function (&$data) { $data['items'][] = 'b'; $data['version'] = 1; });

        $saved = json_decode(file_get_contents($this->file), true);
        $this->assertSame(5, $saved['version'], 'an older program does not lower the format number');
        $this->assertSame(2, $saved['compatible']);
        $this->assertSame(array('x' => 1), $saved['future'], 'what this version does not know is kept');
        $this->assertSame(array('a', 'b'), $saved['items']);
    }

    public function testNewerFileWithoutACompatibilityMarkIsUsedToo()
    {
        $this->write(array('version' => 5, 'items' => array('a')));

        $this->assertSame(array('a'), $this->store(2)->read()['items']);
    }

    public function testFileThatNeedsANewerProgramIsRefused()
    {
        $this->write(array('version' => 5, 'compatible' => 4, 'items' => array()));

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('более новой версией');
        $this->store(2)->read();
    }

    public function testWrittenFileSaysWhichFormatCanReadIt()
    {
        $this->store(3, array(), true, 2)->update(function (&$data) { $data['items'][] = 'a'; });
        $saved = json_decode(file_get_contents($this->file), true);
        $this->assertSame(array('version', 'compatible'), array_slice(array_keys($saved), 0, 2));
        $this->assertSame(array(3, 2), array($saved['version'], $saved['compatible']));

        // by default a format is compatible only with itself
        unlink($this->file);
        $this->store(3)->update(function (&$data) { $data['items'][] = 'a'; });
        $this->assertSame(3, json_decode(file_get_contents($this->file), true)['compatible']);
    }

    public function testOldFormatIsMigratedStepByStepOnFirstRead()
    {
        $this->write(array('version' => 1, 'title' => 'Old'));
        $migrations = array(
            1 => function ($data) { $data['name'] = $data['title']; unset($data['title']); return $data; },
            2 => function ($data) { $data['name'] = strtoupper($data['name']); return $data; },
        );

        $data = $this->store(3, $migrations)->read();

        $this->assertSame(3, $data['version']);
        $this->assertSame('OLD', $data['name']);
        $this->assertArrayNotHasKey('title', $data);
        $saved = json_decode(file_get_contents($this->file), true);
        $this->assertSame(3, $saved['version'], 'migrated file is saved');
        $this->assertSame('Old', json_decode(file_get_contents($this->file.'.bak'), true)['title'], 'file from before the migration is kept');
    }

    public function testUnwritableDirectoryIsReported()
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0)
            $this->markTestSkipped('root can write everywhere');
        chmod($this->dir, 0500);
        try
        {
            $this->expectException(StorageException::class);
            $this->store()->update(function (&$data) { $data['name'] = 'x'; });
        }
        finally
        {
            chmod($this->dir, 0700);
        }
    }

    public function testParallelWritersDoNotLoseUpdates()
    {
        $script = $this->dir.'/writer.php';
        file_put_contents($script, '<?php require $argv[1]; $s = new \TorrentMonitor\Storage\JsonStore($argv[2], array("items" => array()), 1, array(), false);'
            .'for ($i = 0; $i < 40; $i++) $s->update(function (&$d) use ($argv, $i) { $d["items"][] = $argv[3]."-".$i; });');
        $processes = array();
        for ($w = 0; $w < 5; $w++)
            $processes[] = proc_open(array(PHP_BINARY, $script, TM_ROOT.'/src/autoload.php', $this->file, 'w'.$w), array(), $pipes);
        foreach ($processes as $process)
            $this->assertSame(0, proc_close($process));
        unlink($script);

        $items = $this->store()->read()['items'];
        $this->assertCount(200, $items);
        $this->assertCount(200, array_unique($items));
    }
}
