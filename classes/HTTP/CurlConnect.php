<?php

/*
  кэширующий http-клиент на основе curl

  кэширование на основе заголовков if-modified-since

  запросы повтовно не выполняются по одному и тому урлу в течение жизни объекта

  метод clearCache() после явного сброса кэша выполняет
  задержку в 1 сек чтоб избежать повторного попадания в кэш по времени модификации

  ограниченная поддержка кукис:
  сохраняется только значение ассоциированное с конкретным путем урла
  куки разделены для разных авторизационных данных

 */

class HTTP_CurlConnect {

    // по умолчанию данные в кэше актуальны на 0 секунд
    protected $ttl = 0;
    protected $debug = 0;
    protected $ch = null;
    //http://grokbase.com/t/php/php-bugs/13bjbwhbyh/php-bug-bug-66109-new-option-curlopt-customrequest-cant-be-reset-to-default
    protected $ch_cr = null;
    protected $ch_put = null;
    protected $ch_last = null;
    protected $savePath = false;
    protected $key = false;
    protected $cookiecontext;
    private $fp;
    private $filename;
    private $filenametmp;
    private $filenamecookie;
    private $lastmod;
    private $putResult;
    private $history = array();
    private $headers;
    private $timeout = 31;
    /* TODO убрать в $curlConfig */
    private $USER_CERT;
    private $USER_CERT_PASS;
    private $CAINFO;

    /**
     * конифгурация CURL * TODO брать настройки сертификата, таймаута отсюда
     * @var Curl_Config
     */
    private $curlConfig;

    public function __construct($key, $cachepath, $curlConfig = NULL, $cookiecontext = NULL, $timeout = NULL) {
        //для отладки можно выключить кэш
        if (is_object($curlConfig)) {
            $this->USER_CERT = $curlConfig->sslcert;
            $this->USER_CERT_PASS = $curlConfig->sslcertpass;
            $this->CAINFO = $curlConfig->cainfo;
            $this->curlConfig = $curlConfig;
            if ($curlConfig->timeout) {
                $this->timeout = $curlConfig->timeout;
            }
        } elseif (is_array($curlConfig)) {
            $this->USER_CERT = $curlConfig["USER_CERT"];
            $this->USER_CERT_PASS = $curlConfig["USER_CERT_PASS"];
            $this->CAINFO = $curlConfig["CAINFO"];
        } elseif (defined('__CURL_USER_CERT__')) {
            //TODO убрать этот блок вообще
            $this->USER_CERT = __CURL_USER_CERT__;
            $this->USER_CERT_PASS = __CURL_USER_CERT_PASS__;
            $this->CAINFO = __CURL_CAINFO__;
        }
        $this->key = $key;
        $this->cookiecontext = $cookiecontext;
        $this->savePath = $cachepath;
        if ($timeout) {
            $this->timeout = $timeout;
        }
        $this->connect();
    }

    public function connect() {

        $this->ch = curl_init();
        curl_setopt($this->ch(), CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($this->ch, CURLOPT_HEADER, 0);
//        curl_setopt( $this->ch, CURLOPT_SSL_VERIFYPEER, 0 );
        curl_setopt($this->ch, CURLOPT_TIMECONDITION, CURLOPT_TIMECONDITION);
        curl_setopt($this->ch, CURLOPT_VERBOSE, $this->debug);
        curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, 1);
        //if (!strncmp($this->key, "https", 5)) {
        if ($this->USER_CERT && $this->USER_CERT_PASS) {
            //curl_setopt( $ch, CURLOPT_CAINFO, $this->BASE."/certs/ourCAbundle.crt" );
            curl_setopt($this->ch, CURLOPT_CAINFO, $this->CAINFO);
            curl_setopt($this->ch, CURLOPT_SSLCERT, $this->USER_CERT);
            curl_setopt($this->ch, CURLOPT_SSLCERTPASSWD, $this->USER_CERT_PASS);
        } elseif (defined('__CURL_HTTP_USER__') && defined('__CURL_HTTP_PASS__')) {
            //защита от передачи пароля незащищенным каналом
            curl_setopt($this->ch, CURLOPT_USERPWD, __CURL_HTTP_USER__ . ":" . __CURL_HTTP_PASS__);
        }
        //}
        if ($this->curlConfig !== NULL) {
            if (isset($this->curlConfig->proxy)) { //isset на всякий случай, в некоторых проектах до сих пор свой Curl_Config и в нем нет proxy
                curl_setopt($this->ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
                curl_setopt($this->ch, CURLOPT_PROXYAUTH, CURLAUTH_ANY);
                curl_setopt($this->ch, CURLOPT_PROXY, $this->curlConfig->proxy);
            }
            if (isset($this->curlConfig->userpwd)) { //isset на всякий случай, в некоторых проектах до сих пор свой Curl_Config и в нем нет userpwd
                curl_setopt($this->ch, CURLOPT_USERPWD, $this->curlConfig->userpwd);
            }
            if (isset($this->curlConfig->verifypeer) && $this->curlConfig->verifypeer !== NULL) {
                curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, $this->curlConfig->verifypeer ? 1 : 0);
            }
        }
        // Дескрипторы создаются сразу, а не по необходимости. После каких-то установок $this->ch, curl_copy_handle выдает "Segmentation fault".
        $this->ch_cr = curl_copy_handle($this->ch);
        // Отдельный дескриптор для doPut, CURLOPT_INFILE не получается вернуть в состояние по умолчанию.
        $this->ch_put = curl_copy_handle($this->ch);
    }

