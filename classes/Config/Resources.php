<?php

/**
 *
 * читаем настройки из различных источников и выполняем их наложение в соответствии с приоритетами
 * чтобы каждый раз далеко не ходить результат кешируется по времени на 10 сек
 * загруженные значения доступны в массиве $_SERVER как и остальное окружение скрипта
 * типы данных могут быть описаны как в java-нотации "java.lang.Boolean", так и в псевдо-нотации "bool" "boolean" и т.д.
 * (для удобства лучше одинаковые для java и не java-приложений использовать)
 *
 * неявно проверяется и подгружаются значения из пользовательского контекстного файла ~/.config/context.xml
 * предназначено для отладки, нерекомендует для продакшена (неявное поведение)
 *
 * пример:
 * Config_Resources::load("web.xml", "context.xml");
 *
 * @version $Id: Resources.php 630 2020-06-18 10:05:19Z dab $
 * @package Config
 *
 * @author dab@bystrobank.ru
 */
class Config_Resources {

    const cacheTTL = 10; //сек
    const unixHome = "HOME";
    const winHome = "USERPROFILE";
    const contextDir = ".config";
    const contextFile = "context.xml";
    const def = 1;
    const over = 2;
    const kill = 3;
    const ldapPrefix = "LDAPPREFIX";

