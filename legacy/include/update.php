<?php
$dir = dirname(__FILE__)."/../";
require_once $dir."bootstrap.php";

if ( ! Sys::checkAuth())
  die(header('Location: ../'));

Update::main();  
?>