    private function ch_put() {
        $this->ch_last = $this->ch_put;
        return $this->ch_put;
    }

    private function ch_cr() {
        $this->ch_last = $this->ch_cr;
        return $this->ch_cr;
    }

    private function ch() {
        $this->ch_last = $this->ch;
        return $this->ch;
    }

    private function getFromCache($url, $nocache = false) {
        //TODO проверить url на соответствие key?
        $this->filename = $this->savePath . "/" . $this->key . "-" . md5($url);
        $this->filenametmp = $this->filename . ".tmp" . uniqid("", TRUE); // $_SERVER["UNIQUE_ID"] конечно лучше, но он недоступен без апача
        $this->lastmod = 0;
        if (!$nocache && file_exists($this->filename)) {
            $this->lastmod = filemtime($this->filename);
//error_log("lastmod=".date("c",$this->lastmod)." +ttl=".date("c",$this->lastmod+$this->ttl)." time=".date("c",time()));
            if ($this->lastmod + $this->ttl >= time()) { // cache TTL
                $res = file_get_contents($this->filename);
                if ($res == FALSE) {
                    throw new Exception("file_get_contents failed " . $this->filename);
                }
                return $res;
            }
        }
        $mask = umask(0177);
        $this->fp = fopen($this->filenametmp, "w");
        umask($mask);
        if (!$this->fp) {
            throw new Exception("fopen failed " . $this->filenametmp);
        }
        return false;
    }

    private function putToCache($url, $code, $nocache = false, $resultFile = NULL) {
//        fclose( $this->fp );
        $res = NULL;
        if ($code == 200) {
            $lastmod = curl_getinfo($this->ch, CURLINFO_FILETIME);
            //раньше всегда ложилось в кэш, теперь ложим только если пришла дата модификации с сервера
            //rename( $this->filenametmp, $this->filename );
            if ($resultFile) {
                copy($this->filenametmp, $resultFile);
                $res = TRUE;
            } else {
                $res = file_get_contents($this->filenametmp);
            }
            if ($lastmod != -1) {
                // будем ложить если есть дата модфикации
                rename($this->filenametmp, $this->filename);
                touch($this->filename, $lastmod);
            } else {
                unlink($this->filenametmp);
            }
        } else {
            unlink($this->filenametmp); //удалить пустой временный файл
            //TODO разделить время последней модификации от времени последнего доступа
            //touch( $this->filename ); //обновить время последнего доступа
            touch($this->filename, filemtime($this->filename), time());
            $res = file_get_contents($this->filename);
        }
        //убрал history
        $this->history[$url] = $this->filename; //запомнимаем где мы были
        return $res;
    }

