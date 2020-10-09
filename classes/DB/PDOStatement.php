<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of PDOStatement
 *
 * @author slavb
 */
class DB_PDOStatement extends PDOStatement {

    /**
     *
     * @var DB_PDO
     */
    private $dbh;

    protected function __construct($dbh) {
        $this->dbh = $dbh;
    }

    public function fetch($fetch_style = null, $cursor_orientation = null, $cursor_offset = 0) {
        $result = NULL;
        try {
            $result = parent::fetch($fetch_style, $cursor_orientation, $cursor_offset);
        } catch (PDOException $e) {
            $this->dbh->dbHandleException($e);
        }
        return $result;
    }

    public function __destruct() {
        if ($this->dbh->dbGetLogLevel() & DB_PDO::DBLOG_STATEMENT_DESCTRUCT) {
            ob_start();
            $this->debugDumpParams();
            $msg = ob_get_contents();
            ob_end_clean();
            $this->dbh->writeLog("__destruct called " . $msg);
        }
    }

}
