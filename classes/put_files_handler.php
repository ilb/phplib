<?php

/**
 * $Id: put_files_handler.php 612 2019-11-11 05:52:25Z dab $
 *
 * put_files_handler
 *
 * реализует работу с файлами полученным методом POST аналогично загрузке через POST
 * http://dab.net.ilb.ru/public/php/manual/html/features.file-upload.put-method.html
 * http://dab.net.ilb.ru/public/php/manual/html/features.file-upload.html
 *
 * is_uploaded_file() и move_uploaded_file() с файлами полученными PUT-ом НЕ РАБОТАЮТ.
 * на время закачки влияет max_execution_time.
 *
 * временный файл автоматически удаляется после завершения скрипта, аналогично POST
 *
 * @author dab@ilb.ru
 */
if (array_key_exists("REQUEST_METHOD", $_SERVER) && $_SERVER["REQUEST_METHOD"] == "PUT" && ini_get("file_uploads")) {
    $error = 0;
    // читаем данные из настроек php
    $tempnam = tempnam(ini_get("upload_tmp_dir"), "php_put_file");
    $upload_max_filesize = trim(ini_get("upload_max_filesize"));
    $units = substr($upload_max_filesize, -1);
    if (in_array($units, array("g", "G", "m", "M", "k", "K"))) {
        $upload_max_filesize = (int)substr($upload_max_filesize, 0, -1);
        switch ($units) {
            case 'g': case 'G': $upload_max_filesize *= 1073741824;
                break;
            case 'm': case 'M': $upload_max_filesize *= 1048576;
                break;
            case 'k': case 'K': $upload_max_filesize *= 1024;
                break;
        }
    }

    /* Данные PUT находятся в потоке stdin */
    $input = fopen("php://input", "rb"); // в документации указано неверно: поток называется input

    /* Открываем файл для записи */
    $fp = fopen($tempnam, "wb");

    /* Читаем данные блоками размером в 1 KB и записываем их в файл */
    $size = 0; //по ходу закачки считаем размер
    while (!feof($input)) {
        fwrite($fp, fread($input, 1024));
        $size += 1024; // плюс-минус килобайт :)
        if ($size > $upload_max_filesize) {
            $error = UPLOAD_ERR_INI_SIZE;
            exit;
        }
    }

    /* Закрываем потоки */
    fclose($fp);
    fclose($input);

    if(array_key_exists("CONTENT_LENGTH", $_SERVER) && $_SERVER["CONTENT_LENGTH"] != filesize($tempnam)) {
        $error = UPLOAD_ERR_PARTIAL;
    }

    // имя элемента фиксировано! возможен конфликт с одноименным из POST-а
    $_FILES["put_file"] = array(
        "name" => "put_file",
        "type" => array_key_exists("CONTENT_TYPE", $_SERVER) ? $_SERVER["CONTENT_TYPE"] : "application/octet-stream",
        "tmp_name" => $tempnam,
        "error" => $error,
        "size" => filesize($tempnam),
    );

    // используя lambda-функцию
    register_shutdown_function(function($tempnam) { file_exists($tempnam) && unlink($tempnam); }, $tempnam);

    //cleanup
    unset($error, $upload_max_filesize, $units, $input, $tempnam, $fp, $data, $size);
}
