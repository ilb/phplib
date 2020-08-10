<?php

/**
 * $Id: Runnable.php 605 2019-07-18 05:22:30Z dab $
 *
 * Асинхронное выполнение PHP-кода
 *
 * необходимо реализовать класс наследующий Async_Runnable
 * и переопределить метод run($args). пример:
 *
 * class LongLongOperation extends Async_Runnable{
 *
 *  public function run( $args ){
 *   ....
 *   $this->setResult( $res );
 *  }
 * }
 *
 * странное поведение при PHP_FCGI_CHILDREN не равном 0
 * php пытается сам управлять процессами и схлопывает даже не им форкнутые и детаченые от него процессы
 * лучше установить в 0 и пусть apache сам управляет запуском-остановкой процессов php
 *
 * @todo не различить причину ошибки (по аналогии с http 4xx или 5xx) - все ошибки в куче.
 * @see https://josephscott.org/archives/2005/10/fake-fork-in-php/
 *
 * @author dab@bystrobank.ru
 */
class Async_Runnable {

    /**
     * @var string уникальный идентификатор асинхронного процесса
     */
    private $id;

    /**
     * @var integer текущий этап: 1 - синхронная часть (запуск)
     * 2 - синхронная часть (проверка)
     * -1 - асинхронная часть (выполнение)
     */
    private $phaze;

    /**
     * @var boolean признак завершения асинхронной части
     */
    private $done;

    /**
     * @var string имя каталога с временными файлами процесса
     */
    private $tempDir;

    /**
     * @var string имя файла с входными данными для процесса (stdin)
     */
    private $inputFile;

    /**
     * @var string имя файла стандартного вывода процесса (stdout)
     */
    private $outputFile;

    /**
     * @var string имя файла с ошибками процесса (stderr)
     */
    private $errorFile;

    /**
     * @var string имя файла с разультатом работы процесса
     */
    private $resultFile;

    /**
     * @var string имя файла с заголовками
     */
    private $resultHeadersFile;

    /**
     * @var string имя файла с информацией о ходе процесса
     */
    private $progressFile;

    /**
     * @var string имя файла подключаемого процессом файла (конфигурация и пр.)
     */
    private $bootstrapFile;

    /**
     * @var string имя файла с информацией о ходе процесса
     */
    private $durationFile;

    /**
     * @var string имя файла с системным идентификатором процесса PID
     */
    private $pidFile;

    /**
     * @var string имя файла блокировки (определять живой-неживой)
     */
    private $lockFile;
    private $lockfp;

    /**
     *
     * @var string доп. параметры запуска php
     */
    private $executionArgs;

    /**
     * Конструктор экзепляра асинхронного процесса
     * @param string $id идентификатор асинхронного процесса (для новых не указывается)
     */
    public function __construct($id = NULL) {
        $this->id = $id;
        if (!$id) {
            $this->phaze = 1;
            //для новых
            $this->id = date("YmdHis") . "-" . $_SERVER["UNIQUE_ID"] . "-" . uniqid();
        }
        $this->tempDir = self::getTempDir();
        $this->inputFile = $this->tempDir . "/" . $this->id . "/input";
        $this->outputFile = $this->tempDir . "/" . $this->id . "/output";
        $this->resultFile = $this->tempDir . "/" . $this->id . "/result";
        $this->resultHeadersFile = $this->tempDir . "/" . $this->id . "/resultheaders";
        $this->errorFile = $this->tempDir . "/" . $this->id . "/errlog";
        $this->progressFile = $this->tempDir . "/" . $this->id . "/progress";
        $this->durationFile = $this->tempDir . "/" . $this->id . "/duration";
        $this->lockFile = $this->tempDir . "/" . $this->id . "/lock";
        $this->pidFile = $this->tempDir . "/" . $this->id . "/pid";
        //для существующих проверим наличие файлов
        if ($id) {
            if (!preg_match("/^[A-Za-z0-9@=\-]{10,100}$/", $id)) {
                throw new Exception("invalid task id '" . $id . "'", 450);
            }
            $this->phaze = 2;
            clearstatcache();
            //эти файлы существуют пока работает асинхронный процесс
            if (!file_exists($this->errorFile)) {
                throw new Exception("task '" . $id . "' not running", 454);
            }
            if (!file_exists($this->outputFile)) {
                throw new Exception("task '" . $id . "' not running", 454);
            }
        }
        //асинхронный кусок
        if ($_SERVER["PHP_SELF"] == __FILE__ && isset($_SERVER["argv"][3]) && $_SERVER["argv"][3] == $id) {
            $this->phaze = -1;
            //используем одновременно и как флаг работы
            file_put_contents($this->pidFile, getmypid());

            //выставляем эксклюзивную блокировку файла
            $this->lockfp = fopen($this->lockFile, "w");
            flock($this->lockfp, LOCK_EX);

            //регистрируем обработчик завершения работы скрипта (когда скрипт завершен но работа не сделана)
            register_shutdown_function(array($this, "checkDone"));
        }
    }

