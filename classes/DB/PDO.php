<?php

/*
 * @version $Id: PDO.php 601 2019-04-16 06:55:17Z dab $
 */

/**
 * Обертка над классом PDO для работы с транзакицями с помощью sql-запросов
 * весь public интерфейс должен иметь префикс db, чтобы не путаться с PDO
 */
class DB_PDO extends PDO {
    /**
     * Константы для журналирования
     */

    /**
     * Не журналировать ничего
     */
    const DBLOG_NONE = 0;

    /**
     * Журналировать ошибки выполнения запросов
     */
    const DBLOG_ERROR = 2;

    /**
     * Журналировать текст запросов
     */
    const DBLOG_QUERY = 4;

    /**
     * Журналировать текст запросов и ошибки выполнения
     */
    const DBLOG_QUERYERROR = 6;

    /**
     * Журналировать запрос с подставленными параметрами
     */
    const DBLOG_INTERPOLATE = 8;

    /**
     * Журналировать текст запросов и ошибки выполнения
     */
    const DBLOG_QUERYERROR_INTERPOLATE = 14;

    /**
     * Журналировать очистку PDOStatement
     */
    const DBLOG_STATEMENT_DESCTRUCT = 16;

    /**
     * Использовать механизм управление транзакциями с помощью sql-запросов (TRUE) / с помощью PDO ([FALSE])
     * @var boolean
     */
    private $useQueryTransaction = FALSE;

    /**
     * Sql-запрос для запуска транзакции
     * @var string
     */
    private $queryTStart;

    /**
     * Sql-запрос для завершения транзакции
     * @var string
     */
    private $queryTCommit;

    /**
     * Sql-запрос для отката транзакции
     * @var string
     */
    private $queryTRollback;

    /**
     * Sql-запрос для запуска "читающей" транзакции
     * @var string
     */
    private $queryTStartRO;

    /**
     * Запускать "читающие" транзакции
     * @var boolean
     */
    private $readOnly = FALSE;

    /**
     * Id для журналирования. если понадобиться еще где то можно переназвать $id и инициализировать в конструкторе
     * @var type
     */
    private $logId;

    /**
     * Файл журнала запросов
     * @var type
     */
    private $logFile;

    /**
     * Уровень журналирования (см. константы DBLOG_XXX)
     * @var type
     */
    private $logLevel;
    private $stripCrlLf;

    /**
     * Кэшированные препаренные запросы
     * @var type
     */
    private $cachedQueries = array();
    private $exceptionMap = array();

    /**
     * для вложенных транзакций
     */
    private $transactionCounter = 0;
    private $transactionCommit;

    /*
     * Здесь был конструктор и колдовство с register_shutdown_function(array($this, '__destruct'));
     * Убрал, т.к. коннекты не освобождались до завершения работы процесса (оставались ссылки).
     * На mysql приводит к SQLSTATE[08004] [1040] Too many connections.
     * Вообще, сервер БД должен сам откатить транзакцию при разрыве коннекта, так что
     * ничего плохого не должно случиться.
     */

    /**
     *
     * @return type
     */
    public function inTransaction() {
        return $this->transactionCounter > 0;
    }

    /**
     * Настройка параметров транзакции
     * @param string $useQueryTransaction использовать механизм управление транзакциями с помощью sql-запросов (TRUE) / с помощью PDO ([FALSE])
     * @param string $queryTStart sql-запрос для запуска транзакции, если NULL, то параметр не изменяется
     * @param string $queryTCommit sql-запрос для завершения транзакции, если NULL, то параметр не изменяется
     * @param string $queryTRollback sql-запрос для отката транзакции, если NULL, то параметр не изменяется
     * @param string $queryTStartRO sql-запрос для запуска "читающей" транзакции, если NULL, то параметр не изменяется
     * @throws DB_PDOException
     */
    public function dbSetupTransaction($useQueryTransaction, $queryTStart = NULL, $queryTCommit = NULL, $queryTRollback = NULL, $queryTStartRO = NULL) {
        if ($this->inTransaction()) {
            throw new DB_PDOException("DB_PDO::setupTransaction() call in transaction");
        }
        $this->useQueryTransaction = $useQueryTransaction;
        if ($queryTStart !== NULL) {
            $this->queryTStart = $queryTStart;
        }
        if ($queryTCommit !== NULL) {
            $this->queryTCommit = $queryTCommit;
        }
        if ($queryTRollback !== NULL) {
            $this->queryTRollback = $queryTRollback;
        }
        if ($queryTStartRO !== NULL) {
            $this->queryTStartRO = $queryTStartRO;
        }

        if ($this->useQueryTransaction && !($this->queryTStart && $this->queryTCommit && $this->queryTRollback)) {
            throw new DB_PDOException("DB_PDO::setupTransaction() invalid transaction params");
        }
    }

