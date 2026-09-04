<?php
/**
 * Shared configuration for the Traffic Monitor.
 *
 * Kept in /opt/mt (outside the web root) because it holds the database password:
 * both the collector service and the web app include this one file, so the
 * credentials never sit under a directory nginx can serve.
 */

define('TM_DB_HOST', 'localhost');
define('TM_DB_NAME', 'radius');
define('TM_DB_USER', 'radius');
define('TM_DB_PASS', 'CHANGE_ME_DB_PASSWORD');

define('TM_PY', '/opt/mt/mt_traffic.py');

// Serialises collector runs. Deliberately not under /tmp (the service uses
// PrivateTmp) nor /run/lock (fs.protected_regular blocks cross-user writes there).
define('TM_LOCK', '/opt/mt/tm_collect.lock');

/**
 * PDO handle. Pass true to force a new connection - the collector runs for weeks
 * and MySQL will eventually drop an idle link ("server has gone away").
 */
function tm_db($fresh = false) {
    static $pdo = null;
    if ($pdo === null || $fresh) {
        $pdo = new PDO(
            'mysql:host=' . TM_DB_HOST . ';dbname=' . TM_DB_NAME . ';charset=utf8mb4',
            TM_DB_USER, TM_DB_PASS,
            [PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES   => false]
        );
    }
    return $pdo;
}