    public function doGet($url, $customheaders = false, $nocache = false, &$httpheaders = NULL, $customCookies = NULL, $resultFile = NULL) {
        //$uid = Logger::entering("CurlConnect","doGet",$url,1);
        $res = $this->getFromCache($url, $nocache);
        $header = array("Accept-charset: UTF-8");
        if ($customheaders) {
            if (!is_array($customheaders))
                $customheaders = array($customheaders);
            $header = array_merge($header, $customheaders);
            //$header[]=$customheaders;
        }
        if (!$res) {
            curl_setopt($this->ch(), CURLOPT_URL, $url);
            curl_setopt($this->ch, CURLOPT_FILE, $this->fp);
            curl_setopt($this->ch, CURLOPT_TIMEVALUE, $this->lastmod);
            curl_setopt($this->ch, CURLOPT_POST, 0);
            curl_setopt($this->ch, CURLOPT_PUT, 0);
            curl_setopt($this->ch, CURLOPT_NOBODY, 0);
            // для того чтобы потом получить время модификации с помощью CURLINFO_FILETIME
            curl_setopt($this->ch, CURLOPT_FILETIME, 1);
            curl_setopt($this->ch, CURLOPT_HTTPHEADER, $header);

            $this->setCookies($url, $this->ch);
            if ($customCookies) {
                if (is_array($customCookies)) {
                    $cookiesStr = '';
                    foreach ($customCookies as $cookieName => $cookieValue) {
                        $cookiesStr .= ';'.$cookieName.'='.$cookieValue;
                    }
                    curl_setopt($this->ch, CURLOPT_COOKIE, substr($cookiesStr, 1));
                } else {
                    curl_setopt($this->ch, CURLOPT_COOKIE, $customCookies);
                }
            }
            $headersfile = tmpfile();
            //$headersfile=fopen($this->filename.".headers","w+");
            curl_setopt($this->ch, CURLOPT_WRITEHEADER, $headersfile);

            curl_exec($this->ch);
            $httpheaders = $this->saveHeaders($headersfile);
            $code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
            //fclose( $this->fp );
            //trigger_error($code);
            while ($code == 202) {
                rewind($this->fp);
                ftruncate($this->fp, 0);
                $refresh = $this->getRefreshData($url);
                if (!empty($refresh)) {
                    sleep($refresh[0]);

                    //trigger_error(" ".print_r($matches,true));
                    //return $this->doHead($matches[1], $customheaders);
                    $headersfile = tmpfile();
                    //$headersfile=fopen($this->filename.".headers","w+");
                    curl_setopt($this->ch, CURLOPT_WRITEHEADER, $headersfile);
                    curl_setopt($this->ch, CURLOPT_URL, $refresh[1]);
                    $this->setCookies($refresh[1], $this->ch);

                    curl_exec($this->ch);
                    $httpheaders = $this->saveHeaders($headersfile);
                    $code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
                } else {
                    $res = file_get_contents($this->filenametmp);
                    fclose($this->fp);
                    unlink($this->filenametmp);
                    throw new HTTP_Exception(" Нет данных для дальнейших действий." . $url . " " . curl_error($this->ch) . (curl_getinfo($this->ch, CURLINFO_SIZE_DOWNLOAD) < 1 ? $httpheaders : "") . $res, $code + curl_errno($this->ch));
                }
            }
            if ($code == 200 || $code == 304) {
                fclose($this->fp);
                $res = $this->putToCache($url, $code, $nocache, $resultFile);
                // cache отдает ошибку и не меняет код, временная мера
                $contentType = curl_getinfo($this->ch, CURLINFO_CONTENT_TYPE);
                if ((!$resultFile) && (strpos($url, "/csp/") !== FALSE) && (strpos($contentType, "/xml") !== FALSE)) {
                    self::checkXml($res);
                }
            } else { //ошибка?
                //оставить времнный файл для разборок
                //Logger::exiting($uid,"exit by HttpException");
                //сообщение слеплено из: ошибки курла если есть, заголовков если не было тела ответа, самого ответа
                //код устанавливается оибо курловой ошибки если была либо код хттп-ответа
                // cache не умеет возвращать нестандартные ошибки
                if (strpos($url, "/csp/") !== FALSE) {
                    $code += 50;
                }
                $res = file_get_contents($this->filenametmp);
                //временная затычка удаляется не закрытый файл на windows падает
                fclose($this->fp);
                @unlink($this->filenametmp);
                throw new HTTP_Exception($res . PHP_EOL . $url . " " . curl_error($this->ch) . (curl_getinfo($this->ch, CURLINFO_SIZE_DOWNLOAD) < 1 ? $httpheaders : ""), curl_errno($this->ch) ? curl_errno($this->ch) : $code);
            }
        }
        //Logger::exiting($uid);
        return $res;
    }

    public function doHead($url, $customheaders = false) {
        //$uid = Logger::entering("CurlConnect","doGet",$url,1);
        $nocache = TRUE;
        $res = $this->getFromCache($url, $nocache);
        $header = array("Accept-charset: UTF-8");
        if ($customheaders) {
            if (!is_array($customheaders)) {
                $customheaders = array($customheaders);
            }
            $header = array_merge($header, $customheaders);
            //$header[]=$customheaders;
        }
        if (!$res) {
            curl_setopt($this->ch(), CURLOPT_URL, $url);
            curl_setopt($this->ch, CURLOPT_FILE, $this->fp);
            curl_setopt($this->ch, CURLOPT_TIMEVALUE, $this->lastmod);
            curl_setopt($this->ch, CURLOPT_POST, 0);
            curl_setopt($this->ch, CURLOPT_PUT, 0);
            curl_setopt($this->ch, CURLOPT_NOBODY, 1);
            curl_setopt($this->ch, CURLOPT_HTTPHEADER, $header);
            $this->setCookies($url, $this->ch);
            $headersfile = tmpfile();
            //$headersfile=fopen($this->filename.".headers","w+");
            curl_setopt($this->ch, CURLOPT_WRITEHEADER, $headersfile);

            curl_exec($this->ch);
            $httpheaders = $this->saveHeaders($headersfile);
            $code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
            fclose($this->fp);
            unlink($this->filenametmp);
            $res = "";
            if ($code == 200 || $code == 304) {
                //$res = $this->putToCache( $url , $nocache);
                //$this->saveCookies( $url, $httpheaders );
            } elseif ($code == 202) {
                $refresh = $this->getRefreshData($url);
                if (!empty($refresh)) {
                    sleep($refresh[0]);

                    //trigger_error(" ".print_r($matches,true));
                    return $this->doGet($refresh[1], $customheaders);
                } else {
                    throw new HTTP_Exception(" Нет данных для дальнейших действий." . $url . " " . curl_error($this->ch) . (curl_getinfo($this->ch, CURLINFO_SIZE_DOWNLOAD) < 1 ? $httpheaders : ""), $code + curl_errno($this->ch));
                }
            } else { //ошибка?
                //оставить времнный файл для разборок
                //Logger::exiting($uid,"exit by HttpException");
                //сообщение слеплено из: ошибки курла если есть, заголовков если не было тела ответа, самого ответа
                //код устанавливается оибо курловой ошибки если была либо код хттп-ответа
                throw new HTTP_Exception($url . " " . curl_error($this->ch) . (curl_getinfo($this->ch, CURLINFO_SIZE_DOWNLOAD) < 1 ? $httpheaders : ""), curl_errno($this->ch) ? curl_errno($this->ch) : $code);
            }
        }
        //Logger::exiting($uid);
        return $res;
    }