    /**
     * Настройка журналирования
     * @param string $logFile имя файла журнала
     * @param string $logLevel уровень жужналирования DB_PDO::DBLOG_XXX
     * @param string $logId идентификатор для журнала, по умолчанию случайный sha1
     */
    public function dbSetupLog($logFile, $logLevel = DB_PDO::DBLOG_QUERYERROR, $logId = NULL, $stripCrlf = TRUE) {
        $this->logFile = $logFile;
        $this->logLevel = $logLevel;
        $this->logId = $logId ? $logId : sha1(uniqid());
        /* не работает с постоянными коннектами, вместо этого сделано в обретке DB_PDO::fetch
          if(!$this->getAttribute(PDO::ATTR_PERSISTENT)){
          //General error: PDO::ATTR_STATEMENT_CLASS cannot be used with persistent PDO instances
          $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, array('DB_PDOStatement', array($this)));
          } */
    }

    /**
     * метод сделан по аналогии с http://docs.oracle.com/javase/7/docs/api/java/sql/Connection.html
     * @return boolean
     */
    public function isReadOnly() {
        return $this->readOnly;
    }

    /**
     * метод сделан по аналогии с http://docs.oracle.com/javase/7/docs/api/java/sql/Connection.html
     */
    public function setReadOnly($readOnly) {
        if ($this->inTransaction()) {
            if (!$readOnly && $this->readOnly) {
                throw new DB_PDOException('Transaction read only');
            }
        }
        else {
            $this->readOnly = $readOnly;
            if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) == "firebird" && defined("PDO::ATTR_READONLY")) {
                $this->setAttribute(PDO::ATTR_READONLY, $readOnly);
            }
        }
    }

    /**
     * Запуск транзакции встроенной в PDO/c помощью запроса в зависимости от параметра useQueryTransaction
     * @return boolean
     */
    public function beginTransaction() {
        if ($this->inTransaction()) {
            $this->transactionCounter++;
            return TRUE;
        }

        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) != "dblib") { //The auto-commit mode cannot be changed for this driver
            $this->setAttribute(PDO::ATTR_AUTOCOMMIT, FALSE);
        }
        if ($this->useQueryTransaction) {
            $res = $this->exec($this->readOnly? $this->queryTStartRO:$this->queryTStart);
        }
        else {
            $res = parent::beginTransaction();
        }
        if ($res) {
            $this->transactionCounter = 1;
            $this->transactionCommit = NULL;
        }
        else {
            $this->setAttribute(PDO::ATTR_AUTOCOMMIT, TRUE);
        }
        return $res;
    }

    /**
     * Запуск транзакции встроенной в PDO/c помощью запроса в зависимости от параметра useQueryTransaction
     * @param boolean $readOnly запуск "читающей" транзакции
     * @deprecated вместо dbBeginTransaction использовать java-style методы beginTransaction()/setReadonly()
     * @return boolean
     */
    public function dbBeginTransaction($readOnly = FALSE) {
        $this->setReadOnly($readOnly);
        return $this->beginTransaction();
    }

    /**
     * Завершение транзакции
     * @return boolean
     */
    public function commit() {

        $res = TRUE;
        if ($this->inTransaction()) {
            $this->transactionCounter--;
            if ($this->transactionCommit === NULL) {
                $this->transactionCommit = TRUE;
            }
            else if (!$this->transactionCommit) {
                throw new DB_PDOException('Transaction must rollback');
            }
            if ($this->transactionCounter == 0) {
                $res = $this->useQueryTransaction? $this->exec($this->queryTCommit):parent::commit();
                if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) != "dblib") { //The auto-commit mode cannot be changed for this driver
                    $this->setAttribute(PDO::ATTR_AUTOCOMMIT, TRUE);
                }
            }
        }
        return $res;
    }

    /**
     * Откат транзакции
     * @return boolean
     */
    public function rollBack() {

        $res = TRUE;
        if ($this->inTransaction()) {
            $this->transactionCounter--;
            if ($this->transactionCommit === NULL) {
                $this->transactionCommit = FALSE;
            }
            else if ($this->transactionCommit) {
                throw new DB_PDOException('Transaction must commit');
            }
            if ($this->transactionCounter == 0) {
                $res = $this->useQueryTransaction? $this->exec($this->queryTRollback):parent::rollBack();
                $this->setAttribute(PDO::ATTR_AUTOCOMMIT, TRUE);
            }
        }
        return $res;
    }

    /**
     * Прверка коннекта на "валидность"
     * @return boolean
     */
    public function dbIsValid() {
        $valid = TRUE;
        try {
            $this->beginTransaction();
            $this->commit();
        } catch (PDOException $e) {
            $valid = FALSE;
        }
        return $valid;
    }

    public function dbGetLogLevel() {
        return $this->logLevel;
    }

    public function dbSetExceptionMap($exceptionMap) {
        $this->exceptionMap = $exceptionMap;
    }

    /*
     * Откат активной транзакции при уничтожении класса
     * PDO транзакции и сами хорошо откатываются, даже в постоянных коннектах
     * Транзакции, запущенные с помощью sql-запроса - нет
     */

    public function __destruct() {
        if ($this->inTransaction()) {
            $this->transactionCounter = 1;
            if ($this->transactionCommit) {
                $this->commit();
            }
            else {
                $this->rollBack();
            }
        }
        //деструкторы, как и конструктукторы, объявленные в родительском классе, не вызваются автоматически
        //вызываем явно
        //ошибка:
        //PHP Fatal error:  Call to undefined method PDO::__destruct() in /usr/share/php/phplib/DB/PDO.php on line 159
        //parent::__destruct();
    }

    /**
     * Отпрепарить запрос с кэшированием и выполнить
     * @param string $query
     * @param array $driverOptions
     * @return type
     * @throws DB_PDOException
     */
    public function dbExecute($query, $params = array(), $driverOptions = array()) {
        $queryId = sha1($query . (count($driverOptions) ? serialize($driverOptions) : ""));
        $sth = null;
        if (isset($this->cachedQueries[$queryId])) {
            $sth = $this->cachedQueries[$queryId]['object'];
        }
        try {
            if (!$sth) {
                $sth = $this->prepare($query, $driverOptions);
                if (!$sth) {
                    throw new PDOException("PDO::prepare failed");
                }
                //кэшируем только параметризированные запросы
                if (count($params)) {
                    $this->cachedQueries[$queryId]['object'] = $sth;
                    $this->cachedQueries[$queryId]['query'] = $query;
                }
            }
            $this->dbLogQuery($query, $params, $queryId);
            $sth->execute($params);
        } catch (PDOException $e) {
            $this->dbHandleException($e, $query, $params, $queryId);
        }
        return $sth;
    }
    /**
     * Отпрепарить запрос с кэшированием и выполнить
     * @param string $query
     * @param array $driverOptions
     * @return type
     * @throws DB_PDOException
     */
    public function dbExecuteRepeatOverLock($query, $params = array(), $driverOptions = array()) {
        $queryId = sha1($query . (count($driverOptions) ? serialize($driverOptions) : ""));
        $sth = null;
        if (isset($this->cachedQueries[$queryId])) {
            $sth = $this->cachedQueries[$queryId]['object'];
        }
        try {
            if (!$sth) {
                $sth = $this->prepare($query, $driverOptions);
                if (!$sth) {
                    throw new PDOException("PDO::prepare failed");
                }
                //кэшируем только параметризированные запросы
                if (count($params)) {
                    $this->cachedQueries[$queryId]['object'] = $sth;
                    $this->cachedQueries[$queryId]['query'] = $query;
                }
            }
            $this->dbLogQuery($query, $params, $queryId);
            $this->repeatOverLock($sth, $query, $params, $queryId);
        } catch (PDOException $e) {
            $this->dbHandleException($e, $query, $params, $queryId);
        }
        return $sth;
    }

    /**
     * Повторяет запрос несколько раз если там deadlock или lock conflict
     * @param type $sth
     * @param type $params
     * @param type $cnt
     * @return type
     * @throws PDOException
     */
    private function repeatOverLock($sth, $query, $params = [], $queryId, $cnt = 0) {
        try {
            $sth->execute($params);
        } catch (PDOException $ex) {
            if ($cnt >= 3) {
                $this->dbLogQuery($query, $params, $queryId, "ПАДАЕМ по cnt");
                throw $ex;
            }
            // на локе и дедлоке повторим запрос
            if (strpos($ex->getMessage(), "deadlock") !== false || strpos($ex->getMessage(), "lock conflict") !== false) {
                sleep(3);
                $this->dbLogQuery($query, $params, $queryId, "ПОВТОРЯЕМ запрос (deadlock or lock conflict)");

                return $this->repeatOverLock($sth, $query, $params, $queryId, ++$cnt);
            }
            $this->dbLogQuery($query, $params, $queryId, "ПАДАЕМ");
            throw $ex;
        }
    }

    /**
     * fetch - вызывать PDOStatement::fetch  с обработкой ошибок
     * @param PDOStatement $sth
     * @param type $fetch_style
     * @param type $cursor_orientation
     * @param type $cursor_offset
     * @return type
     */
    public function fetch(PDOStatement $sth, $fetch_style = null, $cursor_orientation = PDO::FETCH_ORI_NEXT, $cursor_offset = 0) {
        $res = NULL;
        try {
            $res = $sth->fetch($fetch_style, $cursor_orientation, $cursor_offset);
        } catch (PDOException $e) {
            $this->dbHandleException($e);
        }
        return $res;
    }

    public function exec($statement) {
        $res = NULL;
        try {
            $this->dbLogQuery($statement);
            $res = parent::exec($statement);
        } catch (PDOException $e) {
            $this->dbHandleException($e, $statement);
        }
        return $res;
    }

    /**
     * пример как вырезать sql запросы из лога cut -b 125- [имя файла] |sed 's/$/;/'
     * @param type $query
     * @param type $params
     * @param type $queryId
     * @param type $shortDescription
     */
    private function dbLogQuery($query, $params = NULL, $queryId = NULL, $shortDescription = null) {
        if ($this->logLevel & self::DBLOG_QUERY) {
            if ($this->stripCrlLf) {
                $query = str_replace(array("\r", "\n"), array(" ", " "), $query);
            }
            if ($params && $this->logLevel & self::DBLOG_INTERPOLATE) {
                $this->writeLog("$shortDescription $queryId InterpolatedQuery: " . $this->dbInterpolateQuery($query, $params));
            } else {
                $this->writeLog("$shortDescription $queryId Query: " . $query);
                if ($params) {
                    $this->writeLog("$shortDescription $queryId Params: " . str_replace(PHP_EOL, " ", print_r($params, true)));
                }
            }
        }
    }

    public function dbHandleException(PDOException $e, $query = NULL, $params = NULL, $queryId = NULL) {
        $msg = $e->getMessage();
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) == "firebird") {
            //exception G_INVALIDDATE; - приходит в win1251 при коннекте в UTF-8
            //еще может прийти битый UTF-8, его попробуем пофиксить
            $msg = self::dbIconvWorkaround($msg);
        }
        $message = $msg;
        if ($query) {
            $message.= "\nQuery: " . $query;
        }
        if ($params) {
            $message.="\nParams: " . print_r($params, true);
            $message.="\nInterpolatedQuery: " . $this->dbInterpolateQuery($query, $params);
        }
        if ($this->logLevel & DB_PDO::DBLOG_ERROR) {
            $this->writeLog($queryId . " " . $msg);
        }
        $code = NULL;
        foreach ($this->exceptionMap as $key => $value) {
            if (strpos($msg, $key) !== FALSE) {
                $code = $value;
                break;
            }
        }
        throw new DB_PDOException($message, $code, $e);
    }

    public function writeLog($message) {
        if ($this->logFile) {
            $dt = new DateTime();
            error_log($this->logId . " [" . getmypid() . "] " . $dt->format("Y-m-d H:i:s.u") . " " . $message . PHP_EOL, 3, $this->logFile);
        }
    }

    /**
     * Получить кэшированный запрос
     * @param string $query
     * @param array $driverOptions
     * @return PDOStatement
     */
    public function dbGetCachedPDOStatement($query, $driverOptions = array()) {
        $queryHash = sha1($query . (count($driverOptions) ? serialize($driverOptions) : ""));
        $sth = null;
        if (isset($this->cachedQueries[$queryHash])) {
            $sth = $this->cachedQueries[$queryHash]['object'];
        }
        return $sth;
    }

    public function dbInterpolateQuery($query, $params) {
        return self::dbInterpolateQueryStatic($query, $params, $this);
    }

    /**
     * Replaces any parameter placeholders in a query with the value of that
     * parameter. Useful for debugging. Assumes anonymous parameters from
     * $params are are in the same order as specified in $query
     *
     * @param string $query The sql query with parameter placeholders
     * @param array $params The array of substitution parameters
     * @return string The interpolated query
     */
    public static function dbInterpolateQueryStatic($query, $params, PDO $pdo = NULL) {
        $keys = array();
        $values = $params;
        $namedParams = TRUE;

        # build a regular expression for each parameter
        foreach ($params as $key => $value) {
            if (substr($key, 0, 1) == ':') {
                $key = substr($key, 1, strlen($key));
            }
            if (is_string($key)) {
                $keys[] = '/:' . $key . '/';
            } else {
                $keys[] = '/[?]/';
                $namedParams = FALSE;
            }

            if (is_array($value)) {
                $values[$key] = implode(',', $value);
            }
        }
        foreach ($values as $k => $v) {
            if ($v === NULL) {
                $v = "NULL";
            } else if (!is_int($v)) {
                $v = $pdo ? $pdo->quote($v) : ("'" . $v . "'");
            }
            $values[$k] = $v;
        }
        //именованные параметры заменяем все без ограничения
        $query = preg_replace($keys, $values, $query, $namedParams ? -1 : 1, $count);

        return $query;
    }

    public static function dbIconvWorkaround($str) {
        if (mb_check_encoding($str, "cp1251") && !mb_check_encoding($str, "UTF-8")) {
            return iconv("cp1251", "UTF-8", $str);
        } else {
            return Transformer_String::fixUTF8($str);
        }
    }

}
