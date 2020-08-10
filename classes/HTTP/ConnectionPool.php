<?php

class HTTP_ConnectionPool {

    protected static $instance = null;
    protected $connections = array();
    protected $cachecontext;
    protected $cookiecontext;
    public $savePath = null;

    protected function __construct() {
        $this->savePath = sys_get_temp_dir() . "/CurlConnect.php-cache-" . (isset($_SERVER["USER"]) ? $_SERVER["USER"] : getmyuid());
        // контекст кэша запросов по-умолчанию - удаленный пользователь
        // иначе при запросах зависящих от X-Remote-User кэш путается
        $this->cachecontext = isset($_SERVER["REMOTE_USER"]) ? $_SERVER["REMOTE_USER"] : "default";
        // контекст кук по-умолчанию - ид процесса, чтобы куки не путались между процессами
        // актуально для cache
        $this->cookiecontext = getmypid();
        if (!is_dir($this->savePath)) {
            @mkdir($this->savePath, 0700); //@race condition  [ Подробно Ticket#: 2013112810000232 ] *Робот* Создание проводок (ЮЛ и ИП)
        }
    }

    /**
     * @return HTTP_ConnectionPool
     */
    public static function getInstance() {
        if (!HTTP_ConnectionPool::$instance) {
            HTTP_ConnectionPool::$instance = new HTTP_ConnectionPool();
        }
        return HTTP_ConnectionPool::$instance;
    }

    /**
     * @param string $url
     * @param mixed $obj
     * @return HTTP_CurlConnect
     */
    public function getConnect($url, $obj = false, $cachecontext = NULL, $cookiecontext = NULL, $timeout = NULL) {
        $p = parse_url($url);
        $scheme = isset($p["scheme"]) ? $p["scheme"] : "";
        $host = isset($p["host"]) ? $p["host"] : "";
        $port = isset($p["port"]) ? $p["port"] : "";
        $user = isset($p["user"]) ? $p["user"] : "";
        $pass = isset($p["pass"]) ? $p["pass"] : "";
        // если передан объект использовать его идентификационную информацию для изоляции
        if (is_object($obj)) {
            $user = $obj->sslcert;
            $pass = $obj->sslcertpass;
        } elseif (is_array($obj)) {
            $user = $obj["USER_CERT"];
            $pass = $obj["USER_CERT_PASS"];
        }
        if ($cachecontext === NULL) {
            $cachecontext = $this->cachecontext;
        }
        if ($cookiecontext === NULL) {
            $cookiecontext = $this->cookiecontext;
        }
        // упрощаем до ситуации когда один хост = одни авторизационные данные
        $key = $scheme . "-" . md5(($user ? ($user . ":" . $pass . "@") : "") . $host . ($port ? (":" . $port) : "")) . "-" . $cachecontext . ($timeout ? "-" . $timeout : "");
        // key отделяет коннект юзера к конкретному хосту от других для повторного его использования
        if (!isset($this->connections[$key])) {
            $this->connections[$key] = new HTTP_CurlConnect($key, $this->savePath, $obj, $cookiecontext, $timeout);
            //HttpConnectionFactory::create( $key,$this->savePath );
        }
        return $this->connections[$key];
    }

}