    public function doPost($url, $data, $type = false, $customheaders = false, $resultFile = NULL, &$httpheaders = NULL) {
        //$uid = Logger::entering("CurlConnect","doPost",$url,1);
        //пост кэшировать нельзя - в кэше просто размещаем временные файлы
        $digest = microtime(true);
        //этот запрос в кэш всегда промахивается - он нужен чтоб корректно открыть временные файлы и пр.
        $res = $this->getFromCache($url . "___" . $digest);
        if (!$res) {
            $header = array("Accept-charset: UTF-8");
            if ($type) {
                $header[] = "Content-Type: " . $type;
            }
            if ($customheaders) {
                if (!is_array($customheaders)) {
                    $customheaders = array($customheaders);
                }
                $header = array_merge($header, $customheaders);
            }
            curl_setopt($this->ch(), CURLOPT_URL, $url);
            curl_setopt($this->ch, CURLOPT_FILE, $this->fp);
            curl_setopt($this->ch, CURLOPT_TIMEVALUE, $this->lastmod); // не кэшировать
            curl_setopt($this->ch, CURLOPT_POST, 1);
            curl_setopt($this->ch, CURLOPT_PUT, 0);
            curl_setopt($this->ch, CURLOPT_NOBODY, 0);
            curl_setopt($this->ch, CURLOPT_SAFE_UPLOAD, true); //отключаем явно "@" в значениях
            $datafp = NULL;
            //работу с "@" повторяем сами как это работало в старых версиях php
            if (is_string($data) && substr($data, 0, 1) == "@") {
                curl_setopt($this->ch, CURLOPT_PUT, 1); //WTF? doPost? PUT?
                $file = substr($data, 1);
                $datafp = fopen($file, "r");
                curl_setopt($this->ch, CURLOPT_INFILE, $datafp);
                curl_setopt($this->ch, CURLOPT_INFILESIZE, filesize($file));
            } else {
                if (is_array($data)) {
                    foreach ($data as $key => $val) {
                        if (is_string($val) && substr($val, 0, 1) == "@") {
                            $data[$key] = new CurlFile(substr($val, 1));
                        }
                    }
                }
                curl_setopt($this->ch, CURLOPT_POSTFIELDS, $data);
            }
            curl_setopt($this->ch, CURLOPT_HTTPHEADER, $header);
            $this->setCookies($url, $this->ch);
            $headersfile = tmpfile();
            curl_setopt($this->ch, CURLOPT_WRITEHEADER, $headersfile);

            curl_exec($this->ch);
            $httpheaders = $this->saveHeaders($headersfile);
            $code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
            fclose($this->fp);
            if ($datafp) {
                fclose($datafp);
            }
            $res = NULL;
            //для ошибочных кодов всегда читаем ответ в $res
            if (!$resultFile || ($code != 200 && $code != 304 && $code != 202)) {
                $res = file_get_contents($this->filenametmp);
            }
            if ($resultFile) {
                rename($this->filenametmp, $resultFile);
            } else {
                unlink($this->filenametmp);
            }


            if ($code == 200 || $code == 304 || $code == 204) {
                // cache отдает ошибку и не меняет код, временная мера
                $contentType = curl_getinfo($this->ch, CURLINFO_CONTENT_TYPE);
                if ((!$resultFile) && (strpos($url, "/csp/") !== FALSE) && (strpos($contentType, "/xml") !== FALSE)) {
                    self::checkXml($res);
                }
            } elseif ($code == 202) {
                $refresh = $this->getRefreshData($url);
                if (!empty($refresh)) {
                    sleep($refresh[0]);

                    //trigger_error(" ".print_r($matches,true));
                    return $this->doGet($refresh[1], $customheaders);
                } else {
                    throw new HTTP_Exception(" Нет данных для дальнейших действий." . $url . " " . curl_error($this->ch) . (curl_getinfo($this->ch, CURLINFO_SIZE_DOWNLOAD) < 1 ? $httpheaders : "") . $res, $code + curl_errno($this->ch));
                }
            } else { //ошибка?
                //оставить времнный файл для разборок
                //Logger::exiting($uid,"exit by HttpException");
                // cache не умеет возвращать нестандартные ошибки
                if (strpos($url, "/csp/") !== FALSE) {
                    $code += 50;
                }
                throw new HTTP_Exception($res . PHP_EOL . $url . " " . curl_error($this->ch) . (curl_getinfo($this->ch, CURLINFO_SIZE_DOWNLOAD) < 1 ? $httpheaders : ""), curl_errno($this->ch) ? curl_errno($this->ch) : $code);
            }
        }
        //Logger::exiting($uid);
        return $res;
    }

    public static function checkXml($res) {
        try {
            $xr = new XMLReader();
            $xr->XML($res);
            while ($xr->read());
        } catch (Exception $e) {
            Logger_Tools::NotifyException($e, $res);
            throw new Exception($res . "\n" . $e->getMessage(), 450);
        }
    }

