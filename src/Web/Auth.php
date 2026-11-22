<?php
namespace TorrentMonitor\Web;

use TorrentMonitor\Data\Settings;
use TorrentMonitor\Storage\Files;

//Web interface login: the session and an optional long-lived login ("remember me").
final class Auth
{
    const COOKIE = 'tm_login';
    const REMEMBER_DAYS = 60;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE)
            return;
        session_name('tm_session');
        session_set_cookie_params(array('path' => '/', 'httponly' => TRUE, 'samesite' => 'Lax'));
        session_start();
        if (empty($_SESSION['csrf']))
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function csrf(): string
    {
        self::start();
        return $_SESSION['csrf'];
    }

    public static function csrfValid(): bool
    {
        self::start();
        $sent = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string)$_SERVER['HTTP_X_CSRF_TOKEN'] : '';
        return $sent !== '' && hash_equals($_SESSION['csrf'], $sent);
    }

    //whether password protection is enabled
    public static function required(): bool
    {
        return Settings::on('auth');
    }

    public static function ok(): bool
    {
        self::start();
        if ( ! self::required() || ! empty($_SESSION['in']))
            return TRUE;
        $token = isset($_COOKIE[self::COOKIE]) ? (string)$_COOKIE[self::COOKIE] : '';
        if ($token === '')
            return FALSE;
        $logins = Files::state()->read()['logins'];
        $key = hash('sha256', $token);
        if (isset($logins[$key]) && $logins[$key] > time())
            return $_SESSION['in'] = TRUE;
        return FALSE;
    }

    //verifies the password; a password in the old format (md5) is replaced with bcrypt on first login
    public static function passwordValid(string $password): bool
    {
        $stored = (string)Settings::get('password');
        if ($stored === '')
            return FALSE;
        if (password_verify($password, $stored))
            return TRUE;
        if (strlen($stored) == 32 && hash_equals($stored, md5($password)))
        {
            self::setPassword($password);
            return TRUE;
        }
        return FALSE;
    }

    public static function setPassword(string $password): void
    {
        Settings::set('password', password_hash($password, PASSWORD_BCRYPT));
        //the password was changed: earlier long-lived logins are no longer valid
        Files::state()->update(function (&$data) { $data['logins'] = array(); });
    }

    public static function login(string $password, bool $remember): bool
    {
        self::start();
        if ( ! self::passwordValid($password))
            return FALSE;
        session_regenerate_id(TRUE);
        $_SESSION['in'] = TRUE;
        if ($remember)
        {
            $token = bin2hex(random_bytes(32));
            $expires = time() + self::REMEMBER_DAYS * 86400;
            Files::state()->update(function (&$data) use ($token, $expires) {
                foreach ($data['logins'] as $key => $until)
                    if ($until <= time())
                        unset($data['logins'][$key]);
                $data['logins'][hash('sha256', $token)] = $expires;
            });
            setcookie(self::COOKIE, $token, array('expires' => $expires, 'path' => '/', 'httponly' => TRUE, 'samesite' => 'Lax'));
        }
        return TRUE;
    }

    public static function logout(): void
    {
        self::start();
        $token = isset($_COOKIE[self::COOKIE]) ? (string)$_COOKIE[self::COOKIE] : '';
        if ($token !== '')
        {
            Files::state()->update(function (&$data) use ($token) { unset($data['logins'][hash('sha256', $token)]); });
            setcookie(self::COOKIE, '', array('expires' => 1, 'path' => '/'));
        }
        unset($_SESSION['in']);
    }
}