    public static function getTempDir() {
        return sys_get_temp_dir() . "/AsyncRunnable-" . (getenv("USER") ? getenv("USER") : getenv("USERNAME"));
    }

    /**
     * Получить идентификатор асинхронного процесса
     * @return string идентификатор асинхронного процесса
     */
    public function getId() {
        return $this->id;
    }

/////////////////////////////////////////////////////
    public function getBootstrap() {
        return $this->bootstrapFile;
    }

    public function setBootstrap($path) {
        if ($this->phaze != 1) {
            trigger_error("method allowed only in phaze1");
        }
        $this->bootstrapFile = $path;
    }

    /**
     * Запустить процесс асинхронно
     * @param mixed $args аргументы будут переданы методу run()
     * @param int $duration приблизительное время выполнения (0 - неизвестно, или процесс указывает сам)
     * @return string присвоенный асинхронному процессу идентификатор
     */
    public function fork($args, $duration = 0) {
        if ($this->phaze != 1) {
            trigger_error("method allowed only in phaze1");
        }
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0770);
        }
        if (!is_dir($this->tempDir . "/" . $this->id)) {
            mkdir($this->tempDir . "/" . $this->id, 0770);
        }
        file_put_contents($this->inputFile, serialize($args));
        //сохраняем окружение
        $server = $_SERVER;
        //это переменные специфичны - их передавать не будем
        $unsafe = array(
            "argv",
            "argc",
            "PATH",
            "PWD",
            "FCGI_ROLE",
            "UNIQUE_ID",
            "SCRIPT_URL",
            "SCRIPT_URI",
            "SERVER_SIGNATURE",
            "SERVER_SOFTWARE",
            "SERVER_NAME",
            "SERVER_ADDR",
            "SERVER_PORT",
            "REMOTE_ADDR",
            "DOCUMENT_ROOT",
            "SCRIPT_FILENAME",
            "REMOTE_PORT",
            "REDIRECT_URL",
            "GATEWAY_INTERFACE",
            "SERVER_PROTOCOL",
            "REQUEST_METHOD",
            "QUERY_STRING",
            "REQUEST_URI",
            "SCRIPT_NAME",
            "ORIG_SCRIPT_FILENAME",
            "ORIG_PATH_INFO",
            "ORIG_PATH_TRANSLATED",
            "ORIG_SCRIPT_NAME",
            "PHP_SELF",
            "REQUEST_TIME",
            "CONTENT_TYPE",
            "CONTENT_LENGTH",
            "HTTPS",
        );
        //SERVER_ADMIN оставил - чтоб ошибки куда надо уходили
        //также выкинем специфичные для HTTP и SSL
        foreach (array_keys($server) as $key) {
            if (!strncmp($key, "HTTP_", 5) && $key != "HTTP_X_REMOTE_USER" && $key != "REDIRECT_HTTP_X_REMOTE_USER") {
                $unsafe[] = $key;
            } elseif (!strncmp($key, "SSL_", 4) || !strncmp($key, "REDIRECT_SSL_", 13)) {
                $unsafe[] = $key;
            }
        }
        foreach ($unsafe as $key) {
            if (array_key_exists($key, $server)) {
                unset($server[$key]);
            }
        }
        file_put_contents($this->inputFile . ".server", serialize($server));

        $cmd = "/bin/sh" .
                " -c '/usr/bin/php" .
                " -d max_execution_time=0 -d display_errors=stderr" .
                " -d memory_limit=512M " .
                ($this->executionArgs ? " $this->executionArgs" : "") .
                " " . __FILE__ . //0
                " " . getcwd() . //1
                " " . addslashes(get_class($this)) . //2
                " " . $this->id . //3
                " " . $this->bootstrapFile . //4
                " </dev/null 1>" . $this->outputFile . " 2>" . $this->errorFile .
                //" || echo \$? >".$this->errorFile.".code".
                " & disown -h -ar $!'" .
                " </dev/null 2>&1 1>" . $this->errorFile . " &";
        //форкаем асинхронный процесс
        //var_dump("exec");
        $ret = NULL;
        $output = NULL;
        exec($cmd, $output, $ret);
        if ($ret) {
            unlink($this->inputFile);
            trigger_error("exec('" . $cmd . "') failed with " . $ret . PHP_EOL . join(PHP_EOL, $output));
        }
        //запустились - перешли в фазу 2
        $this->phaze = 2;

        //ждем 10 секунд пока он запустится,
        //чтобы потом проверить работает он или нет, т.к. сразу же код возврата не получить
        //TODO на сильно загруженной тачке за 10 сек может не успеть создать новый процесс
        $start = time();
        while (!file_exists($this->pidFile) && time() - $start < 20) {
            clearstatcache();
            usleep(1000);
        }
        if (!file_exists($this->pidFile)) {
            trigger_error("fork failed: " . file_get_contents($this->errorFile));
        }

        //когда все запущено можно и продолжительность записать
        if (is_numeric($duration) && $duration > 0) {
            file_put_contents($this->durationFile, $duration);
        }
        return $this->id;
    }

    /**
     * завершить асинхронный процесс
     */
    public function kill($force = NULL) {
        if ($this->phaze != 2) {
            trigger_error("method allowed only in phaze2");
        }
        $sig = $force ? 9 : 15; //константы определены в модуле PCNTL - не используем их
        if (function_exists("posix_kill")) { //собрано с POSIX
            if (!posix_kill(file_get_contents($this->pidFile), $sig)) {
                throw new Exception("posix_kill failed: " . posix_strerror(posix_get_last_error()));
            }
        } else { //собрано без POSIX (не тестировано!!!)
            exec("kill -s " . $sig . " " . file_get_contents($this->pidFile));
            //@todo обработка ошибок
        }
        //даем асинхронному процессу время остановиться
        sleep(1);
    }

    /**
     * Проверить готовность данных процесса
     * @return boolean true - процесс завершен, false - процесс не завершен
     */
    public function isDone() {
        if ($this->phaze != 2) {
            trigger_error("method allowed only in phaze2");
        }
        //проверим оно работает ли вообще
        if ($this->isRunning()) {
            return FALSE;
        }
        clearstatcache();
        $start = time();
        while (!file_exists($this->resultFile) && time() - $start < 5) {
            clearstatcache();
            sleep(1);
        }
        //что-то записано в файл результата - его то мы и ждали
        if (file_exists($this->resultFile)) {
            //тут тоже ничего сами не удаляем
            //информация о работе процесса может быть собрана для статистики и пр.
            return TRUE;
        }
        //что-то записано в лог ошибок - возможно ошибка
        if (filesize($this->errorFile) > 0) {
            $error = file_get_contents($this->errorFile);
            $matches = NULL;
            $code = 550;
            if (preg_match("/^Error (\d\d\d)$/", substr($error, 0, 9), $matches)) {
                $code = $matches[1];
            }
            throw new Exception($error, $code);
        }
        trigger_error("async process unexpectedly terminated");
    }

    /**
     * проверяем жив ли процесс пытаясь эксклюзивно заблокировать файл
     * @return boolean живой-неживой
     */
    public function isRunning() {
        $this->lockfp = fopen($this->lockFile, "w");
        if (flock($this->lockfp, LOCK_EX | LOCK_NB)) {
            //удалось заблокировать - процесс мертв?
            fclose($this->lockfp);
            return FALSE;
        }
        fclose($this->lockfp);
        return TRUE;
    }

    /**
     * Получить информацию о прогрессе
     * @return mixed прогресс процесса
     */
    public function getProgress() {
//разрешил getProgress в асихронной фазе тоже для Report_Report::addTimedProgress
//        if ($this->phaze != 2) {
//            trigger_error("method allowed only in phaze2");
//        }
        clearstatcache();
        //выставлен самим процессом - его и отдадим
        if (file_exists($this->progressFile) && filesize($this->progressFile) > 0) {
            return unserialize(file_get_contents($this->progressFile));
        }
        //вычисляем процент примерно - на основе времени выполнения
        if (file_exists($this->durationFile)) {
            return ( time() - filemtime($this->pidFile) ) / ( file_get_contents($this->durationFile) ) * 100;
        }
        return NULL;
    }

    /**
     * Получить результат выполнения асинхронного процесса
     * @return mixed результат
     *
     * @see getResultFile()
     */
    public function getResult() {
        if ($this->phaze != 2) {
            trigger_error("method allowed only in phaze2");
        }
        return file_get_contents($this->resultFile);
    }

    /**
     * Получить результат
     * @return mixed результат
     *
     * @see getResultFile()
     */
    public function getResultHeaders() {
        if ($this->phaze != 2) {
            trigger_error("method allowed only in phaze2");
        }
        return file_exists($this->resultHeadersFile) ? file_get_contents($this->resultHeadersFile) : NULL;
    }

    /**
     * Получить имя файла с результатом выполнения асинхронного процесса
     * @return string имя файла с результатом
     */
    public function getResultFile() {
        if ($this->phaze != 2) {
            trigger_error("method allowed only in phaze2");
        }
        return $this->resultFile;
    }

    /**
     * Получить имя файла с
     * @return string имя файла с результатом
     */
    public function getResultHeadersFile() {
        if ($this->phaze != 2) {
            trigger_error("method allowed only in phaze2");
        }
        return $this->resultHeadersFile;
    }

    /**
     * Завершить работу с процессом
     * очистить все рабочие файлы
     */
    public function close() {
        if (!($this->phaze == -1 || $this->phaze == 2)) {
            trigger_error("method allowed only in phaze2");
        }
        if ($this->phaze == -1) {
            //если завершаем изнутри асинхронного
            //нужно немного подождать чтоб вызывавший успел зафиксировать запуск без ошибки
            //на случай если асинхронный завершится слишком быстро
            if (time() - filemtime($this->pidFile) < 1) {
                //если прошло меньше секунды подождем секундочку
                sleep(1);
            }
        }
        unlink($this->outputFile);
        unlink($this->errorFile);
        unlink($this->pidFile);
        clearstatcache();
        if (file_exists($this->progressFile)) {
            unlink($this->progressFile);
        }
        if (file_exists($this->resultFile)) {
            unlink($this->resultFile);
        }
        if (file_exists($this->resultHeadersFile)) {
            unlink($this->resultHeadersFile);
        }

        if (file_exists($this->durationFile)) {
            unlink($this->durationFile);
        }
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
        @rmdir($this->tempDir . "/" . $this->id);
        //@rmdir($this->tempDir); //оставляем пустой валяться? никому он не мешает
    }

