<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of PDOFactory
 *
 * @author slavb
 */
class DB_PDOFactory {

    /**
     * sql запрос для запуска "пишушей" трназакции firebird
     */
    const FB_TRANS_WRITE = "SET TRANSACTION READ WRITE READ COMMITTED RECORD_VERSION NO WAIT";

    /**
     * sql запрос для запуска "читающей" трназакции firebird
     */
    const FB_TRANS_READ = "SET TRANSACTION READ ONLY READ COMMITTED RECORD_VERSION NO WAIT";
    const FB_TRANS_COMMIT = "COMMIT";
    const FB_TRANS_ROLLBACK = "ROLLBACK";

    protected static $instance;
    protected $connections = array();

    protected function __construct() {

    }

    /**
     * Получить экземпляр класса
     * @return DB_PDOFactory
     */
    public static function getInstance() {
        if (!self::$instance) {
            self::$instance = new DB_PDOFactory();
        }
        return self::$instance;
    }

    /**
     * Получить экземпляр PDO
     * @param DB_Config $DBConfig
     * @param array $options
     * @return \DB_PDO
     */
    public function getPDO(DB_Config $DBConfig, $options = NULL, $newInstance = FALSE) {
        $instanceKey = $DBConfig->getHash();
        $pdo = NULL;
        if ($newInstance || !isset($this->connections[$instanceKey])) {
            $PDOConnectString = $DBConfig->toPDOConnectString();
            $pdo = new DB_PDO($PDOConnectString, $DBConfig->getUser(), $DBConfig->getPass(), $options);
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) == "firebird") {
                /* @rule для firebird параметры транзакции по-умолчанию в PDO не подходят.
                 * Для работы с транзакциями используются sql-запросы, по -умолчанию настраивается "пищущая" транзакция,
                 * для запуска "читающей" транзакции в коде программы нужно выполнить $pdo->setReadonly(TRUE)
                 */
                if (defined("PDO::ATTR_READONLY")) {
                    //сброс параметров транзакции (актуально для постоянных коннектов)
                    $pdo->setAttribute(PDO::ATTR_READONLY, FALSE);
                } else {

                    $pdo->dbSetupTransaction(TRUE, DB_PDOFactory::FB_TRANS_WRITE, DB_PDOFactory::FB_TRANS_COMMIT, DB_PDOFactory::FB_TRANS_ROLLBACK, DB_PDOFactory::FB_TRANS_READ);
                }
                $pdo->dbSetExceptionMap(array("arithmetic exception" => 550, "exception" => 450));
            }
            $this->connections[$instanceKey] = $pdo;
        } else {
            $pdo = $this->connections[$instanceKey];
        }
        return $pdo;
    }

}
