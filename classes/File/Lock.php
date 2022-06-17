<?php

/*
 * Файловая блокировка
 * @version $Id$
 */

/**
 * Description of File_Lock
 *
 * @author slavb
 */
class File_Lock {

    private $lockfile;
    private $lockfp;
    private $lockino;
    private $locked;
    private $shared;
    private $timeout;
    private $mode;
    private $start;
    private $tracelog;

    /**
     * Блокировка.
     * Разделяемая блокировка может ставиться если нет эксклюзивной.
     * Одновременное количество разделяемых блокировок не ограничено.
     * Разделяемая блокировка может использоваться для конкурентного чтения ресурса.
     * Получение разделяемой блокировки означает защиту ресурса от модификации на время чтения.
     *
     * Эксклюзивная блокировка может ставиться если нет никакой блокировки
     * (ни одной разделяемой, ни эксклюзивной). Она может быть только одна.
     * Эксклюзивная блокировка может использоваться для обновления конкурентного ресурса.
     * При этом чтения ресурса блокируются на время изменения ресурса.
     *
     * @param string $lockfile путь к файлу
     * @param int $timeout таймаут в секундах
     * @param boolean $shared разделяемая (TRUE)/эксклюзивная(FALSE) блокировка. по умолчанию - эксклюзиваная (FALSE)
     * @param int $mode права доступа к файлу (если NULL то согласно тек. маске). 0666 - дать доступ всем (все равно нужна только блокировка)
     * @param bool $lock устанавливать блокировку в конструкторе (по-умолчанию TRUE)
     * @param resource $tracelog хендл для трайса (для fwrite)
     */
    public function __construct($lockfile, $timeout = NULL, $shared = FALSE, $mode = NULL, $lock = TRUE, $tracelog = NULL) {
        $this->lockfile = $lockfile;
        $this->shared = $shared;
        $this->timeout = $timeout;
        $this->mode = $mode;
        $this->tracelog = $tracelog;
        if ($lock) {
            $this->lock();
        }
    }

    public function setTracelog($tracelog) {
        $this->tracelog = $tracelog;
    }

    private function openlockfile() {
        if ($this->tracelog) {
            fwrite($this->tracelog, date(DATE_ATOM) . " openlockfile() " . $this->lockfile . PHP_EOL);
        }
        $this->lockfp = fopen($this->lockfile, "w");
        $fstat = fstat($this->lockfp);
        $this->lockino = $fstat["ino"];
        if ($this->mode !== NULL) {
            if (($fstat["mode"] & 0777) != $this->mode) {
                /* @rule если передан параметр mode, и он не совпадает с правами файла, исправим права */
                chmod($this->lockfile, $this->mode);
            }
        }
    }

    private function closelockfile() {
        if ($this->tracelog) {
            fwrite($this->tracelog, date(DATE_ATOM) . " closelockfile() " . $this->lockfile . PHP_EOL);
        }
        fclose($this->lockfp);
        $this->lockfp = NULL;
    }

    /**
     * Блокировка
     */
    public function lock() {
        if (!$this->locked) {
            $this->start = $this->timeout !== NULL ? time() : NULL;
            $retry = TRUE;
            while ($retry) {
                $this->openlockfile();
                if ($this->timeout !== NULL) {
                    /* @rule если блокировка с таймаутом, выставляем неблокирующую (LOCK_NB) блокировку в цикле */
                    $this->locked = flock($this->lockfp, ($this->shared ? LOCK_SH : LOCK_EX) | LOCK_NB);
                    if (!$this->locked) {
                        while (!$this->locked && (time() - $this->start) < $this->timeout) {
                            /* @rule если не заблокировали то закроем файл, подождем 1 сек, откроем файл заново и попробуем снова, пока не истечет таймаут */
                            $this->closelockfile();
                            sleep(1);
                            $this->openlockfile();
                            $this->locked = flock($this->lockfp, ($this->shared ? LOCK_SH : LOCK_EX) | LOCK_NB);
                        }
                    }
                } else {
                    /* если блокировка без таймаута, выставляем блокирующую :) блокировку */
                    $this->locked = flock($this->lockfp, ($this->shared ? LOCK_SH : LOCK_EX));
                }
                $retry = FALSE;
                if ($this->locked) {
                    if (file_exists($this->lockfile)) {
                        /* @rule если заблокировали и файл сущуствует, проверим его инод */
                        $stat = @stat($this->lockfile); //ошибка подавляется на случай race condition
                        if (!$stat || $stat["ino"] != $this->lockino) {
                            /* @rule если инод заблокированного не совпадает с файлом на диске, нужно повторить попытку */
                            flock($this->lockfp, LOCK_UN);
                            $this->locked = FALSE;
                            if ($this->timeout === NULL || (time() - $this->start) < $this->timeout) {
                                /* @rule если блокировка без таймаута или таймаут не истек, нужно повторить попытку */
                                $retry = TRUE;
                            }
                        }
                    } else {
                        /* @rule если заблокировали и файл НЕ сущуствует, нужно повторить попытку */
                        $retry = TRUE;
                    }
                }
                if (!$this->locked) {
                    $this->closelockfile();
                }
            }
        }
        return $this->locked;
    }

    public function isLocked() {
        return $this->locked;
    }

    public function unlock() {
        if ($this->locked) {
            // файл может быть удален предыдущим блокирующим процессом
            if (!$this->shared && file_exists($this->lockfile)) {
                $stat = @stat($this->lockfile); //ошибка подавляется на случай race condition
                if ($stat && $stat["ino"] == $this->lockino) {
                    /* @rule удаляем файл если его инод софпадает с заблокированным файлом */
                    unlink($this->lockfile);
                }
            }
            //снимаем блокировку
            flock($this->lockfp, LOCK_UN);
            $this->closelockfile();
            $this->locked = FALSE;
        }
    }

    public function __destruct() {
        $this->unlock();
    }

}
