<?php
/**
 * Traffic Monitor - web app bootstrap.
 *
 * The database credentials live in /opt/mt/tm_config.php, outside the web root.
 * Logins are the SAME admin_users accounts as the billing panel, so there is no
 * second password to remember and nothing is written to that table from here.
 */

require_once '/opt/mt/tm_config.php';

// A separate cookie name so signing in here never disturbs a billing-panel session
// (and vice versa) if both are ever served from the same host name.
if (session_status() === PHP_SESSION_NONE) {
    session_name('TMSESSID');
    session_start();
}

function tm_setting($key, $default = '') {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (tm_db()->query("SELECT k, v FROM tm_settings")->fetchAll() as $r) {
            $cache[$r['k']] = $r['v'];
        }
    }
    return array_key_exists($key, $cache) && $cache[$key] !== '' ? $cache[$key] : $default;
}

function tm_auth() {
    if (empty($_SESSION['tm_admin_id'])) {
        header('Location: /login.php');
        exit;
    }
}

function tm_is_admin() {
    return (($_SESSION['tm_role'] ?? '') === 'admin');
}

/** Pages that change configuration are for the owner only, not zone operators. */
function tm_require_admin() {
    tm_auth();
    if (!tm_is_admin()) {
        header('Location: /index.php');
        exit;
    }
}

function tm_csrf() {
    if (empty($_SESSION['tm_csrf'])) $_SESSION['tm_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['tm_csrf'];
}

function tm_csrf_ok() {
    return isset($_POST['csrf'], $_SESSION['tm_csrf'])
        && hash_equals($_SESSION['tm_csrf'], (string)$_POST['csrf']);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