    public function doPostXml($url, $data, $customheaders = false, $schemaLocation = NULL, $resultFile = NULL) {
        if ($schemaLocation) {
            $requestDOM = new DOMDocument();
            $requestDOM->preserveWhiteSpace = FALSE;
            $requestDOM->loadXML($data);
            $requestDOM->schemaValidate($schemaLocation);
        }
        return $this->doPost($url, $data, "application/xml", $customheaders, $resultFile);
    }

    public function doPut($url, $data, $customheaders = false, $infile = NULL, $resultFile = NULL) {
        return $this->doCustomRequest("PUT", $url, $data, $customheaders, $infile, $resultFile);
    }

    /**
     * Отправка XML-я PUT-ом (header: application/xml)
     * @param $url URL ресурса
     * @param $data XML
     * @param bool $customheaders дополнительные HTTP заголовки
     * @param null $schemaLocation путь до XSD схемы, для проверки XML перед отправкой
     * @param null $resultFile имя файла, куда свалить ответ
     * @return bool|null|string
     */
    public function doPutXML($url, $data, $customheaders = false, $schemaLocation = NULL, $resultFile = NULL) {
        if ($schemaLocation) {
            $requestDOM = new DOMDocument();
            $requestDOM->preserveWhiteSpace = FALSE;
            $requestDOM->loadXML($data);
            $requestDOM->schemaValidate($schemaLocation);
        }
        $headers = array("Content-Type: application/xml");
        if ($customheaders) {
            if (!is_array($customheaders)) {
                $customheaders = array($customheaders);
            }
            $headers = array_merge($headers, $customheaders);
        }
        return $this->doCustomRequest("PUT", $url, $data, $headers, null, $resultFile);
    }

    public function doPatch($url, $data, $customheaders = false, $infile = NULL, $resultFile = NULL) {
        return $this->doCustomRequest("PATCH", $url, $data, $customheaders, $infile, $resultFile);
    }

