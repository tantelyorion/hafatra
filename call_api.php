<?php
// HAFATRA - call_api.php v4 | Signaling WebRTC audio + vidéo
require_once 'config.php';
$uid  = auth();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// ── Nettoyage global à chaque requête ────────────────────────────────────
// Appels "ringing" sans réponse après 60s → missed
db()->prepare("UPDATE calls SET status='missed' WHERE status='ringing' AND created_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)")->execute();
// Appels "accepted" qui durent plus de 3h → ended (session perdue)
db()->prepare("UPDATE calls SET status='ended', ended_at=NOW() WHERE status='accepted' AND started_at < DATE_SUB(NOW(), INTERVAL 3 HOUR)")->execute();
// Signaux vieux de plus de 3 minutes → supprimés
db()->prepare("DELETE FROM call_signals WHERE created_at < DATE_SUB(NOW(), INTERVAL 3 MINUTE)")->execute();

switch ($action) {

// ─── NETTOYER SES PROPRES APPELS (appelé avant chaque initiate) ─────────
case 'cleanup_stale_calls':
    // Force-terminer TOUS les appels actifs liés à cet utilisateur
    // Utile quand l'utilisateur recharge la page sans raccrocher
    db()->prepare(
        "UPDATE calls SET status='ended', ended_at=NOW()
         WHERE status IN ('ringing','accepted')
           AND (caller_id=? OR callee_id=?)"
    )->execute([$uid, $uid]);
    jsonResponse(['success' => true]);
    break;

// ─── INITIER UN APPEL ────────────────────────────────────────────────────
case 'initiate_call':
    $calleeId = (int)($body['callee_id'] ?? 0);
    $convId   = (int)($body['conv_id']   ?? 0);
    $callType = in_array($body['call_type'] ?? '', ['audio','video']) ? $body['call_type'] : 'audio';

    if (!$calleeId || !$convId) jsonResponse(['error' => 'Paramètres manquants']);

    // 1. Forcer la fin de tout appel précédent de L'APPELANT lui-même
    //    (cas : refresh de page sans avoir raccroché)
    db()->prepare(
        "UPDATE calls SET status='ended', ended_at=NOW()
         WHERE caller_id=? AND status IN ('ringing','accepted')"
    )->execute([$uid]);

    // 2. Vérifier si l'APPELÉ est dans un appel ACTIF (ringing depuis < 55s ou accepted)
    //    On ignore les appels > 55s car déjà nettoyés par le cron ci-dessus
    $stmt = db()->prepare(
        "SELECT id FROM calls
         WHERE callee_id = ?
           AND status IN ('ringing','accepted')
         LIMIT 1"
    );
    $stmt->execute([$calleeId]);
    if ($stmt->fetch()) jsonResponse(['error' => 'Contact actuellement en appel']);

    // 3. Vérifier blocage
    $stmt = db()->prepare("SELECT id FROM blocks WHERE blocker_id=? AND blocked_id=?");
    $stmt->execute([$calleeId, $uid]);
    if ($stmt->fetch()) jsonResponse(['error' => "Impossible d'appeler ce contact"]);

    // 4. Créer l'appel
    $stmt = db()->prepare(
        "INSERT INTO calls (caller_id, callee_id, conversation_id, status, call_type)
         VALUES (?, ?, ?, 'ringing', ?)"
    );
    $stmt->execute([$uid, $calleeId, $convId, $callType]);
    jsonResponse(['call_id' => (int)db()->lastInsertId()]);
    break;

// ─── POLLING APPEL ENTRANT ───────────────────────────────────────────────
case 'poll_incoming_call':
    $stmt = db()->prepare(
        "SELECT c.id, c.caller_id, c.conversation_id, c.created_at, c.call_type,
                u.name AS caller_name, u.avatar AS caller_avatar
         FROM calls c
         JOIN users u ON u.id = c.caller_id
         WHERE c.callee_id = ? AND c.status = 'ringing'
         ORDER BY c.created_at DESC LIMIT 1"
    );
    $stmt->execute([$uid]);
    jsonResponse(['call' => $stmt->fetch() ?: null]);
    break;

// ─── METTRE À JOUR STATUT ────────────────────────────────────────────────
case 'update_call_status':
    $callId   = (int)($body['call_id']  ?? 0);
    $status   = $body['status']          ?? '';
    $duration = (int)($body['duration'] ?? 0);

    $allowed = ['accepted','rejected','ended','missed','busy'];
    if (!in_array($status, $allowed)) jsonResponse(['error' => 'Statut invalide']);

    $stmt = db()->prepare("SELECT caller_id, callee_id FROM calls WHERE id=?");
    $stmt->execute([$callId]);
    $call = $stmt->fetch();
    if (!$call) jsonResponse(['success' => true]); // Déjà terminé — silencieux
    if ((int)$call['caller_id'] !== $uid && (int)$call['callee_id'] !== $uid)
        jsonResponse(['error' => 'Non autorisé']);

    if ($status === 'accepted') {
        db()->prepare("UPDATE calls SET status='accepted', started_at=NOW() WHERE id=?")->execute([$callId]);
    } else {
        db()->prepare(
            "UPDATE calls SET status=?, ended_at=NOW(), duration=? WHERE id=?"
        )->execute([$status, $duration, $callId]);
    }
    jsonResponse(['success' => true]);
    break;

// ─── ENVOYER SIGNAL ──────────────────────────────────────────────────────
case 'send_signal':
    $callId     = (int)($body['call_id']     ?? 0);
    $toUser     = (int)($body['to_user']     ?? 0);
    $signalType = $body['signal_type']        ?? '';
    $payload    = $body['payload']            ?? '{}';

    if (!in_array($signalType, ['offer','answer','ice_candidate','hangup']))
        jsonResponse(['error' => 'Type invalide']);

    // Vérifier que l'appel existe et nous concerne
    $stmt = db()->prepare("SELECT id FROM calls WHERE id=? AND (caller_id=? OR callee_id=?)");
    $stmt->execute([$callId, $uid, $uid]);
    if (!$stmt->fetch()) jsonResponse(['error' => 'Non autorisé']);

    db()->prepare(
        "INSERT INTO call_signals (call_id, from_user, to_user, signal_type, payload)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$callId, $uid, $toUser, $signalType, $payload]);
    jsonResponse(['success' => true]);
    break;

// ─── RÉCUPÉRER SIGNAUX PAR TYPE ──────────────────────────────────────────
case 'get_signals':
    $callId     = (int)($body['call_id']     ?? 0);
    $signalType = $body['signal_type']        ?? '';

    $stmt = db()->prepare(
        "SELECT * FROM call_signals
         WHERE call_id=? AND to_user=? AND signal_type=?
         ORDER BY created_at ASC LIMIT 5"
    );
    $stmt->execute([$callId, $uid, $signalType]);
    $signals = $stmt->fetchAll();
    if ($signals) {
        $ids = implode(',', array_map(fn($s) => (int)$s['id'], $signals));
        db()->prepare("UPDATE call_signals SET processed=1 WHERE id IN ($ids)")->execute();
    }
    jsonResponse(['signals' => $signals]);
    break;

// ─── POLLING SIGNAUX ─────────────────────────────────────────────────────
case 'poll_signals':
    $callId   = (int)($body['call_id']   ?? 0);
    $fromUser = (int)($body['from_user'] ?? 0);

    $stmt = db()->prepare(
        "SELECT * FROM call_signals
         WHERE call_id=? AND to_user=? AND from_user=? AND processed=0
         ORDER BY created_at ASC LIMIT 20"
    );
    $stmt->execute([$callId, $uid, $fromUser]);
    $signals = $stmt->fetchAll();
    if ($signals) {
        $ids = implode(',', array_map(fn($s) => (int)$s['id'], $signals));
        db()->prepare("UPDATE call_signals SET processed=1 WHERE id IN ($ids)")->execute();
    }
    jsonResponse(['signals' => $signals]);
    break;

// ─── HISTORIQUE ──────────────────────────────────────────────────────────
case 'get_call_history':
    $convId = (int)($body['conv_id'] ?? 0);
    $stmt   = db()->prepare(
        "SELECT c.*,
                uc.name AS caller_name, uc.avatar AS caller_avatar,
                ue.name AS callee_name, ue.avatar AS callee_avatar
         FROM calls c
         JOIN users uc ON uc.id = c.caller_id
         JOIN users ue ON ue.id = c.callee_id
         WHERE c.conversation_id=? AND (c.caller_id=? OR c.callee_id=?)
         ORDER BY c.created_at DESC LIMIT 50"
    );
    $stmt->execute([$convId, $uid, $uid]);
    jsonResponse(['calls' => $stmt->fetchAll()]);
    break;

default:
    jsonResponse(['error' => 'Action inconnue: ' . $action]);
}
