<?php

/**
 * $Id: __autoload.php 606 2019-09-12 06:50:03Z dab $
 *
 * загрузчик классов.
 *
 * имена классов и расположение в файловой системе аналогично Zend Framework
 * класс Category1_Subcategory2_Class3 распологается в файле Category1/Subcategory2/Class3.php
 * путь до каталога указывается явно абсолютный (/...) или относительный (./... ../...),
 * иначе пытаемся интерпертировать как подкаталог (чтобы не хранить в приложениях полные пути)
 *
 *  @author dab@bystrobank.ru
 */

$n = "";
$o = get_include_path();
foreach (explode(PATH_SEPARATOR, $o) as $d) {
    if ($d[0] !== "." && strpos($d, "/") === false && strpos($d, "\\") === false) { //DIRECTORY_SEPARATOR не используем - платформенно-зависимый
        foreach (explode(PATH_SEPARATOR, $o) as $s) {
            if (is_dir($s . DIRECTORY_SEPARATOR . $d)) {
                $d = $s . DIRECTORY_SEPARATOR . $d;
                break;
            }
        }
    }
    if (!is_dir($d)) {
        uncaughtFatalErrorExceptionHandler(
                new FatalErrorException("Directory '" . $d . "' not found in '" . $o . "'"));
    }
    $n = $n . PATH_SEPARATOR . realpath($d);
}
set_include_path($n . PATH_SEPARATOR . __DIR__); //себя добавить не забываем
unset($n, $o, $d, $s);

//function __autoload($c) {
spl_autoload_register(function ($c) {
    $f = str_replace((strpos($c, "\\") !== FALSE ? "\\" : "_"), DIRECTORY_SEPARATOR, $c) . ".php";
    //проверяем сами - чтоб отловить место где произошла ошибка (стандартное сообщение неинформатвно)
    $r = null;
    foreach (explode(PATH_SEPARATOR, get_include_path()) as $p) {
        if (file_exists($p . DIRECTORY_SEPARATOR . $f)) {
            // файл класса существует
            $r = $p . DIRECTORY_SEPARATOR . $f;
            break;
        }
    }
    //из __autoload нельзя выбросить исключение, поэтому зовем обработчик ошибок явно
    if (!$r) {
        if (isset($_SERVER["argv"][0]) && $_SERVER["argv"][0] == "/usr/bin/phpunit") { //phpunit использует это для проверки - не падаем
            return false;
        }
        uncaughtFatalErrorExceptionHandler(
                new FatalErrorException("File '" . $f . "' not found in '" . get_include_path() . "'"));
    }
    if (!is_readable($r)) {
        uncaughtFatalErrorExceptionHandler(
                new FatalErrorException("File '" . $r . "' not readable"));
    }
    require_once($r);
    if (!(class_exists($c, false) || interface_exists($c, false) || trait_exists($c, false))) {
        //проверяем загрузился ли класс
        uncaughtFatalErrorExceptionHandler(
                new FatalErrorException("Class '" . $c . "' not found in '" . $r . "'"));
    }
});
