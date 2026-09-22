<?php
/**
 * Config connexion base de données.
 * Modifie ces valeurs selon ton environnement d'hébergement (ou passe par des
 * variables d'environnement si ton hébergeur le permet).
 */

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'traceo');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// Clé secrète pour signer les tokens d'authentification (JWT maison, voir helpers/auth.php)
// IMPORTANT: change cette valeur avant mise en production.
define('APP_SECRET', getenv('APP_SECRET') ?: 'change-moi-avant-production-0192837465');

// Fuseau horaire de l'établissement — change selon ton pays (ex: Africa/Porto-Novo, Africa/Abidjan, Africa/Dakar, Africa/Douala)
define('APP_TIMEZONE', getenv('APP_TIMEZONE') ?: 'Africa/Porto-Novo');
define('APP_TIMEZONE_OFFSET', getenv('APP_TIMEZONE_OFFSET') ?: '+01:00');
date_default_timezone_set(APP_TIMEZONE);

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsns = [
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            'mysql:host=127.0.0.1;port=3306;dbname=' . DB_NAME . ';charset=utf8mb4',
            'mysql:host=localhost;unix_socket=/opt/lampp/var/mysql/mysql.sock;dbname=' . DB_NAME . ';charset=utf8mb4',
        ];
        $lastException = null;
        foreach ($dsns as $dsn) {
            try {
                $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                break;
            } catch (Exception $e) {
                $lastException = $e;
            }
        }
        if ($pdo === null) {
            throw $lastException;
        }
        $pdo->exec("SET time_zone = '" . APP_TIMEZONE_OFFSET . "'");
    }
    return $pdo;
}