    public function doCustomRequest($method, $url, $data, $customheaders = false, $infile = NULL, $resultFile = NULL) {
        //$uid = Logger::entering("CurlConnect","doPost",$url,1);
        //пут кэшировать нельзя - в кэше просто размещаем временные файлы
        $digest = microtime(true);
        //этот запрос в кэш всегда промахивается - он нужен чтоб корректно открыть временные файлы и пр.
        $res = $this->getFromCache($url . "___" . $digest);
        if (!$res) {
            $tmp = $this->savePath . "/" . microtime(true);
            if ($infile === NULL) {
                file_put_contents($tmp, $data);
            } else {
                rename($infile, $tmp);
            }

            $header = array("Content-Length: " . filesize($tmp), "Transfer-Encoding:");
            if ($customheaders) {
                if (!is_array($customheaders)) {
                    $customheaders = array($customheaders);
                }
                $header = array_merge($header, $customheaders);
                //$header[]=$customheaders;
            }

            curl_setopt($this->ch_put(), CURLOPT_URL, $url);
            curl_setopt($this->ch_put, CURLOPT_TIMEVALUE, 0);
            curl_setopt($this->ch_put, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($this->ch_put, CURLOPT_POST, 0);
            curl_setopt($this->ch_put, CURLOPT_PUT, 1);
            curl_setopt($this->ch_put, CURLOPT_NOBODY, 0);
            curl_setopt($this->ch_put, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($this->ch_put, CURLOPT_INFILESIZE, filesize($tmp));
            $inFileHandle = fopen($tmp, "rb");
            if (!$inFileHandle) {
                throw new Exception("fopen failed " . $tmp);
            }
            curl_setopt($this->ch_put, CURLOPT_INFILE, $inFileHandle);
            curl_setopt($this->ch_put, CURLOPT_HTTPHEADER, $header);
            $this->setCookies($url, $this->ch_put);
            $headersfile = tmpfile();
            curl_setopt($this->ch_put, CURLOPT_WRITEHEADER, $headersfile);
            curl_setopt($this->ch_put, CURLOPT_FILE, $this->fp);

            $this->putResult = $result = curl_exec($this->ch_put);

            curl_setopt($this->ch_put, CURLOPT_RETURNTRANSFER, 0);
            $httpheaders = $this->saveHeaders($headersfile);
            fclose($inFileHandle);
            $code = curl_getinfo($this->ch_put, CURLINFO_HTTP_CODE);
            fclose($this->fp);
            /* if ($code == 201 || $code == 200) {
              //nop
              } else { //ошибка?
              //оставить времнный файл для разборок
              //Logger::exiting($uid,"exit by HttpException");
              //TODO тут надо аналогично get-post выводить тело ответа сервера
              throw new HTTP_Exception($url . " " . curl_error($this->ch_put) . (curl_getinfo($this->ch_put, CURLINFO_SIZE_DOWNLOAD) < 1 ? $httpheaders : "") . $result, $code + curl_errno($this->ch_put));
              }
              if (!unlink($tmp)) {
              throw new Exception("unlink failed " . $tmp);
              }
              return $code; */
            $res = NULL;
            //для ошибочных кодов всегда читаем ответ в $res
            if (!$resultFile || ($code != 200 && $code != 304 && $code != 202 && $code != 201 && $code != 204)) {
                $res = file_get_contents($this->filenametmp);
            }
            if ($resultFile) {
                rename($this->filenametmp, $resultFile);
            } else {
                unlink($this->filenametmp);
            }


            if ($code == 200 || $code == 304 || $code == 201 || $code == 204) {
                // cache отдает ошибку и не меняет код, временная мера
                $contentType = curl_getinfo($this->ch_put, CURLINFO_CONTENT_TYPE);
                if ((!$resultFile) && (strpos($url, "/csp/") !== FALSE) && (strpos($contentType, "/xml") !== FALSE)) {
                    self::checkXml($res);
                }
            } elseif ($code == 202) {
                $refresh = $this->getRefreshData($url);
                if (!empty($refresh)) {
                    sleep($refresh[0]);

                    //trigger_error(" ".print_r($matches,true));
                    return $this->doGet($refresh[1], $customheaders);
                } else {
                    throw new HTTP_Exception(" Нет данных для дальнейших действий." . $url . " " . curl_error($this->ch_put) . (curl_getinfo($this->ch_put, CURLINFO_SIZE_DOWNLOAD) < 1 ? $httpheaders : "") . $res, $code + curl_errno($this->ch_put));
                }
            } else { //ошибка?
                //оставить времнный файл для разборок
                //Logger::exiting($uid,"exit by HttpException");
                // cache не умеет возвращать нестандартные ошибки
                if (strpos($url, "/csp/") !== FALSE) {
                    $code += 50;
                }
                throw new HTTP_Exception($res . PHP_EOL . $url . " " . curl_error($this->ch_put) . (curl_getinfo($this->ch_put, CURLINFO_SIZE_DOWNLOAD) < 1 ? $httpheaders : ""), curl_errno($this->ch_put) ? curl_errno($this->ch_put) : $code);
            }
        }
        //Logger::exiting($uid);
        return $res;
    }

    public function doDelete($url, $customheaders = false) {
        $header = array();
        $res = $this->getFromCache($url, true);
        if ($customheaders) {
            if (!is_array($customheaders)) {
                $customheaders = array($customheaders);
            }
            $header = array_merge($header, $customheaders);
            //$header[]=$customheaders;
        }

        curl_setopt($this->ch_cr(), CURLOPT_URL, $url);
        curl_setopt($this->ch_cr, CURLOPT_FILE, $this->fp);
        curl_setopt($this->ch_cr, CURLOPT_POST, 0);
        curl_setopt($this->ch_cr, CURLOPT_PUT, 0);
        curl_setopt($this->ch_cr, CURLOPT_NOBODY, 0);
        curl_setopt($this->ch_cr, CURLOPT_TIMEVALUE, 0); // не кэшировать
        curl_setopt($this->ch_cr, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($this->ch_cr, CURLOPT_HTTPHEADER, $header);
        $this->setCookies($url, $this->ch_cr);
        $headersfile = tmpfile();
        curl_setopt($this->ch_cr, CURLOPT_WRITEHEADER, $headersfile);

        curl_exec($this->ch_cr);
        $httpheaders = $this->saveHeaders($headersfile);
        $code = curl_getinfo($this->ch_cr, CURLINFO_HTTP_CODE);


        if ($code == 204) {
            unlink($this->filenametmp);
            //nop
        } else { //ошибка?
            //Logger::exiting($uid,"exit by HttpException");
            //TODO тут надо аналогично get-post выводить тело ответа сервера
            $res = file_get_contents($this->filenametmp);
            unlink($this->filenametmp);
            throw new HTTP_Exception($url . " " . curl_error($this->ch_cr) . (curl_getinfo($this->ch_cr, CURLINFO_SIZE_DOWNLOAD) < 1 ? $httpheaders : "") . $res, $code + curl_errno($this->ch_cr));
        }
        return $code;
    }

    public function doMkcol($url, $customheaders = false) {
        $header = array();
        $res = $this->getFromCache($url, true);
        if ($customheaders) {
            if (!is_array($customheaders)) {
                $customheaders = array($customheaders);
            }
            $header = array_merge($header, $customheaders);
            //$header[]=$customheaders;
        }

        curl_setopt($this->ch_cr(), CURLOPT_URL, $url);
        curl_setopt($this->ch_cr, CURLOPT_FILE, $this->fp);
        curl_setopt($this->ch_cr, CURLOPT_POST, 0);
        curl_setopt($this->ch_cr, CURLOPT_PUT, 0);
        curl_setopt($this->ch_cr, CURLOPT_NOBODY, 0);
        curl_setopt($this->ch_cr, CURLOPT_TIMEVALUE, 0); // не кэшировать
        curl_setopt($this->ch_cr, CURLOPT_CUSTOMREQUEST, "MKCOL");
        curl_setopt($this->ch_cr, CURLOPT_HTTPHEADER, $header);
        $this->setCookies($url, $this->ch_cr);
        $headersfile = tmpfile();
        curl_setopt($this->ch_cr, CURLOPT_WRITEHEADER, $headersfile);

        curl_exec($this->ch_cr);
        $httpheaders = $this->saveHeaders($headersfile);
        $code = curl_getinfo($this->ch_cr, CURLINFO_HTTP_CODE);

        if ($code == 201) {
            unlink($this->filenametmp);
            //nop
        } else { //ошибка?
            //Logger::exiting($uid,"exit by HttpException");
            //TODO тут надо аналогично get-post выводить тело ответа сервера
            $res = file_get_contents($this->filenametmp);
            unlink($this->filenametmp);
            throw new HTTP_Exception($url . " " . curl_error($this->ch_cr) . (curl_getinfo($this->ch_cr, CURLINFO_SIZE_DOWNLOAD) < 1 ? $httpheaders : "") . $res, $code + curl_errno($this->ch_cr));
        }
        return $code;
    }

    public function doCopy($url, $dest, $customheaders = false) {
        $header = array('Destination:' . $dest);
        $res = $this->getFromCache($url, true);
        if ($customheaders) {
            if (!is_array($customheaders)) {
                $customheaders = array($customheaders);
            }
            $header = array_merge($header, $customheaders);
        }

        curl_setopt($this->ch_cr(), CURLOPT_URL, $url);
        curl_setopt($this->ch_cr, CURLOPT_FILE, $this->fp);
        curl_setopt($this->ch_cr, CURLOPT_POST, 0);
        curl_setopt($this->ch_cr, CURLOPT_PUT, 0);
        curl_setopt($this->ch_cr, CURLOPT_NOBODY, 0);
        curl_setopt($this->ch_cr, CURLOPT_TIMEVALUE, 0); // не кэшировать
        curl_setopt($this->ch_cr, CURLOPT_CUSTOMREQUEST, "COPY");
        curl_setopt($this->ch_cr, CURLOPT_HTTPHEADER, $header);
        $this->setCookies($url, $this->ch_cr);
        $headersfile = tmpfile();
        curl_setopt($this->ch_cr, CURLOPT_WRITEHEADER, $headersfile);

        curl_exec($this->ch_cr);
        $httpheaders = $this->saveHeaders($headersfile);
        $code = curl_getinfo($this->ch_cr, CURLINFO_HTTP_CODE);

        if ($code == 201 || $code == 204) {
            unlink($this->filenametmp);
        } else { //ошибка?
            $res = file_get_contents($this->filenametmp);
            unlink($this->filenametmp);
            throw new HTTP_Exception($url . " " . curl_error($this->ch_cr) . (curl_getinfo($this->ch_cr, CURLINFO_SIZE_DOWNLOAD) < 1 ? $httpheaders : "") . $res, $code + curl_errno($this->ch_cr));
        }
        return $code;
    }

    public function doPropFind($url, $depth = 1, $customheaders = false) {
        $header = array("Depth: " . $depth);
        $res = $this->getFromCache($url, true);
        if ($customheaders) {
            if (!is_array($customheaders)) {
                $customheaders = array($customheaders);
            }
            $header = array_merge($header, $customheaders);
            //$header[]=$customheaders;
        }

        curl_setopt($this->ch_cr(), CURLOPT_URL, $url);
        curl_setopt($this->ch_cr, CURLOPT_FILE, $this->fp);
        curl_setopt($this->ch_cr, CURLOPT_POST, 0);
        curl_setopt($this->ch_cr, CURLOPT_PUT, 0);
        curl_setopt($this->ch_cr, CURLOPT_NOBODY, 0);
        curl_setopt($this->ch_cr, CURLOPT_TIMEVALUE, 0); // не кэшировать
        curl_setopt($this->ch_cr, CURLOPT_CUSTOMREQUEST, "PROPFIND");
        curl_setopt($this->ch_cr, CURLOPT_HTTPHEADER, $header);
        $this->setCookies($url, $this->ch_cr);
        $headersfile = tmpfile();
        curl_setopt($this->ch_cr, CURLOPT_WRITEHEADER, $headersfile);

        curl_exec($this->ch_cr);
        $httpheaders = $this->saveHeaders($headersfile);
        $code = curl_getinfo($this->ch_cr, CURLINFO_HTTP_CODE);

        if ($code == 207) {
            $res = file_get_contents($this->filenametmp);
            unlink($this->filenametmp);
            //nop
        } else { //ошибка?
            //Logger::exiting($uid,"exit by HttpException");
            //TODO тут надо аналогично get-post выводить тело ответа сервера
            $res = file_get_contents($this->filenametmp);
            unlink($this->filenametmp);
            throw new HTTP_Exception($url . " " . curl_error($this->ch_cr) . (curl_getinfo($this->ch_cr, CURLINFO_SIZE_DOWNLOAD) < 1 ? $httpheaders : "") . $res, $code + curl_errno($this->ch_cr));
        }
        return array('code' => $code, 'res' => $res);
    }

    public function getLocalFilename() {
        return $this->filename;
    }

    public function clearCache() {
        foreach ($this->history as $url => $filename) {
            @unlink($filename);
        }
        $this->history = array();
    }

    public function getCookies($url) {
        //кука ассоциирована с путем а не со всем урлом - собираем для нее свое имя файла
        //TODO проверка на отсылаемых кук на соответсвие пути, expires и пр.
        $p = parse_url($url);
        $scheme = isset($p["scheme"]) ? $p["scheme"] : "";
        $host = isset($p["host"]) ? $p["host"] : "";
        $port = isset($p["port"]) ? $p["port"] : "";
        $user = isset($p["user"]) ? $p["user"] : "";
        $pass = isset($p["pass"]) ? $p["pass"] : "";
        $path = isset($p["path"]) ? $p["path"] : "";
        if ($this->USER_CERT) {
            $user = $this->USER_CERT;
            $pass = $this->USER_CERT_PASS;
        }
        $cookiepath = $scheme . "://" . ($user ? ($user . ":" . $pass . "@") : "") . $host . ($port ? (":" . $port) : "") . "/" . $path;
        $this->filenamecookie = $this->savePath . "/" . $this->key . "-" . $this->cookiecontext . "-" . md5($cookiepath) . ".cookie";
        return is_readable($this->filenamecookie)? file_get_contents($this->filenamecookie):NULL;
    }

    private function setCookies($url, $ch) {
        $cookies = $this->getCookies($url);
        if (!empty($cookies)) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookies);
        }
    }

    private function saveCookies() {
        //сохраняются только значения кук
        //время жизни, видимость и проч. не учитываются
        $cookies = '';
        foreach ($this->getHttpResponseHeaders("/^Set-Cookie:\s*(.+);/i") as $cookie) {
            $cookies = ';' . $cookie[1];
        }
        if (!empty($cookies)) {
            $mask = umask(0177);
            file_put_contents($this->filenamecookie, substr($cookies, 1));
            umask($mask);
        }
    }

    private function getRefreshData($url) {
        $matches = $this->getHttpResponseHeaders("/^Refresh:\s*(\d*)\s*;\s*(.*)/i");
        if (!empty($matches)) {
            return array($matches[0][1] == '' ? 1 : intval($matches[0][1]), self::comlete_relative_url($url, $matches[0][2]));
        }
        return NULL;
    }

    private function saveHeaders($h) {
        fseek($h, 0);
        $httpheaders = stream_get_contents($h);
        fclose($h);
        $this->headers = explode("\r\n", $httpheaders);
        $this->saveCookies();
        return $httpheaders;
    }

    public function getHttpResponseHeaders($regexp = NULL) {
        $headers = array();
        if (is_array($this->headers)) {
            foreach ($this->headers as $header) {
                if (is_string($regexp)) {
                    $matches = NULL;
                    if (preg_match($regexp, $header, $matches)) {
                        $headers[] = $matches;
                    }
                } else {
                    $headers[] = $header;
                }
            }
        }
        return $headers;
    }

    public function getUrl() {
        $url = curl_getinfo($this->ch_last, CURLINFO_EFFECTIVE_URL);
        return $url;
    }

    public function getMimeType() {
        $mimeType = explode(";", curl_getinfo($this->ch_last, CURLINFO_CONTENT_TYPE));
        return $mimeType[0];
    }

    public function getCharset() {
        $charSet = explode(";", curl_getinfo($this->ch_last, CURLINFO_CONTENT_TYPE));
        return array_key_exists(1, $charSet) ? substr($charSet[1], strpos($charSet[1], "=") + 1) : NULL;
    }

    public function getHttpResponseCode() {
        return curl_getinfo($this->ch_last, CURLINFO_HTTP_CODE);
    }

    /**
     * Дозаполенние относительного url (без схемы и хоста, например в заголовке Refresh).
     * Если передан $relativeurl с хостом, то функция ничего не делает.
     * @param string $baseurl базовый запрос (полный url)
     * @param string $relativeurl отсносительный запрос (может быть не полным - без хоста)
     * @return string
     */
    public static function comlete_relative_url($baseurl, $relativeurl) {
        $relative = parse_url($relativeurl);
        if (!isset($relative["host"])) {
            $base = parse_url($baseurl);
            $relative["scheme"] = $base["scheme"];
            $relative["host"] = $base["host"];
            if (isset($base["port"])) {
                $relative["port"] = $base["port"];
            }
            if (isset($base["user"])) {
                $relative["user"] = $base["user"];
            }
            if (isset($base["pass"])) {
                $relative["pass"] = $base["pass"];
            }
            $relativeurl = self::unparse_url($relative);
        }
        return $relativeurl;
    }

    /**
     * Функция, обратная parse_url
     * @param array $parsed_url
     * @return string
     */
    public static function unparse_url($parsed_url) {
        $scheme = isset($parsed_url["scheme"]) ? $parsed_url["scheme"] . "://" : "";
        $host = isset($parsed_url["host"]) ? $parsed_url["host"] : "";
        $port = isset($parsed_url["port"]) ? ":" . $parsed_url["port"] : "";
        $user = isset($parsed_url["user"]) ? $parsed_url["user"] : "";
        $pass = isset($parsed_url["pass"]) ? ":" . $parsed_url["pass"] : "";
        $pass = ($user || $pass) ? "$pass@" : "";
        $path = isset($parsed_url["path"]) ? $parsed_url["path"] : "";
        $query = isset($parsed_url["query"]) ? "?" . $parsed_url["query"] : "";
        $fragment = isset($parsed_url["fragment"]) ? "#" . $parsed_url["fragment"] : "";
        return "$scheme$user$pass$host$port$path$query$fragment";
    }

    public function __destruct() {
        // на всякий случай чистка временного файла
        if ($this->filenametmp !== NULL && file_exists($this->filenametmp)) {
            @unlink($this->filenametmp); //@ - race condition
        }
    }

    public function getPutResult() {
        return $this->putResult;
    }

}
