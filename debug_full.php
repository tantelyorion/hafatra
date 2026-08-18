<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';
echo "<h2>HAFATRA Debug Complet</h2><style>
body{font-family:monospace;padding:20px;background:#f5f5f5}
.ok{color:green;font-weight:bold} .err{color:red;font-weight:bold}
.warn{color:orange} pre{background:#fff;padding:10px;border-radius:8px;border:1px solid #ddd;overflow-x:auto}
h3{margin-top:20px;background:#333;color:#fff;padding:8px 12px;border-radius:6px}
</style><pre>";

if (empty($_SESSION['user_id'])) { echo "❌ Non connecté\n"; exit; }
$uid = (int)$_SESSION['user_id'];
$pdo = db();
echo "✅ uid=$uid | " . $pdo->query("SELECT VERSION()")->fetchColumn() . "\n\n";

// 1. COLONNES MANQUANTES
echo "<b>=== 1. Colonnes critiques ===</b>\n";
$checks = [
    'conversation_participants' => ['role','banned_at','banned_by','ban_reason','deleted_at','status'],
    'conversations'             => ['type','group_name','channel_public','channel_description'],
    'calls'                     => ['call_type'],
    'messages'                  => ['type','duration','file_path'],
];
foreach ($checks as $table => $cols) {
    $existing = array_column($pdo->query("DESCRIBE `$table`")->fetchAll(), 'Field');
    foreach ($cols as $col) {
        $ok = in_array($col, $existing);
        echo ($ok ? "  ✅" : "  ❌") . " $table.$col" . ($ok ? "" : " <b>MANQUANT</b>") . "\n";
    }
}
echo "\n";

// 2. ENUM VALUES
echo "<b>=== 2. Valeurs ENUM ===</b>\n";
$enumChecks = [
    ['conversation_participants', 'status', ['pending','accepted','spam','blocked','deleted']],
    ['conversation_participants', 'role',   ['member','moderator','admin']],
    ['conversations',             'type',   ['direct','group','channel']],
    ['messages',                  'type',   ['text','image','video','file','voice','system']],
    ['calls',                     'status', ['ringing','accepted','rejected','ended','missed','busy']],
];
foreach ($enumChecks as [$table, $field, $expected]) {
    $row = $pdo->query("SHOW COLUMNS FROM `$table` WHERE Field='$field'")->fetch();
    $enumStr = $row['Type'] ?? 'NOT FOUND';
    foreach ($expected as $val) {
        $ok = strpos($enumStr, "'$val'") !== false;
        if (!$ok) echo "  ❌ $table.$field manque '$val' — actuel: $enumStr\n";
    }
    $allOk = true;
    foreach ($expected as $val) { if (strpos($enumStr, "'$val'") === false) { $allOk = false; break; } }
    if ($allOk) echo "  ✅ $table.$field: OK ($enumStr)\n";
}
echo "\n";

// 3. TEST API DIRECT
echo "<b>=== 3. Test actions API ===</b>\n";

// get_group_info
$stmt = $pdo->prepare("SELECT cp.conversation_id FROM conversation_participants cp JOIN conversations c ON c.id=cp.conversation_id WHERE cp.user_id=? AND c.type='group' LIMIT 1");
$stmt->execute([$uid]);
$gid = $stmt->fetchColumn();
if ($gid) {
    echo "  Groupe trouvé: #$gid\n";
    $stmt2 = $pdo->prepare("SELECT u.id,u.name,cp.role,cp.banned_at,cp.ban_reason,cp.status FROM conversation_participants cp JOIN users u ON u.id=cp.user_id WHERE cp.conversation_id=?");
    $stmt2->execute([$gid]);
    $members = $stmt2->fetchAll();
    foreach ($members as $m) {
        echo "    - [{$m['id']}] {$m['name']} role={$m['role']} status={$m['status']}" . ($m['banned_at']?" BANNI":"") . "\n";
    }
} else {
    echo "  ⚠️ Aucun groupe trouvé\n";
}

// get_channels
$stmt = $pdo->query("SELECT id,group_name,type FROM conversations WHERE type='channel' LIMIT 5");
$channels = $stmt->fetchAll();
echo "  Canaux existants: " . count($channels) . "\n";
foreach ($channels as $ch) echo "    - [{$ch['id']}] {$ch['group_name']}\n";

// spam convs
$stmt = $pdo->prepare("SELECT cp.conversation_id,cp.status FROM conversation_participants cp WHERE cp.user_id=? AND cp.status='spam'");
$stmt->execute([$uid]);
$spams = $stmt->fetchAll();
echo "  Convs spam: " . count($spams) . "\n";
echo "\n";

// 4. JAVASCRIPT CONSOLE ERRORS — simuler
echo "<b>=== 4. SQL pour corriger tout ===</b>\n";
$fixes = [];

// Check and add fixes
$cols_cp = array_column($pdo->query("DESCRIBE conversation_participants")->fetchAll(), 'Field');
if (!in_array('banned_at', $cols_cp))    $fixes[] = "ALTER TABLE conversation_participants ADD COLUMN banned_at DATETIME DEFAULT NULL;";
if (!in_array('banned_by', $cols_cp))    $fixes[] = "ALTER TABLE conversation_participants ADD COLUMN banned_by INT DEFAULT NULL;";
if (!in_array('ban_reason', $cols_cp))   $fixes[] = "ALTER TABLE conversation_participants ADD COLUMN ban_reason VARCHAR(255) DEFAULT NULL;";
if (!in_array('deleted_at', $cols_cp))   $fixes[] = "ALTER TABLE conversation_participants ADD COLUMN deleted_at DATETIME DEFAULT NULL;";

// Check enums
$row = $pdo->query("SHOW COLUMNS FROM conversation_participants WHERE Field='role'")->fetch();
if (strpos($row['Type'], 'moderator') === false) {
    $fixes[] = "ALTER TABLE conversation_participants MODIFY COLUMN role ENUM('member','moderator','admin') DEFAULT 'member';";
}
$row2 = $pdo->query("SHOW COLUMNS FROM conversation_participants WHERE Field='status'")->fetch();
if (strpos($row2['Type'], 'deleted') === false) {
    $fixes[] = "ALTER TABLE conversation_participants MODIFY COLUMN status ENUM('pending','accepted','spam','blocked','deleted') DEFAULT 'pending';";
}
$row3 = $pdo->query("SHOW COLUMNS FROM conversations WHERE Field='type'")->fetch();
if (strpos($row3['Type'], 'channel') === false) {
    $fixes[] = "ALTER TABLE conversations MODIFY COLUMN type ENUM('direct','group','channel') DEFAULT 'direct';";
}
$cols_conv = array_column($pdo->query("DESCRIBE conversations")->fetchAll(), 'Field');
if (!in_array('channel_public', $cols_conv))      $fixes[] = "ALTER TABLE conversations ADD COLUMN channel_public TINYINT(1) DEFAULT 1;";
if (!in_array('channel_description', $cols_conv)) $fixes[] = "ALTER TABLE conversations ADD COLUMN channel_description TEXT DEFAULT NULL;";

$row4 = $pdo->query("SHOW COLUMNS FROM messages WHERE Field='type'")->fetch();
if (strpos($row4['Type'], 'voice') === false) {
    $fixes[] = "ALTER TABLE messages MODIFY COLUMN type ENUM('text','image','video','file','voice','system') DEFAULT 'text';";
}

$cols_calls = array_column($pdo->query("DESCRIBE calls")->fetchAll(), 'Field');
if (!in_array('call_type', $cols_calls)) {
    $fixes[] = "ALTER TABLE calls ADD COLUMN call_type ENUM('audio','video') DEFAULT 'audio';";
}

if (empty($fixes)) {
    echo "  ✅ Aucune correction SQL nécessaire !\n";
} else {
    echo "  ⚠️ " . count($fixes) . " correction(s) à exécuter dans phpMyAdmin :\n\n";
    foreach ($fixes as $f) echo "  $f\n";
}

// 5. AUTO-FIX (execute the fixes)
echo "\n<b>=== 5. Application automatique des corrections ===</b>\n";
foreach ($fixes as $f) {
    try {
        $pdo->exec($f);
        echo "  ✅ Exécuté: $f\n";
    } catch (Exception $e) {
        echo "  ❌ Erreur: " . $e->getMessage() . "\n     SQL: $f\n";
    }
}
if (empty($fixes)) echo "  ✅ Rien à corriger\n";

echo "\n<b>=== FIN ===</b>\n</pre>";
echo "<p style='color:red'><b>⚠️ Supprimez ce fichier après utilisation !</b></p>";