    /**
     * загрузить значения из настроек в окружение скрипта
     *
     * @param string $envFile дескриптор приложения (файл с реестром всех настроек)
     * @param string $contextFile необязательный контекстный файл (например экземпляра приложения)
     * @return boolean - true если загружено из кеша
     */
    public static function load($envFile, $contextFile = null) {
        //сюда собираем или распаковываем содержимое кеша
        $cache = array(self::def => array(), self::over => array(), self::kill => array());
        //имена глобалей за которыми придется идти
        $globenv = array();
        //требуется проверить на пустые значения
        $nullcheck = array();
        //запоминаем типы из декларации элемента в web.xml
        $types = array();
        //для проверки уникальности ключей в файлах
        $uniqweb = array();
        $uniqctx = array();
        $uniqusr = array();
        $userCtxFile = null;

        $testFile = realpath($envFile);
        if (!$testFile) {
            trigger_error("file not exists '" . $envFile . "'", E_USER_ERROR);
        }
        $envFile = $testFile;
        //ищем пользовательский файл контекста
        if (array_key_exists(self::unixHome, $_SERVER)) { //unix
            $testFile = $_SERVER[self::unixHome] . DIRECTORY_SEPARATOR . self::contextDir . DIRECTORY_SEPARATOR . self::contextFile;
            $userCtxFile = realpath($testFile);
            //trigger_error($userCtxFile);
        } else if (array_key_exists(self::winHome, $_SERVER)) { //windows
            $testFile = $_SERVER[self::winHome] . DIRECTORY_SEPARATOR . self::contextDir . DIRECTORY_SEPARATOR . self::contextFile;
            $userCtxFile = realpath($testFile);
        }

        //если явно не указан контекст проверим неявно в /etc и по-старому рядом с web.xml
        if (!$contextFile) {
            $testFile = "/etc/php/context/" . (getenv("USERNAME") ? getenv("USERNAME") : posix_getpwuid(posix_geteuid())["name"]) . DIRECTORY_SEPARATOR . basename(dirname(dirname($envFile))) . ".xml";
            $contextFile = realpath($testFile);
            $testFile2 = dirname($envFile) . DIRECTORY_SEPARATOR . "context.xml";
            $contextFile2 = realpath($testFile2);
            if ($contextFile2) { //ошибка! только один контекстный файл допускаем
                if ($contextFile) {
                    trigger_error("duplicated context file '" . $contextFile . "' and '" . $contextFile2 . "'", E_USER_ERROR);
                }
                $contextFile = $contextFile2;
            }
        }
        //файл кеша - один на наборконфигфайлов-пользователя (конфиги соответвенно разные в разных приложениях)
        $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "Config_Resources_" . md5($envFile . $contextFile . $userCtxFile) . "_" . (getenv("USERNAME") ? getenv("USERNAME") : posix_getuid());

        //если кеш есть
        if (file_exists($cacheFile)) {
            //если кеш не старее любого из конфигов и кода этого файла
            if (max(filemtime(__FILE__), filemtime($envFile), ($contextFile ? filemtime($contextFile) : 0), ($userCtxFile ? filemtime($userCtxFile) : 0)) < filemtime($cacheFile)) {
                //если кеш не протух еще
                if (time() - filemtime($cacheFile) < self::cacheTTL) {
                    //только тогда берем данные из кеша
                    $cache = unserialize(file_get_contents($cacheFile));
                    self::fillFromCache($cache);
                    return true;
                }
            }
        }

        $lockTime = time();
        //выставляем эксклюзивную блокировку файла
        $lockFile = $cacheFile . ".lock";
        $lockfp = fopen($lockFile, "w");
        flock($lockfp, LOCK_EX);

        //чистим кеш фс - иначе получим неверное время изменения файла
        clearstatcache(true, $cacheFile);

        //после получения блокировки перепроверяем кеш - не знам что произошло пока ждали - возможно ктото уже обновил его
        if (file_exists($cacheFile) && filemtime($cacheFile) > $lockTime) {
            //файл новее чем мы начали ждать -  берем данные из кеша
            $cache = unserialize(file_get_contents($cacheFile));
            //освобождаем блокировку
            if (file_exists($lockFile)) { //файла может уже не быть
                unlink($lockFile);
            }
            flock($lockfp, LOCK_UN); //а дескриптор остался
            fclose($lockfp);

            self::fillFromCache($cache);
            return true;
        }

        //читаем дефолты и глобали
        $xr = new XMLReader();
        $xr->xml(file_get_contents($envFile));
        while ($xr->nodeType != XMLReader::ELEMENT) {
            $xr->read();
        }
        if ($xr->name != "web-app") {
            trigger_error("no <web-app> element in '" . $envFile . "'", E_USER_ERROR);
        }
        while ($xr->read()) {
            if ($xr->nodeType == XMLReader::ELEMENT) {
                $name = $type = $value = null;
                if ($xr->name == "env-entry") {
                    while ($xr->read()) {
                        if ($xr->nodeType == XMLReader::ELEMENT) {
                            if ($xr->name == "env-entry-name") {
                                if ($name) {
                                    trigger_error("'env-entry-name' redefined at " . $envFile . ":" . $xr->expand()->getLineNo(), E_USER_ERROR);
                                }
                                $name = $xr->readString();
                                if (array_key_exists($name, $uniqweb)) {
                                    trigger_error("duplicate key for '" . $name . "' declared at " . $envFile . ":" . $xr->expand()->getLineNo(), E_USER_ERROR);
                                }
                                $uniqweb[$name] = true;
                            } else if ($xr->name == "env-entry-type") {
                                if ($type) {
                                    trigger_error("'env-entry-type' redefined at " . $envFile . ":" . $xr->expand()->getLineNo(), E_USER_ERROR);
                                }
                                $type = $xr->readString();
                            } else if ($xr->name == "env-entry-value") {
                                if ($value) {
                                    trigger_error("'env-entry-value' redefined at " . $envFile . ":" . $xr->expand()->getLineNo(), E_USER_ERROR);
                                }
                                $value = $xr->readString();
                            }
                        } else if ($xr->nodeType == XMLReader::END_ELEMENT && $xr->name == "env-entry") {
                            //var_dump("def: " . $name . " " . $type . " " . $value);
                            if (!$name) {
                                trigger_error("'env-entry-name' not found " . $envFile . ":" . $xr->expand()->getLineNo(), E_USER_ERROR);
                            }
                            $cginame = self::getCGIname($name);
                            $types[$name] = $type;
                            if ($value) {
                                $_SERVER[$name] = Config_Resources::convertType($type, $value);
                                //конфиг приложения перебивает окружение и глобали
                                $cache[self::over][$name] = $_SERVER[$name];
                            } else if ($name != $cginame && array_key_exists($cginame, $_SERVER)) {
                                //есть пхп-вая переменная с "похожим" CGI-наименованием - считаем что "оно"
                                $value = $_SERVER[$cginame];
                                $_SERVER[$name] = Config_Resources::convertType($type, $value);
                                $cache[self::def][$name] = $_SERVER[$name];
                                //сбрасываем значение с CGI-транслитерированным наименованием, чтоб не дублировался и не путаться
                                self::unsetValue($cginame);
                                $cache[self::kill][$cginame] = true;
                            } else if (array_key_exists($name, $_SERVER)) {
                                //есть пхп-вая переменная - берем её и приводим тип
                                $value = $_SERVER[$name];
                                $_SERVER[$name] = Config_Resources::convertType($type, $value);
                                $cache[self::def][$name] = $_SERVER[$name];
                            } else {
                                $cache[self::def][$name] = $value;
                                //используем дефолтное значение только если его еще не установлено в окружении
                                //смотрим в реальное окружение, в $_ENV и $_SERVER имена уже искорёжены
                                $value = getenv($name);
                                if ($value === false) {
                                    $value = null;
                                }
                                // TODO не допускаем пустых значений? нет значений переменной - падаем? может быть заполнено позже?
                                //if ($value == false) {
                                //    trigger_error("no value for '" . $name . "' declared at " . $envFile . ":" . $xr->expand()->getLineNo(), E_USER_ERROR);
                                //}
                                $_SERVER[$name] = Config_Resources::convertType($type, $value);
                                $nullcheck[$name] = $envFile . ":" . $xr->expand()->getLineNo();
                            }
                            //var_dump(__LINE__, $cache);
                            $name = $type = $value = null;
                            break;
                        }
                    }
                } else if ($xr->name == "resource-env-ref") {
                    while ($xr->read()) {
                        if ($xr->nodeType == XMLReader::ELEMENT) {
                            if ($xr->name == "resource-env-ref-name") {
                                if ($name) {
                                    trigger_error("'resource-env-ref-name' redefined at " . $envFile . ":" . $xr->expand()->getLineNo(), E_USER_ERROR);
                                }
                                $name = $xr->readString();
                                if (array_key_exists($name, $uniqweb)) {
                                    trigger_error("duplicate key for '" . $name . "' declared at " . $envFile . ":" . $xr->expand()->getLineNo(), E_USER_ERROR);
                                }
                                $uniqweb[$name] = true;
                            } else if ($xr->name == "resource-env-ref-type") {
                                if ($type) {
                                    trigger_error("'resource-env-ref-type' redefined at " . $envFile . ":" . $xr->expand()->getLineNo(), E_USER_ERROR);
                                }
                                $type = $xr->readString();
                            }
                        } else if ($xr->nodeType == XMLReader::END_ELEMENT && $xr->name == "resource-env-ref") {
                            //var_dump("glob: " . $name);
                            if (!$name) {
                                trigger_error("'resource-env-ref-name' not found " . $envFile . ":" . $xr->expand()->getLineNo(), E_USER_ERROR);
                            }
                            $cginame = self::getCGIname($name);
                            $types[$name] = $type;
                            if ($name != $cginame && array_key_exists($cginame, $_SERVER)) {
                                //есть пхп-вая переменная с "похожим" CGI-наименованием - считаем что "оно"
                                $value = $_SERVER[$cginame];
                                //сбрасываем значение с CGI-транслитерированным наименованием, чтоб не дублировался и не путаться
                                self::unsetValue($cginame);
                                $cache[self::kill][$cginame] = true;
                            } else {
                                //смотрим в реальное окружение, в $_ENV и $_SERVER имена уже искорёжены
                                $value = getenv($name);
                            }
                            //используем глобальное значение только если его еще не установлено в окружении
                            if ($value === false) {
                                $value = null;
                            }
                            //запомним на потом чтобы достать значения позже
                            $globenv[$name] = true;
                            $_SERVER[$name] = $value;
                            $name = $type = $value = null;
                            break;
                        }
                    }
                }
            }
        }
        $xr->close();

        //читаем из контекста приложения - возможно их и не потребуется заполнять глобалями
        if ($contextFile) {
            $xr = new XMLReader();
            $xr->xml(file_get_contents($contextFile));
            while ($xr->nodeType != XMLReader::ELEMENT) {
                $xr->read();
            }
            if ($xr->name != "Context") {
                trigger_error("no <Context> element in '" . $contextFile . "'", E_USER_ERROR);
            }
            while ($xr->read()) {
                $name = $type = $value = null;
                if ($xr->nodeType == XMLReader::ELEMENT && $xr->name == "Environment") {
                    $name = $xr->getAttribute("name");
                    if (array_key_exists($name, $uniqctx)) {
                         trigger_error("duplicate key for '" . $name . "' declared at " . $contextFile . ":" . $xr->expand()->getLineNo(), E_USER_ERROR);
                    }
                    $uniqctx[$name] = true;
                    $type = $xr->getAttribute("type");
                    $value = $xr->getAttribute("value");
                    if ($name == self::ldapPrefix) {
                        $_SERVER[self::ldapPrefix] = $value;
                        continue;
                    }
                    //var_dump("ctx: " . $name . " " . $type . " " . $value);
                    if (!array_key_exists($name, $_SERVER) || !array_key_exists($name, $types)) {
                        //незадекларированные элементы из контекста не принимаем - "таможня"
                       trigger_error("undeclared element '" . $name . "' defined at " . $contextFile . ":" . $xr->expand()->getLineNo(), E_USER_WARNING);
                    }
                    if (array_key_exists($name, $_SERVER) && array_key_exists($name, $types)) {
                        //незадекларированные элементы не принимаем - просто пропускаем - они могут быть "не наши"
                        if ($types[$name] !== $type) {
                            //если тип значения не совпадает с задекларированным в web.xml типом
                            trigger_error("element '" . $name . "' must be of type '" . $types[$name] . "' at " . $contextFile . ":" . $xr->expand()->getLineNo(), E_USER_ERROR);
                        }
                        //контекстное значение перебивает ранее установленные дефолты, глобали, окружение и проч.
                        $_SERVER[$name] = Config_Resources::convertType($type, $value);
                        $cache[self::over][$name] = $_SERVER[$name];
                        unset($cache[self::def][$name]);
                        //получили контекстное значение - в глобали смысла нет смотреть
                        unset($globenv[$name]);
                    }
                }
            }
            $xr->close();
        }

        //перебиваем значениями из пользовательского контекста - только существующие!
        if ($userCtxFile && $userCtxFile !== $contextFile) {
            $xr = new XMLReader();
            $xr->xml(file_get_contents($userCtxFile));
            while ($xr->nodeType != XMLReader::ELEMENT) {
                $xr->read();
            }
            if ($xr->name != "Context") {
                trigger_error("no <Context> element in '" . $userCtxFile . "'", E_USER_ERROR);
            }
            while ($xr->read()) {
                $name = $type = $value = null;
                if ($xr->nodeType == XMLReader::ELEMENT && $xr->name == "Environment") {
                    $name = $xr->getAttribute("name");
                    if (array_key_exists($name, $uniqusr)) {
                         trigger_error("duplicate key for '" . $name . "' declared at " . $userCtxFile . ":" . $xr->expand()->getLineNo(), E_USER_ERROR);
                    }
                    $uniqusr[$name] = true;
                    $type = $xr->getAttribute("type");
                    $value = $xr->getAttribute("value");
                    if ($name == self::ldapPrefix) {
                        $_SERVER[self::ldapPrefix] = $value;
                        continue;
                    }
                    //var_dump("userctx: " . $name . " " . $type . " " . $value);
                    if (array_key_exists($name, $_SERVER)) {
                        //незадекларированные элементы не принимаем - просто пропускаем - они могут быть "не наши"
                        //контекстное значение перебивает ранее установленные дефолты, глобали, окружение и проч.
                        if ($types[$name] !== $type) {
                            //если тип значения не совпадает с задекларированным в web.xml типом
                            trigger_error("element '" . $name . "' must be of type '" . $types[$name] . "' at " . $userCtxFile . ":" . $xr->expand()->getLineNo(), E_USER_ERROR);
                        }
                        $_SERVER[$name] = Config_Resources::convertType($type, $value);
                        $cache[self::over][$name] = $_SERVER[$name];
                        unset($cache[self::def][$name]);
                        //получили контекстное значение - в глобали смысла нет смотреть
                        unset($globenv[$name]);
                    }
                }
            }
            $xr->close();
        }

        //заполняем значения глобалей
        //var_dump(__LINE__, $globenv);
        //NB! disable LDAP on external phplib
        if (false && count($globenv) > 0) { //если осталось что заполнять
            $ds = ldap_connect();
            ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ds, LDAP_OPT_DEBUG_LEVEL, 7);
            if (file_exists($cacheFile)) {
                $sr = ldap_read($ds, "cn=lastmod,c=ru", "(objectClass=lastmod)", array("modifyTimestamp"));
                //сервер поддерживает ластмод - сравним время кеша с ним
                if ($sr) {
                    $lastmod = ldap_get_entries($ds, $sr);
                    //преобразуем формат времени
                    $u = strptime($lastmod[0]["modifytimestamp"][0], "%Y%m%d%H%M%SZ");
                    if ($u == false) {
                        trigger_error("strptime() failed", E_USER_ERROR);
                    }
                    if (gmmktime($u["tm_hour"], $u["tm_min"], $u["tm_sec"], $u["tm_mon"] + 1, $u["tm_mday"], $u["tm_year"] + 1900) < filemtime($cacheFile)) {
                        //если лдап старее кеша - берем поближе из кеша
                        // TODO сравнение "старее-моложе" требует точной синхронизации времени на хостах. заменить на "такойже-другой"?
                        $oldcache = unserialize(file_get_contents($cacheFile));
                        //var_dump(__LINE__, $oldcache);
                        foreach ($globenv as $name => $value) {
                            //есть установленное из окружения значение
                            if (array_key_exists($name, $oldcache[self::def])) {
                                if ($_SERVER[$name] === null) {
                                    $_SERVER[$name] = $oldcache[self::def][$name];
                                }
                                //перекладываем в новый кеш
                                $cache[self::def][$name] = $oldcache[self::def][$name];
                                //выкашиваем значения которых уже получены
                                unset($globenv[$name]);
                            }
                        }
                    }
                }
                ldap_free_result($sr);
            }

            //читаем лдапские ресурсы
            foreach ($globenv as $name => $value) {
                if ($name[0] == '.' && array_key_exists(self::ldapPrefix, $_SERVER)) {
                    $cn = $_SERVER[self::ldapPrefix] . $name;
                } else {
                    $cn = $name;
                }
                $sr = ldap_search($ds, null, "(&(objectClass=applicationProcess)(cn=" . $cn . "))", array("labeledURI"));
                $entry = ldap_first_entry($ds, $sr);
                if (!$entry) {
                    trigger_error("ldap entry not found '" . $cn . "'", E_USER_ERROR);
                }
                $attrs = ldap_get_attributes($ds, $entry);
                ldap_free_result($sr);
                //нет установленного из окружения значения
                if ($_SERVER[$name] === null) {
                    $_SERVER[$name] = $attrs["labeledURI"][0];
                }
                //но в кеш всегда кладем лдапское
                $cache[self::def][$name] = $attrs["labeledURI"][0];
                //var_dump(__LINE__, $cache, $value);
                //sleep(5);
            }
            ldap_close($ds);
        }

