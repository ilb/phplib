<?php

/**
 * $Id: posix_extension.php 477 2016-04-12 11:37:17Z dab $
 *
 * реализует удобную bsd-функцию getgrouplist отсутствующую в php
 *
 * дополнительно корректирует инициализацию REMOTE_USER связанную с внутрениими редиректами PHP
 *
 * устанавливает глобальную константу __POSIX_ENT_LAST_MODIFIED__ тайштампу последней модификации
 * списков пользователей и групп posix.
 * использует стандартные /etc/passwd /etc/group и файлы nss_cache - /etc/passwd.cache /etc/group.cache
 *
 *
 * @author dab@bystrobank.ru
 */
if (array_key_exists("REDIRECT_REMOTE_USER", $_SERVER)) {
    $_SERVER["REMOTE_USER"] = $_SERVER["REDIRECT_REMOTE_USER"];
}

@define("__POSIX_ENT_LAST_MODIFIED__", max(filemtime("/etc/passwd"), filemtime("/etc/group"), filemtime("/etc/passwd.cache"), filemtime("/etc/group.cache")));

/**
 * используем затычку если нативная функция недоступна (непатченая пыха)
 */
if (!function_exists("posix_getgrouplist")) {

    /**
     * Получить группы принадлежащие пользователю
     *
     * @param sting $user имя пользователя
     * @param int $group цифровой идентификатор первичной группы пользователя (не используется)
     * @return array массив цифровых идентификатор групп принадлежащих пользователю
     */
    function posix_getgrouplist($user, $group) {
        $l = exec("id -G " . $user) or trigger_error("'id -G " . $user . "' failed");
        return explode(" ", $l);
    }

}
