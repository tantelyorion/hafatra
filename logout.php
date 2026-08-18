<?php
require_once 'config.php';
if (!empty($_SESSION['user_id'])) {
    db()->prepare("UPDATE users SET status='offline' WHERE id=?")->execute([$_SESSION['user_id']]);
}
session_destroy();
header('Location: login.php');
exit;
?>