        //проверим чтобы не было пустых
        foreach ($nullcheck as $name => $fileline) {
            if (!array_key_exists($name, $_SERVER) || is_null($_SERVER[$name])) {
                trigger_error("no value for '" . $name . "' declared at " . $fileline, E_USER_ERROR);
            }
        }

        //var_dump($cache);
        //создаем пустой файл кеша доступный только владельцу
        $tmpFile = $cacheFile . "." . getmypid() . ".tmp";
        touch($tmpFile);
        chmod($tmpFile, 0600);
        //заполняем файл кеша
        file_put_contents($tmpFile, serialize($cache), LOCK_EX);
        if (DIRECTORY_SEPARATOR != "\\") { //not windows?
            chmod($tmpFile, 0400);
        }
        rename($tmpFile, $cacheFile);

        //освобождаем блокировку
        if (file_exists($lockFile)) { //файла может уже не быть
            if (DIRECTORY_SEPARATOR == "\\") { //is windows?
                 fclose($lockfp); //на винде не дает удалить открытый файл
                 $lockfp = null;
            }
            unlink($lockFile);
        }
        if ($lockfp) {
            flock($lockfp, LOCK_UN); //а дескриптор остался
            fclose($lockfp);
        }

        return false;
    }

    /**
     * приведение типов значений к пхп-ным по наименованию типа (ява "java.lang.boolean" или псевдо-тип "bool" и т.д.)
     *
     * @param string $type тип
     * @param string $value исходное значение
     * @return mixed значение приведенное к типу
     */
    private static function convertType($type, $value) {
        if ($value == null) {
            return $value;
        }
        $type = strtolower($type);
        if (strpos($type, "bool") !== false) {
            $newvalue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($newvalue === null) {
                trigger_error("not boolean value '" . $value . "'", E_USER_ERROR);
            }
            return $newvalue;
        } else if (strpos($type, "int") !== false || strpos($type, "short") !== false || strpos($type, "long") !== false) {
            if (!ctype_digit($value)) {
                trigger_error("not integer value '" . $value . "'", E_USER_ERROR);
            }
            return intval($value);
        } else if (strpos($type, "float") !== false || strpos($type, "double") !== false) {
            if (!is_numeric($value)) {
                trigger_error("not numeric value '" . $value . "'", E_USER_ERROR);
            }
            return floatval($value);
        }
        //все остальные типы как есть - строкой
        return $value;
    }

    /**
     * загружалка значений из кеша в окружение скрипта
     *
     * @param array $cache ассоциативный массив ключ-значение из кеша
     */
    private static function fillFromCache(array $cache) {
        foreach ($cache[self::over] as $name => $value) {
            //кешированные из конфигов перекрывают
            $_SERVER[$name] = $value;
        }
        foreach ($cache[self::def] as $name => $value) {
            if (!array_key_exists($name, $_SERVER)) {
                //var_dump("glob: " . $name);
                $cginame = self::getCGIname($name);
                if (array_key_exists($cginame, $_SERVER)) {
                    //есть пхп-вая переменная с "похожим" CGI-наименованием - считаем что "оно"
                    $_SERVER[$name] = $_SERVER[$cginame];
                } else {
                    //дефолты берем из кеша только если нет в окружении
                    $envvalue = getenv($name);
                    if ($envvalue != false) {
                        $_SERVER[$name] = $envvalue;
                    } else {
                        $_SERVER[$name] = $value;
                    }
                }
            }
        }
        foreach ($cache[self::kill] as $name => $value) {
            //зачистка того что должно быть зачищено
            self::unsetValue($name);
        }
    }

    /**
     * привести наименование переменной окружения по правилам CGI
     *
     * @param string $name оригинальное наименование
     * @return string CGi-наименование переменной
     */
    private static function getCGIname($name) {
        return str_replace(array('-', '.'), '_', $name);
    }

    /**
     * удалить значение из _SERVER
     *
     * @param string $name ключ
     */
    private static function unsetValue($name) {
        if (array_key_exists($name, $_SERVER)) {
            unset($_SERVER[$name]);
        }
        if (array_key_exists("REDIRECT_" . $name, $_SERVER)) {
            unset($_SERVER["REDIRECT_" . $name]);
        }
    }

}
