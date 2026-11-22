<?php
// (Re)creates the SQLite database of the application under test from db_schema/sqlite.sql.
// The file is emptied in place, not deleted: other processes may have it open.
$file = '/data/tm.sqlite';
$pdo = new PDO('sqlite:'.$file);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout = 10000');
$pdo->beginTransaction();
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table)
    $pdo->exec('DROP TABLE "'.$table.'"');
if ($pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'sqlite_sequence'")->fetchColumn())
    $pdo->exec('DELETE FROM sqlite_sequence');
$pdo->exec(file_get_contents('/app/db_schema/sqlite.sql'));
$pdo->commit();
chmod($file, 0666);
