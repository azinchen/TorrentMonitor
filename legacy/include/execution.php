<?php
$dir = dirname(__FILE__)."/../";
require_once $dir."bootstrap.php";
if ( ! Sys::checkAuth())
    die(header('Location: ../'));
?>
<div class="top-bar mb-2">
    <div class="top-bar__title"><svg><use href="assets/img/sprite.svg#play" /></svg> Запуск</div>
</div>
<div class="check">
<?php include $dir.'../engine.php'; ?>
</div>
