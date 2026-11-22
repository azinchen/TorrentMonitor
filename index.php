<?php
// Setup libxml for PHP version compatibility
// libxml_disable_entity_loader() was deprecated in PHP 8.0
if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
    libxml_disable_entity_loader(true);
}

$dir = dirname(__FILE__)."/";
include_once $dir."class/Config.class.php";
include_once $dir."class/Database.class.php";
include_once $dir."class/System.class.php";

//данные от версии с базой данных ещё не перенесены, либо файлы данных недоступны
try
{
    $storageProblem = Sys::legacyDatabaseNotice();
    if ($storageProblem === NULL)
        Database::getSetting('auth');
}
catch (StorageException $e)
{
    $storageProblem = $e->getMessage();
}
if ($storageProblem !== NULL)
{
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    die('<!doctype html><meta charset="utf-8"><title>TorrentMonitor</title><body style="font-family:sans-serif;max-width:46em;margin:3em auto;line-height:1.5">'
        .'<h1>TorrentMonitor не может начать работу</h1><p>'.htmlspecialchars($storageProblem).'</p></body>');
}

if (session_status() !== PHP_SESSION_ACTIVE)
    session_start();

if (empty($_SESSION['csrf_token']))
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

if (Sys::checkAuth())
    require_once "pages/main.php";
else
    require_once "pages/auth.php";
?>