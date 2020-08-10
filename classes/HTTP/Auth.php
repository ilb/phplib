<?php

/**
 *
 * $Id: Auth.php 609 2019-10-22 10:19:43Z dab $
 *
 * Вспомогательный класс для работы с авторизационными данными.
 * Непосредственно аутентификация выполняется на уровне сервера.
 *
 * @author dab@bystrobank.ru
 *
 */
class HTTP_Auth {
    /**
     * Получаем пользователя из http-заголовков
     *
     * @return string|null - идентификатор пользователя
     */
    private static function getXRemoteUserFromHeader() {
        if (array_key_exists("HTTP_X_REMOTE_USER", $_SERVER)) {
            $user = $_SERVER["HTTP_X_REMOTE_USER"];
        } else if (array_key_exists("REDIRECT_HTTP_X_REMOTE_USER", $_SERVER)) {
            $user = $_SERVER["REDIRECT_HTTP_X_REMOTE_USER"];
        } else {
            $user = null;
        }
        return $user;
    }

    /**
     * Получить идентификатор реального пользователя авторизованного удаленным приложением.
     * ВНИМАНИЕ! метод не выполняет проверку прав - только проверку полученных данных из HTTP-запроса.
     * Обязательно требуется проверить права после получения идентификатора пользователя!
     *
     * @param array $allowed - список идентификаторов которым разрешена подмена реального пользователя
     * @return string - идентификатор реального пользователя или удаленного приложения если недостаточно прав и пр.
     */
    public static function getXRemoteUser(array $allowed) {
        $user = self::getXRemoteUserFromHeader();
        if (!$user) {
            return $_SERVER["REMOTE_USER"];
        }
        if (!in_array($_SERVER["REMOTE_USER"], $allowed)) {
            trigger_error("X-Remote-User '" . $user . "' is not allowed for '" . $_SERVER["REMOTE_USER"] . "'"); //FIXME считаем фатальной - неверные настройки и пр.
        }
        return $user;
    }

    /**
     * Получить идентификатор реального пользователя авторизованного удаленным приложением.
     * проверку доверенных выполняет по членству в группе
     *
     * @param string $group - имя группы для проверки
     * @return string - идентификатор реального пользователя
     */
    public static function getXRemoteUserByGroup($group) {
        $user = self::getXRemoteUserFromHeader();
        if (!$user) {
            return $_SERVER["REMOTE_USER"];
        }
        $gr = posix_getgrnam($group) or trigger_error("posix_getgrnam " . $group . " failed");
        if (!in_array($_SERVER["REMOTE_USER"], $gr["members"])) {
            trigger_error("X-Remote-User '" . $user . "' is not allowed for '" . $_SERVER["REMOTE_USER"] . "'"); //FIXME считаем фатальной - неверные настройки и пр.
        }
        return $user;
    }

}
