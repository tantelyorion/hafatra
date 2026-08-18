<?php
// ===================================================
// HAFATRA - config.php
// ===================================================

define('DB_HOST',       'localhost');
define('DB_USER',       'root');
define('DB_PASS',       '');
define('DB_NAME',       'hafatra4');   // ← vérifiez ce nom
define('UPLOAD_DIR',    __DIR__ . '/uploads/');
define('BASE_URL',      'http://localhost/hafatra4/');
define('MAX_FILE_SIZE', 50 * 1024 * 1024);

// Supprimer les warnings qui cassent le JSON
ini_set('display_errors', 0);
error_reporting(0);

// Config session sécurisée
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

session_set_cookie_params([
    'lifetime' => 86400 * 30, // 30 jours
    'path'     => '/',
    'secure'   => $isHttps,   // HTTPS seulement si disponible
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Créer les dossiers uploads si absents
$_dirs = ['uploads/avatars','uploads/images','uploads/videos','uploads/files','uploads/audio'];
foreach ($_dirs as $_d) {
    $_full = __DIR__ . '/' . $_d;
    if (!is_dir($_full)) @mkdir($_full, 0755, true);
}
unset($_dirs, $_d, $_full);

function db() {
    static $pdo = null;
    if (!$pdo) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))",
                ]
            );
        } catch (PDOException $e) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]);
            exit;
        }
    }
    return $pdo;
}

function auth() {
    if (empty($_SESSION['user_id'])) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'session_expired', 'redirect' => 'login.php']);
            exit;
        }
        header('Location: login.php');
        exit;
    }
    return (int)$_SESSION['user_id'];
}

function currentUser() {
    $uid  = auth();
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    return $stmt->fetch();
}

function jsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function sanitize($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}
