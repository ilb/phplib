<?php

/**
 * @version $Id: Config.php 638 2020-09-10 09:09:18Z dab $
 */

/**
 * Конфигурация бд
 */
class DB_Config {

    /**
     * схема
     *
     * @var string
     */
    public $scheme = NULL;

    /**
     * хост базы
     *
     * @var string
     */
    public $host = NULL;

    /**
     * порт сервера БД
     *
     * @var string
     */
    public $port = NULL;

    /**
     * имя базы
     *
     * @var string
     */
    public $base = NULL;

    /**
     * имя пользователя
     *
     * @var string
     */
    public $user = NULL;

    /**
     * пароль пользователя
     *
     * @var string
     * @xmlio in
     */
    public $pass = NULL;

    /**
     * роль пользователя (firebird)
     *
     * @var string
     */
    public $role = NULL;

    /**
     * диалект (firebird)
     *
     * @var string
     */
    public $dialect = NULL;

    /**
     * Кодировка подключения (актуально для firebird)
     * @var string
     */
    public $charset;

    /**
     *
     * @param string $host хост базы
     * @param string $base имя база
     * @param string $user имя пользователя
     * @param string $pass пароль пользователя
     * @param string $role роль пользователя
     * @param string $port порт сервера БД
     * @param string $scheme схема
     */
    public function __construct($host = NULL, $base = NULL, $user = NULL, $pass = NULL, $role = NULL, $port = NULL, $scheme = NULL, $charset = "UTF-8") {
        $this->host = $host;
        $this->base = $base;
        $this->user = $user;
        $this->pass = $pass;
        $this->role = $role;
        $this->port = $port;
        $this->scheme = $scheme;
        $this->charset = $charset;
    }

    public function getScheme() {
        return $this->scheme;
    }

    public function getHost() {
        return $this->host;
    }

    public function getPort() {
        return $this->port;
    }

    public function getBase() {
        return $this->base;
    }

    public function getUser() {
        return $this->user;
    }

    public function getPass() {
        return $this->pass;
    }

    public function getRole() {
        return $this->role;
    }

    public function getDialect() {
        return $this->dialect;
    }

    /**
     *
     * @param string $scheme
     * @return DB_Config
     */
    public function setScheme($scheme) {
        $this->scheme = $scheme;
        return $this;
    }

    /**
     *
     * @param string $host
     * @return DB_Config
     */
    public function setHost($host) {
        $this->host = $host;
        return $this;
    }

    /**
     *
     * @param string $port
     * @return DB_Config
     */
    public function setPort($port) {
        $this->port = $port;
        return $this;
    }

    /**
     *
     * @param string $base
     * @return DB_Config
     */
    public function setBase($base) {
        $this->base = $base;
        return $this;
    }

    /**
     *
     * @param string $user
     * @return DB_Config
     */
    public function setUser($user) {
        $this->user = $user;
        return $this;
    }

    /**
     *
     * @param string $pass
     * @return DB_Config
     */
    public function setPass($pass) {
        $this->pass = $pass;
        return $this;
    }

    /**
     *
     * @param string $role
     * @return DB_Config
     */
    public function setRole($role) {
        $this->role = $role;
        return $this;
    }

    /**
     *
     * @param string $dialect
     * @return DB_Config
     */
    public function setDialect($dialect) {
        $this->dialect = $dialect;
        return $this;
    }

    public function getCharset() {
        return $this->charset;
    }

    /**
     *
     * @param string $charset
     * @return DB_Config
     */
    public function setCharset($charset) {
        $this->charset = $charset;
        return $this;
    }

    /**
     * Заполнить поля коннекта из строки подключение
     * @param string $connectionString
     * @return DB_Config
     */
    public function fromConnectionString($connectionString) {
        $parts = parse_url($connectionString);
        if ($parts === FALSE) {
            trigger_error("invalid " . $connectionString, E_USER_ERROR);
        }
        if (isset($parts["scheme"])) {
            $this->scheme = $parts["scheme"];
            switch ($this->scheme) {
                case "firebirdsql":
                case "firebird":
                    $this->port = 3050;
                    break;
                case "mysql":
                    $this->port = 3306;
                    break;
                case "mssql":
                    $this->port = 1433;
                    break;
                default:
                    trigger_error("Unsupported database scheme: '" . $this->scheme . "'", E_USER_ERROR);
            }
        }
        if (isset($parts["host"])) {
            $this->host = $parts["host"];
        }
        if (isset($parts["port"])) {
            $this->port = $parts["port"];
        }
        if (isset($parts["path"])) {
            $this->base = basename($parts["path"]);
        }
        if (isset($parts["user"])) {
            $this->user = $parts["user"];
        }
        if (isset($parts["pass"])) {
            $this->pass = $parts["pass"];
        }
        if (isset($parts["query"])) {
            $output = array();
            parse_str($parts["query"], $output);
            if (isset($output["roleName"])) {
                $this->role = $output["roleName"];
            }
        }
        return $this;
    }

    /**
     *
     * @return string
     */
    public function toDBConnectString() {
        $result = NULL;
        switch ($this->scheme) {
            case "firebirdsql":
            case "firebird":
                $result = $this->host . ($this->port ? "/$this->port" : "") . ":" . $this->base;
                break;
            case "mysql":
                $result = $this->host . ($this->port ? ":$this->port" : "");
                break;
            case "mssql":
                $result = $this->host . ($this->port ? ":$this->port" : "");
                break;
            default:
                trigger_error("Unsupported database scheme: '" . $this->scheme . "'", E_USER_ERROR);
        }
        return $result;
    }

    /**
     * Заполнить поля коннекта из строки подключение
     * @param string $connectionString
     * @return DB_Config
     */
    public static function constructFromConnectionString($connectionString) {
        $dbconf = new DB_Config();
        $dbconf->fromConnectionString($connectionString);
        return $dbconf;
    }

    /**
     * Получить строку коннекта к PDO
     * @return string
     */
    public function toPDOConnectString() {
        $result = NULL;
        switch ($this->scheme) {
            case "firebirdsql":
            case "firebird":
                $result = "firebird:dbname=" . $this->toDBConnectString() . ($this->role ? ";role=$this->role" : "") . ($this->charset ? ";charset=$this->charset" : "");
                break;
            case "mysql":
                $result = "mysql:host=" . $this->host . ";dbname=" . $this->base . ($this->port ? ";port=$this->port" : "");
                break;
            case "mssql":
                $result = "dblib:host=" . $this->host . ":" . $this->port . ";dbname=" . $this->base . ($this->dialect ? ";version=$this->dialect" : "") . ($this->charset ? ";charset=$this->charset" : "");
                break;
            default:
                trigger_error("Unsupported database scheme: '" . $this->scheme . "'", E_USER_ERROR);
        }
        return $result;
    }

    /**
     * @return DB_PDO объект
     */
    public function toPDO($options = NULL) {
        $PDOFactory = DB_PDOFactory::getInstance();
        $pdo = $PDOFactory->getPDO($this, $options);
        return $pdo;
    }

    public function getHash() {
        return sha1(serialize($this));
    }

}
