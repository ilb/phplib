<?php

/*
 * @author Борисов Вячеслав <slavb@bystrobank.ru>
 * @version $Id: Type.php 638 2020-09-10 09:09:18Z dab $
 * @package Mime
 */

/**
 * Работа с миме-типами.
 * Опредлеление миме-типа по содержимому и расширению файла.
 */
class Mime_Type {

    /**
     * Путь к файлу mime.types
     */
    const MIME_BASE_PATH = "/etc/mime.types";

    /**
     * Признак инициализированности статических данных
     * @var boolean
     */
    private static $initialized = FALSE;

    /**
     * Данны mime.types, индексированные по миме-типу.
     * Массив [миме-тип]=>array(расширение1,расширение2,...,расширениеN)
     * @var array
     */
    private static $typeToExt = NULL;

    /**
     * Данны mime.types, индексированные по расширению.
     * Массив [расширение]=>миме-тип
     * @var array
     */
    private static $extToType = NULL;

    /**
     * Экземпаляр класса finfo.
     * См. {@link http://ru.php.net/fileinfo}
     * @var finfo
     */
    private static $fileInfo = NULL;

    /**
     * Конструктор.
     * Вызывает {@link initialize()} при первом вызове.
     */
    public function __construct() {
        if (!self::$initialized) {
            $this->initialize();
        }
    }

    /**
     * Инициализация статических данных.
     * Заполняются массивы {@link $typeToExt}, {@link $extToType}, инициализируется
     * {@link $fileInfo}.
     * Флагу {@link $initialized} присваивается значение TRUE.
     */
    private function initialize() {
        $mimeFile = file(self::MIME_BASE_PATH);
        foreach ($mimeFile as $line) {
            if (strpos($line, "#") === FALSE) {
                $items = preg_split("/\s+/", trim($line));
                if (count($items) > 1) {
                    $mimeType = array_shift($items);
                    self::$typeToExt[$mimeType] = $items;
                    foreach ($items as $ext) {
                        self::$extToType[$ext] = $mimeType;
                    }
                }
            }
        }
//Ticket#: 2012021510000329 ] Не определен тип файла. Проверьте содержимое и расширение.
//finfo не пользуемся, т.к. база старая, не понимает некоторые ods
//   self::$fileInfo = new finfo(FILEINFO_MIME); //"/usr/share/misc/magic.mgc"; //"/usr/share/misc/file/magic.mime.mgc"
//   if (!self::$fileInfo) {
//       throw new FatalErrorException("Opening fileinfo database failed");
//   }

        self::$initialized = TRUE;
    }

    /**
     * Выводит внутреннее состояние объекта.
     */
    public function dump() {
        var_dump(self::$initialized);
        var_dump(self::$extToType);
        var_dump(self::$typeToExt);
        var_dump(self::$fileInfo);
    }

    /**
     * Проверить на доступность расширения для миме-типа.
     * @param string $ext расширение (без .)
     * @param string $mimeType миме-тип
     * @return boolean
     */
    public function isValidExtForType($ext, $mimeType) {
        $result = isset(self::$extToType[$ext]) && self::$extToType[$ext] == $mimeType;
        return $result;
    }

    /**
     * Получить список расширений для миме-типа.
     * @param string $mimeType
     * @return array
     */
    public function getValidExtListForType($mimeType) {
        $result = isset(self::$typeToExt[$mimeType]) ? self::$typeToExt[$mimeType] : array();
        return $result;
    }

    /**
     * Получить расширение по-умочанию для миме-типа.
     * @param string $mimeType
     * @return array
     */
    public function getDefaultExtForType($mimeType) {
        $validExtList = $this->getValidExtListForType($mimeType);
        return count($validExtList) ? reset($validExtList) : NULL;
    }

    /**
     * Получить миме-тип по расширению.
     * @param string $ext расширение (без .)
     * @return string миме-тип файла
     */
    public function getTypeByExt($ext) {
        //приводим расширение к нижнему регистру как в mime.types, чтобы не различать .pdf .PDF .pDF
        //если будут явно указанные расширения различного регистра нужно будет "нормализовать" к ним, а не просто заловеркейсить
        $ext = strtolower($ext);
        $result = isset(self::$extToType[$ext]) ? self::$extToType[$ext] : 'application/octet-stream';
        return $result;
    }

    /**
     * Получить миме-тип файла по его содержимому с уточнением по расширению.
     * @param string $filePath путь к файлу
     * @param string $ext расширение имени файла
     * @param boolean $validate2 выполнить более надежную проверку содержимого
     * @return string mime-тип
     */
    public function getTypeByContentAndExt($filePath, $ext = NULL, $validate2 = FALSE, &$validate2result = NULL) {
        //$mimeType=self::$fileInfo->file($filePath);
        $output = array();
        $retval = NULL;
        $cmd = "file -bi " . escapeshellarg($filePath) . " 2>&1";
        $mimeType = exec($cmd, $output, $retval);
        if ($retval)
            trigger_error("exec($cmd) failed: " . $retval . "\n" . implode("\n", $output), E_USER_ERROR);
        $ch = strpos($mimeType, ";");
        if ($ch !== FALSE)
            $mimeType = substr($mimeType, 0, $ch);
        $validate2result = NULL;
        switch ($mimeType) {
            case "application/zip":
            case "application/vnd.ms-office":
            case "application/msword":
            case "text/plain":
                if ($ext !== NULL) {
                    /* @rule если передано не чистое расширение, выгрызем все лишнее */
                    if (strpos($ext, ".") !== FALSE) {
                        $ext = pathinfo($ext, PATHINFO_EXTENSION);
                    }
                    $mimeType = $this->getTypeByExt($ext);
                }
                break;
            case "application/pdf":
                if ($validate2) {
                    $output = array();
                    $retval = NULL;
                    $cmd = "pdfinfo $filePath";
                    exec($cmd, $output, $retval);
                    $validate2result = implode("\n", $output);
                    if ($retval) {
                        $mimeType = "application/octet-stream";
                    }
                }
        }
        return $mimeType;
    }

}
