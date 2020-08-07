<?php

/**
 * $Id: auto_prepend_file.php 575 2018-09-21 07:00:11Z dab $
 */

//общий для всех скриптов глобальный конфиг ищем рядом с системным php.ini
require_once(dirname(get_cfg_var("cfg_file_path")) . "/config.php");

//либы грузим конкретной версии
require_once(__DIR__ . "/fatal_error_handler.php");
require_once(__DIR__ . "/posix_extension.php");
require_once(__DIR__ . "/put_files_handler.php");
