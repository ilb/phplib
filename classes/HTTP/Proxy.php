<?php

/*
 * $Id: Proxy.php 576 2018-10-16 05:26:49Z dab $
 */

/**
 * Класс для написания прокси-оберток сервисов
 *
 * @author slavb
 */
class HTTP_Proxy {

    static $outputheadersmask = array("HTTP", "Content-Disposition", "Content-Type", "Last-Modified", "ETag");

    public static function accessCheck($targetUrl, $accessList, $substringMatch = FALSE) {
        $access = FALSE;
        if ($substringMatch) {
            foreach ($accessList as $path) {
                if (strncmp($path, $targetUrl, strlen($path)) == 0) {
                    $access = TRUE;
                    break;
                }
            }
        } else {
            $access = in_array($targetUrl, $accessList);
        }
        return $access;
    }

    public static function doGet($url, $customheaders, Curl_Config $curlConfig, $lastModMask = NULL) {
        $requestLastMod = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : NULL;
        $localLastMod = $lastModMask ? strtotime(date($lastModMask)) : NULL;
        if ($requestLastMod && $requestLastMod == $localLastMod) {
            header($_SERVER["SERVER_PROTOCOL"] . ' 304 Not Modified');
            return;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CAINFO, $curlConfig->cainfo);
        curl_setopt($ch, CURLOPT_SSLCERT, $curlConfig->sslcert);
        curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $curlConfig->sslcertpass);
        curl_setopt($ch, CURLOPT_TIMEOUT, $curlConfig->timeout);
        if (isset($curlConfig->userpwd)) { //isset на всякий случай, в некоторых проектах до сих пор свой Curl_Config и в нем нет userpwd
            curl_setopt($ch, CURLOPT_USERPWD, $curlConfig->userpwd);
        }

        if ($requestLastMod) {
            curl_setopt($ch, CURLOPT_TIMECONDITION, CURL_TIMECOND_IFMODSINCE);
            curl_setopt($ch, CURLOPT_TIMEVALUE, $requestLastMod);
        }
        $fpout = tmpfile();
        curl_setopt($ch, CURLOPT_VERBOSE, 0);
        curl_setopt($ch, CURLOPT_FILE, $fpout);
        $headersreq = array("Accept-charset: UTF-8");
        if (isset($_SERVER["HTTP_ACCEPT"])) {
            $headersreq[] = "Accept: " . $_SERVER["HTTP_ACCEPT"];
        }
        if ($customheaders) {
            if (!is_array($customheaders))
                $customheaders = array($customheaders);
            $headersreq = array_merge($headersreq, $customheaders);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headersreq);
        $headersfile = tmpfile();
        curl_setopt($ch, CURLOPT_WRITEHEADER, $headersfile);
        curl_exec($ch);
        fseek($fpout, 0);
        fseek($headersfile, 0);
        $httpheaders = explode("\n", fread($headersfile, 8192));
        fclose($headersfile);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($code == 200) {
            $lastModHeader = FALSE;
            foreach ($httpheaders as $header) {
                foreach (self::$outputheadersmask as $mask) {
                    if (strncasecmp($header, $mask, strlen($mask)) == 0) {
                        header($header);
                        if ($mask == "Last-Modified") {
                            $lastModHeader = TRUE;
                        }
                    }
                }
            }
            if (!$lastModHeader && $localLastMod) {
                header("Last-Modified: " . gmdate(DATE_RFC1123, $localLastMod));
            }
            fpassthru($fpout);
        } else if ($code == 304) {
            header($_SERVER["SERVER_PROTOCOL"] . ' 304 Not Modified');
        } else {
            throw new Exception($url . PHP_EOL . curl_error($ch) . (curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD) < 1 ? print_r($httpheaders, true) : "") . fread($fpout, 8192), $code + curl_errno($ch));
        }
        curl_close($ch);
        fclose($fpout);
    }

}