/////////////////////////////////////////////////////
    /**
     * Собственно метод который вызывается в асинхронном режиме
     * @param mixed $args переметры переданные при вызове
     */
    protected function run($args) {
        if ($this->phaze != -1) {
            trigger_error("method allowed only in async phaze1");
        }
        trigger_error("AsyncRunnable->run() must be redefined");
    }

    public function getArgs() {
        if ($this->phaze != -1) {
            trigger_error("method allowed only in async phaze1");
        }
        //читаем входные параметры
        $args = unserialize(file_get_contents($this->inputFile));
        unlink($this->inputFile); //для безопасности
        return $args;
    }

    /**
     * Получить время начала работы асинхронного процесса
     * используется для расчетов прогресса процесса
     * @return int время начала работы асинхронного процесса
     * @see setProgress()
     */
    public function getStartTime() {
        if (!($this->phaze == -1 || $this->phaze == 2)) {
            trigger_error("method allowed only in phaze2" . $this->phaze);
        }
        return filemtime($this->pidFile);
    }

    /**
     * получить доп. параметры запуска php
     * @return string
     */
    public function getExecutionArgs() {
        return $this->executionArgs;
    }

    /**
     * Установить прогресс выполнения асинхронного процесса
     * Вызывается из run()
     * @param mixed $pr переменная с информацией о прогрессе
     */
    protected function setProgress($pr) {
        if ($this->phaze != -1) {
            trigger_error("method allowed only in async phaze1");
        }
        file_put_contents($this->progressFile . ".tmp", serialize($pr));
        rename($this->progressFile . ".tmp", $this->progressFile);
    }

    /**
     * Установить результат выполнения асинхронного процесса
     * Вызывается из run()
     * @param mixed $res переменная с результатами
     */
    protected function setResult($res) {
        if ($this->phaze != -1) {
            trigger_error("method allowed only in async phaze1");
        }
        //время записи непредсказуемо - делаем атомарно чтоб избежать одновременного доступа
        file_put_contents($this->resultFile . ".tmp", $res);
        $this->setResultFile($this->resultFile . ".tmp");
    }

    /**
     * Установить файл результата выполнения асинхронного процесса
     * Вызывается из run()
     * @param string $file имя файла
     */
    protected function setResultFile($filename) {
        clearstatcache();
        //перекладываем "как договаривались" - чтоб вызывавший смог найти результат
        rename($filename, $this->resultFile);
        clearstatcache();
        if (!file_exists($this->resultFile)) {
            trigger_error("opa!");
        }

        //освобождаем блокировку
        flock($this->lockfp, LOCK_UN);
        fclose($this->lockfp);
        unlink($this->lockFile);

        //выставляем флаг завершения работы
        $this->done = TRUE;
    }

    protected function setResultHeaders($res) {
        if ($this->phaze != -1) {
            trigger_error("method allowed only in async phaze1");
        }
        //время записи непредсказуемо - делаем атомарно чтоб избежать одновременного доступа
        file_put_contents($this->resultHeadersFile . ".tmp", $res);
        $this->setResultHeadersFile($this->resultHeadersFile . ".tmp");
    }

    /**
     * Установить файл результата выполнения асинхронного процесса
     * Вызывается из run()
     * @param string $file имя файла
     */
    protected function setResultHeadersFile($filename) {
        rename($filename, $this->resultHeadersFile);
    }

    /**
     * установить доп. параметры запуска php
     * например -d xdebug.profiler_enable=1
     * @param string $val
     */
    public function setExecutionArgs($val) {
        $this->executionArgs = $val;
    }

    public function setExecutionArgsXdebug() {
        $this->setExecutionArgs("-d xdebug.profiler_enable=1 -d xdebug.profiler_output_dir=" . getenv("HOME"));
    }

    /**
     * вызывается при завершении работы скрипта для проверки
     * было ли все равершено правильно
     */
    public function checkDone() {
        if ($this->done != TRUE) {
            file_put_contents($this->errorFile, PHP_EOL . "ERROR: setResult not called", FILE_APPEND);
        }
    }

    /**
     * Вывести HTML-страницу с информацией о процессе и перейти по адресу через интервал
     *
     * @param type $url адрес для повторного запроса
     * @param type $timeout интревал перед повторением запроса
     * @param type $title Заголовок страницы и окна. процент и пр. значения прогресса удобно выводить вначале заголовка - тогда видно при свернутых окнах
     * @param type $body Сообщение в теле страницы
     */
    public function printWaitPage($url, $timeout = 1, $title = "Ждите..", $body = "") {
        //выдаем 202 а не 200 и 302 чтоб отличать запуск асинхронного от "ок" и перенаправления
        //302 так же нельзя выдавать много раз подряд - клиент может расценить как зацикливание
        header($_SERVER["SERVER_PROTOCOL"] . " 202 Accepted");
        header("Refresh: " . $timeout . ";" . $url);
        header("Content-type: text/html; charset=UTF-8");
        echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.1//EN\" \"http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd\">", PHP_EOL,
        "<html xmlns=\"http://www.w3.org/1999/xhtml\" xml:lang=\"ru\">", PHP_EOL,
        "<head>", PHP_EOL,
        "<meta http-equiv=\"content-type\" content=\"text/html; charset=UTF-8\"/>", PHP_EOL,
        "<title>", $title, "</title>", PHP_EOL,
        "<script type=\"text/javascript\">", PHP_EOL,
        "//<![CDATA[", PHP_EOL,
        "function Refresh(){ document.location.replace('", $url, "'); }", PHP_EOL,
        "//]]>", PHP_EOL,
        "</script>", PHP_EOL,
        "</head>", PHP_EOL,
        //для жаваскрипта делаем таймаут меньше - чтоб сработал только один из них
        "<body onload=\"setTimeout(Refresh,", ($timeout * 1000 - 100), ")\">", PHP_EOL,
        //если не указано что написать в тело - продублируем туда заголовок
        "<div id=\"body\">", ($body ? $body : $title), "</div>", PHP_EOL,
        //нарисуем ссылку чотб можно было вручную клацнуть для перехода
        "<p><a href=\"", htmlspecialchars($url), "\">Обновить</a></p>", PHP_EOL,
        "</body>", PHP_EOL,
        "</html>", PHP_EOL;
    }

}

/////////////////////////////////////////////////////
if ($_SERVER["PHP_SELF"] == __FILE__ && isset($_SERVER["argv"][3])) {
    //var_dump("forked");
    //меняем каталог как было при вызове
    chdir($_SERVER["argv"][1]);

    $tempDir = Async_Runnable::getTempDir();
    //восстанавливаем окружение
    //TODO переделать без временных файлов (stdin?)
    $_SERVER = array_merge(unserialize(file_get_contents($tempDir . "/" . $_SERVER["argv"][3] . "/input.server")), $_SERVER);
    unlink($tempDir . "/" . $_SERVER["argv"][3] . "/input.server");

    //цепляем bootstrap чтоб найти классы
    if (array_key_exists(4, $_SERVER["argv"])) {
        require_once($_SERVER["argv"][4]);
    }

    //создаем экземпляр класса с указанным идентификатором
    $asyncRunnable = new $_SERVER["argv"][2]($_SERVER["argv"][3]);

    //запускаем с указанными параметрами
    $asyncRunnable->run($asyncRunnable->getArgs());

    //по окончании ничего сами не удаляем - мало ли чего "там" еще запросят после завершения
}
