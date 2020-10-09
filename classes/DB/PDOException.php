<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of PDOException
 *
 * @author slavb
 */
class DB_PDOException extends PDOException {

    public function __construct($message = "", $code = 0, Exception $previous = NULL) {
        parent::__construct($message, +$code, $previous);
    }

}
