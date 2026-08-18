<?php
// ===================================================
// HAFATRA - api.php
// ===================================================
require_once 'config.php';
$uid = auth();

// Parse input
if (!empty($_POST['action'])) {
    $action = $_POST['action'];
    $body   = $_POST;
} else {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';
}

switch ($action) {

// ============================================================
// CONVERSATIONS
// ============================================================
case 'get_conversations':
    $tab       = $body['tab'] ?? 'chats';
    $statusMap = ['chats' => 'accepted', 'requests' => 'pending', 'spam' => 'spam'];
    $st        = $statusMap[$tab] ?? 'accepted';

    // Étape 1 : IDs des conversations de l'utilisateur
    // Pour 'chats' : inclure aussi les conv 'deleted' qui ont reçu un nouveau message
    // (un nouveau message APRÈS deleted_at fait réapparaître la conv)
    if ($st === 'accepted') {
        // Exclure les canaux (type='channel') — ils ont leur propre onglet
        $stmt = db()->prepare(
            "SELECT cp.conversation_id, cp.status, cp.deleted_at
             FROM conversation_participants cp
             JOIN conversations conv ON conv.id = cp.conversation_id
             WHERE cp.user_id = ?
               AND conv.type IN ('direct', 'group')
               AND (
                 cp.status = 'accepted'
                 OR (
                   cp.status = 'deleted'
                   AND cp.deleted_at IS NOT NULL
                   AND EXISTS (
                     SELECT 1 FROM messages m
                     WHERE m.conversation_id = cp.conversation_id
                       AND m.sender_id != ?
                       AND m.sent_at > cp.deleted_at
                       AND m.is_deleted = 0
                   )
                 )
               )"
        );
        $stmt->execute([$uid, $uid]);
    } else {
        $stmt = db()->prepare(
            "SELECT cp.conversation_id, cp.status, cp.deleted_at
             FROM conversation_participants cp
             JOIN conversations conv ON conv.id = cp.conversation_id
             WHERE cp.user_id = ? AND cp.status = ?
               AND conv.type IN ('direct', 'group')"
        );
        $stmt->execute([$uid, $st]);
    }
    $rows = $stmt->fetchAll();
    if (empty($rows)) jsonResponse(['convs' => []]);

    $results = [];
    foreach ($rows as $row) {
        $cid       = (int)$row['conversation_id'];
        $deletedAt = $row['deleted_at'] ?? null;
        $convStatus = $row['status'] ?? 'accepted';

        // Si la conv était supprimée mais a un nouveau message →
        // la remettre 'accepted' automatiquement
        if ($convStatus === 'deleted' && $deletedAt) {
            db()->prepare(
                "UPDATE conversation_participants SET status='accepted', deleted_at=NULL
                 WHERE conversation_id=? AND user_id=?"
            )->execute([$cid, $uid]);
            $deletedAt = null;
        }

        // Infos de base
        $s = db()->prepare("SELECT id, type, group_name, group_avatar FROM conversations WHERE id = ?");
        $s->execute([$cid]);
        $conv = $s->fetch();
        if (!$conv) continue;

        $isGroup   = ($conv['type'] === 'group');
        $name      = null;
        $avatar    = 'default.svg';
        $status    = null;
        $contactId = null;

        if ($isGroup) {
            $name   = $conv['group_name'];
            $avatar = $conv['group_avatar'] ?: 'default.svg';
        } else {
            $s2 = db()->prepare(
                "SELECT u.id, u.name, u.avatar, u.status
                 FROM conversation_participants cp
                 JOIN users u ON u.id = cp.user_id
                 WHERE cp.conversation_id = ? AND cp.user_id != ?
                 LIMIT 1"
            );
            $s2->execute([$cid, $uid]);
            $other = $s2->fetch();
            if ($other) {
                $contactId = (int)$other['id'];
                $status    = $other['status'];
                $avatar    = $other['avatar'] ?: 'default.svg';
                // Surnom éventuel
                $s3 = db()->prepare(
                    "SELECT nickname FROM contacts
                     WHERE user_id = ? AND contact_user_id = ? AND nickname IS NOT NULL
                     LIMIT 1"
                );
                $s3->execute([$uid, $other['id']]);
                $nick = $s3->fetchColumn();
                $name = $nick ?: $other['name'];
            }
        }

        // Dernier message (après deleted_at si applicable)
        if ($deletedAt) {
            $s4 = db()->prepare(
                "SELECT content, type, sent_at FROM messages
                 WHERE conversation_id = ? AND is_deleted = 0 AND sent_at > ?
                 ORDER BY sent_at DESC LIMIT 1"
            );
            $s4->execute([$cid, $deletedAt]);
        } else {
            $s4 = db()->prepare(
                "SELECT content, type, sent_at FROM messages
                 WHERE conversation_id = ? AND is_deleted = 0
                 ORDER BY sent_at DESC LIMIT 1"
            );
            $s4->execute([$cid]);
        }
        $lm = $s4->fetch();

        // Non lus (seulement après deleted_at)
        $unreadDateCond = $deletedAt ? "AND sent_at > '$deletedAt'" : "";
        $s5 = db()->prepare(
            "SELECT COUNT(*) FROM messages
             WHERE conversation_id = ?
               AND sender_id != ?
               AND is_deleted = 0
               $unreadDateCond
               AND id NOT IN (SELECT message_id FROM message_reads WHERE user_id = ?)"
        );
        $s5->execute([$cid, $uid, $uid]);
        $unread = (int)$s5->fetchColumn();

        $results[] = [
            'id'         => $cid,
            'type'       => $conv['type'],
            'name'       => $name,
            'avatar'     => $avatar,
            'status'     => $status,
            'contact_id' => $contactId,
            'last_msg'   => $lm['content'] ?? null,
            'last_type'  => $lm['type']    ?? null,
            'last_time'  => $lm['sent_at'] ?? null,
            'unread'     => $unread,
        ];
    }

    // Trier par date décroissante
    usort($results, function ($a, $b) {
        if (!$a['last_time'] && !$b['last_time']) return 0;
        if (!$a['last_time']) return 1;
        if (!$b['last_time']) return -1;
        return strcmp($b['last_time'], $a['last_time']);
    });

    jsonResponse(['convs' => $results]);
    break;

// ----------------------------------------------------------------
case 'get_request_count':
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM conversation_participants WHERE user_id = ? AND status = 'pending'"
    );
    $stmt->execute([$uid]);
    jsonResponse(['count' => (int)$stmt->fetchColumn()]);
    break;

// ----------------------------------------------------------------
case 'get_conv_info':
    $convId = (int)($body['conv_id'] ?? 0);
    if (!$convId) jsonResponse(['error' => 'conv_id manquant']);

    $stmt = db()->prepare("SELECT type, group_name, group_avatar, group_description FROM conversations WHERE id = ?");
    $stmt->execute([$convId]);
    $conv = $stmt->fetch();
    if (!$conv) jsonResponse(['error' => 'Conversation introuvable']);

    if ($conv['type'] === 'group') {
        $s2 = db()->prepare(
            "SELECT COUNT(*) FROM conversation_participants WHERE conversation_id = ? AND status = 'accepted'"
        );
        $s2->execute([$convId]);
        $cnt  = (int)$s2->fetchColumn();
        $info = [
            'name'   => $conv['group_name'],
            'avatar' => $conv['group_avatar'] ?: 'default.svg',
            'status' => 'group',
        ];
        jsonResponse(['info' => $info, 'my_status' => 'accepted', 'is_blocked' => false, 'member_count' => $cnt]);
    } else {
        $s2 = db()->prepare(
            "SELECT u.id, u.name, u.avatar, u.status, u.last_seen
             FROM conversation_participants cp
             JOIN users u ON u.id = cp.user_id
             WHERE cp.conversation_id = ? AND cp.user_id != ?
             LIMIT 1"
        );
        $s2->execute([$convId, $uid]);
        $info = $s2->fetch();

        $s3 = db()->prepare(
            "SELECT status FROM conversation_participants WHERE conversation_id = ? AND user_id = ?"
        );
        $s3->execute([$convId, $uid]);
        $rawStatus = $s3->fetchColumn();
        // Si 'deleted', traiter comme 'accepted' pour affichage mais sans input
        $myStatus = ($rawStatus === 'deleted') ? 'deleted' : $rawStatus;

        $isBlocked = false;
        if ($info) {
            $s4 = db()->prepare("SELECT id FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
            $s4->execute([$uid, $info['id']]);
            $isBlocked = (bool)$s4->fetch();
        }
        jsonResponse(['info' => $info, 'my_status' => $myStatus, 'is_blocked' => $isBlocked]);
    }
    break;

// ============================================================
// MESSAGES
// ============================================================
case 'get_messages':
    $convId = (int)($body['conv_id'] ?? 0);
    $stmt   = db()->prepare(
        "SELECT id, deleted_at FROM conversation_participants WHERE conversation_id = ? AND user_id = ?"
    );
    $stmt->execute([$convId, $uid]);
    $myPart = $stmt->fetch();
    if (!$myPart) jsonResponse(['error' => 'Unauthorized']);
    // Si deleted_at défini : ne montrer que les messages APRÈS cette date
    $afterDate = $myPart['deleted_at'] ?? null;
    jsonResponse(['messages' => fetchMessages($convId, null, null, $afterDate)]);
    break;

// ----------------------------------------------------------------
case 'get_new_messages':
    $convId  = (int)($body['conv_id']  ?? 0);
    $afterId = (int)($body['after_id'] ?? 0);
    // Vérifier deleted_at
    $stmt2 = db()->prepare("SELECT deleted_at FROM conversation_participants WHERE conversation_id=? AND user_id=?");
    $stmt2->execute([$convId, $uid]);
    $part2 = $stmt2->fetch();
    $afterDate2 = $part2['deleted_at'] ?? null;
    jsonResponse(['messages' => fetchMessages($convId, $afterId, null, $afterDate2)]);
    break;

// ----------------------------------------------------------------
case 'get_message':
    $msgId = (int)($body['msg_id'] ?? 0);
    $msgs  = fetchMessages(null, null, $msgId);
    jsonResponse(['message' => $msgs[0] ?? null]);
    break;

// ----------------------------------------------------------------
case 'send_message':
    $convId  = (int)($body['conv_id']  ?? 0);
    $content = trim($body['content']   ?? '');
    $replyTo = (int)($body['reply_to'] ?? 0) ?: null;
    if (!$content) jsonResponse(['error' => 'Message vide']);
    $permErr = checkSendPermission($convId, $uid);
    if ($permErr) jsonResponse(['error' => $permErr]);
    $stmt = db()->prepare(
        "INSERT INTO messages(conversation_id, sender_id, type, content, reply_to) VALUES(?, ?, 'text', ?, ?)"
    );
    $stmt->execute([$convId, $uid, $content, $replyTo]);
    $newMsgId = (int)db()->lastInsertId();
    // Restaurer uniquement si conv directe/groupe (pas canal)
    $typeCheck = db()->prepare("SELECT type FROM conversations WHERE id=?");
    $typeCheck->execute([$convId]);
    $convTypeRow = $typeCheck->fetchColumn();
    if ($convTypeRow !== 'channel') {
        db()->prepare(
            "UPDATE conversation_participants
             SET status = 'accepted', deleted_at = NULL
             WHERE conversation_id = ? AND user_id != ? AND status = 'deleted'"
        )->execute([$convId, $uid]);
    }
    $msgs = fetchMessages(null, null, $newMsgId);
    jsonResponse(['message' => $msgs[0] ?? null]);
    break;

// ----------------------------------------------------------------
case 'send_file':
    $convId  = (int)($_POST['conv_id']  ?? 0);
    $replyTo = (int)($_POST['reply_to'] ?? 0) ?: null;
    if (empty($_FILES['file'])) jsonResponse(['error' => 'No file']);
    $permErr = checkSendPermission($convId, $uid);
    if ($permErr) jsonResponse(['error' => $permErr]);
    $file    = $_FILES['file'];
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $imgExts = ['jpg','jpeg','png','gif','webp','heic'];
    $vidExts = ['mp4','webm','mov','avi','mkv'];

    if      (in_array($ext, $imgExts)) { $type = 'image'; $dir = 'images'; }
    elseif  (in_array($ext, $vidExts)) { $type = 'video'; $dir = 'videos'; }
    else                               { $type = 'file';  $dir = 'files';  }

    $fname = uniqid() . '_' . time() . '.' . $ext;
    $path  = UPLOAD_DIR . $dir . '/' . $fname;
    if (!is_dir(UPLOAD_DIR . $dir)) mkdir(UPLOAD_DIR . $dir, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $path)) jsonResponse(['error' => 'Upload failed']);

    $filePath = 'uploads/' . $dir . '/' . $fname;
    $stmt = db()->prepare(
        "INSERT INTO messages(conversation_id, sender_id, type, file_path, file_name, file_size, reply_to)
         VALUES(?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$convId, $uid, $type, $filePath, $file['name'], $file['size'], $replyTo]);
    $msgs = fetchMessages(null, null, (int)db()->lastInsertId());
    jsonResponse(['message' => $msgs[0] ?? null]);
    break;

// ----------------------------------------------------------------
case 'send_voice':
    $convId   = (int)($_POST['conv_id']  ?? 0);
    $duration = (int)($_POST['duration'] ?? 0);
    if (empty($_FILES['voice'])) jsonResponse(['error' => 'No file']);
    $permErr  = checkSendPermission($convId, $uid);
    if ($permErr) jsonResponse(['error' => $permErr]);

    $file  = $_FILES['voice'];
    // Détecter l'extension réelle du fichier audio
    $voiceMime = $_FILES['voice']['type'] ?? 'audio/webm';
    $voiceExt  = 'webm';
    if (strpos($voiceMime, 'ogg') !== false)  $voiceExt = 'ogg';
    elseif (strpos($voiceMime, 'mp4') !== false)  $voiceExt = 'mp4';
    elseif (strpos($voiceMime, 'mpeg') !== false) $voiceExt = 'mp3';
    $fname = 'voice_' . uniqid() . '_' . time() . '.' . $voiceExt;
    $path  = UPLOAD_DIR . 'audio/' . $fname;
    if (!is_dir(UPLOAD_DIR . 'audio')) mkdir(UPLOAD_DIR . 'audio', 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $path)) jsonResponse(['error' => 'Upload failed']);

    $filePath = 'uploads/audio/' . $fname;
    // Corriger durée si non fournie
    if ($duration <= 0) $duration = null;
    $stmt = db()->prepare(
        "INSERT INTO messages(conversation_id, sender_id, type, file_path, duration) VALUES(?, ?, 'voice', ?, ?)"
    );
    $stmt->execute([$convId, $uid, $filePath, $duration]);
    $msgs = fetchMessages(null, null, (int)db()->lastInsertId());
    jsonResponse(['message' => $msgs[0] ?? null]);
    break;

// ----------------------------------------------------------------
case 'delete_message':
    $msgId = (int)($body['msg_id'] ?? 0);
    db()->prepare("UPDATE messages SET is_deleted = 1 WHERE id = ? AND sender_id = ?")->execute([$msgId, $uid]);
    jsonResponse(['success' => true]);
    break;

// ----------------------------------------------------------------
case 'edit_message':
    $msgId   = (int)($body['msg_id']  ?? 0);
    $content = trim($body['content']  ?? '');
    if (!$content) jsonResponse(['error' => 'Vide']);
    db()->prepare(
        "UPDATE messages SET content = ?, is_edited = 1, edited_at = NOW()
         WHERE id = ? AND sender_id = ? AND type = 'text'"
    )->execute([$content, $msgId, $uid]);
    jsonResponse(['success' => true]);
    break;

// ----------------------------------------------------------------
case 'react_message':
    $msgId = (int)($body['msg_id'] ?? 0);
    $emoji = $body['emoji'] ?? '';
    $stmt  = db()->prepare("SELECT emoji FROM message_reactions WHERE message_id = ? AND user_id = ?");
    $stmt->execute([$msgId, $uid]);
    $cur = $stmt->fetchColumn();
    if ($cur === false) {
        db()->prepare("INSERT INTO message_reactions(message_id, user_id, emoji) VALUES(?, ?, ?)")->execute([$msgId, $uid, $emoji]);
    } elseif ($cur === $emoji) {
        db()->prepare("DELETE FROM message_reactions WHERE message_id = ? AND user_id = ?")->execute([$msgId, $uid]);
    } else {
        db()->prepare("UPDATE message_reactions SET emoji = ? WHERE message_id = ? AND user_id = ?")->execute([$emoji, $msgId, $uid]);
    }
    jsonResponse(['success' => true]);
    break;

// ----------------------------------------------------------------
case 'mark_read':
    $convId = (int)($body['conv_id'] ?? 0);
    db()->prepare(
        "INSERT IGNORE INTO message_reads(message_id, user_id)
         SELECT id, ? FROM messages WHERE conversation_id = ? AND sender_id != ?"
    )->execute([$uid, $convId, $uid]);
    jsonResponse(['success' => true]);
    break;

// ============================================================
// CONVERSATIONS MANAGEMENT
// ============================================================
case 'start_conversation':
    $phone    = trim($body['phone']    ?? '');
    $nickname = sanitize($body['nickname'] ?? '');
    if (!$phone) jsonResponse(['error' => 'Numéro requis']);

    // Trouver le contact
    $stmt = db()->prepare("SELECT id, name FROM users WHERE phone = ?");
    $stmt->execute([$phone]);
    $contact = $stmt->fetch();
    if (!$contact) jsonResponse(['error' => "Ce numéro n'est pas inscrit sur HAFATRA"]);
    if ((int)$contact['id'] === $uid) jsonResponse(['error' => 'Vous ne pouvez pas vous contacter']);

    // Vérifier blocage
    $stmt = db()->prepare("SELECT id FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
    $stmt->execute([$contact['id'], $uid]);
    if ($stmt->fetch()) jsonResponse(['error' => 'Impossible de contacter cet utilisateur']);

    // Chercher conv directe existante où les DEUX sont encore actifs (pas deleted)
    $stmt = db()->prepare(
        "SELECT cp1.conversation_id
         FROM conversation_participants cp1
         JOIN conversation_participants cp2
             ON cp2.conversation_id = cp1.conversation_id
             AND cp2.user_id = ?
             AND cp2.status NOT IN ('deleted','spam')
         WHERE cp1.user_id = ?
           AND cp1.status NOT IN ('deleted','spam')
           AND cp1.conversation_id IN (
               SELECT id FROM conversations WHERE type = 'direct'
           )
         LIMIT 1"
    );
    $stmt->execute([(int)$contact['id'], $uid]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        // Conv existante active — juste s'assurer qu'on est 'accepted'
        db()->prepare(
            "UPDATE conversation_participants SET status = 'accepted' WHERE conversation_id = ? AND user_id = ?"
        )->execute([$existing, $uid]);
        jsonResponse(['conv_id' => (int)$existing, 'contact_id' => (int)$contact['id']]);
    }

    // Pas de conv active → créer une nouvelle conversation propre
    db()->prepare("INSERT INTO conversations(type) VALUES('direct')")->execute();
    $convId = (int)db()->lastInsertId();
    db()->prepare(
        "INSERT INTO conversation_participants(conversation_id, user_id, status) VALUES(?, ?, 'accepted')"
    )->execute([$convId, $uid]);
    db()->prepare(
        "INSERT INTO conversation_participants(conversation_id, user_id, status) VALUES(?, ?, 'pending')"
    )->execute([$convId, (int)$contact['id']]);

    // Sauvegarder contact
    $stmt = db()->prepare("SELECT id FROM contacts WHERE user_id = ? AND contact_phone = ?");
    $stmt->execute([$uid, $phone]);
    if (!$stmt->fetch()) {
        db()->prepare(
            "INSERT INTO contacts(user_id, contact_phone, contact_user_id, nickname) VALUES(?, ?, ?, ?)"
        )->execute([$uid, $phone, (int)$contact['id'], $nickname ?: null]);
    }

    jsonResponse(['conv_id' => $convId, 'contact_id' => (int)$contact['id']]);
    break;

// ----------------------------------------------------------------
case 'update_conv_status':
    $convId = (int)($body['conv_id'] ?? 0);
    $st     = $body['status'] ?? '';
    if (!in_array($st, ['accepted', 'spam'])) jsonResponse(['error' => 'Statut invalide']);
    db()->prepare(
        "UPDATE conversation_participants SET status = ? WHERE conversation_id = ? AND user_id = ?"
    )->execute([$st, $convId, $uid]);
    jsonResponse(['success' => true]);
    break;

// ----------------------------------------------------------------
case 'block_user':
    $bid = (int)($body['blocked_id'] ?? 0);
    db()->prepare("INSERT IGNORE INTO blocks(blocker_id, blocked_id) VALUES(?, ?)")->execute([$uid, $bid]);
    jsonResponse(['success' => true]);
    break;

// ----------------------------------------------------------------
case 'unblock_user':
    $bid = (int)($body['blocked_id'] ?? 0);
    db()->prepare("DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?")->execute([$uid, $bid]);
    jsonResponse(['success' => true]);
    break;

// ----------------------------------------------------------------
case 'delete_conversation':
    $convId = (int)($body['conv_id'] ?? 0);
    // Soft delete : on reste participant mais status='deleted' + deleted_at=NOW()
    // L'autre personne peut continuer à écrire.
    // Quand on recevra un nouveau message, la conv réapparaîtra (statut repassera 'accepted')
    // et on ne verra QUE les messages APRÈS deleted_at.
    db()->prepare(
        "UPDATE conversation_participants
         SET status = 'deleted', deleted_at = NOW()
         WHERE conversation_id = ? AND user_id = ?"
    )->execute([$convId, $uid]);
    jsonResponse(['success' => true]);
    break;

// ============================================================
// GROUPS
// ============================================================
case 'create_group':
    $name    = sanitize($_POST['name']        ?? '');
    $desc    = sanitize($_POST['description'] ?? '');
    $members = json_decode($_POST['members']  ?? '[]', true);
    if (!$name) jsonResponse(['error' => 'Nom requis']);

    $groupAvatar = null;
    if (!empty($_FILES['avatar']['tmp_name'])) {
        $file  = $_FILES['avatar'];
        $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fname = 'grp_' . uniqid() . '.' . $ext;
        if (!is_dir(UPLOAD_DIR . 'avatars')) mkdir(UPLOAD_DIR . 'avatars', 0755, true);
        if (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . 'avatars/' . $fname)) {
            $groupAvatar = $fname;
        }
    }

    db()->prepare(
        "INSERT INTO conversations(type, group_name, group_avatar, group_description, group_created_by)
         VALUES('group', ?, ?, ?, ?)"
    )->execute([$name, $groupAvatar, $desc, $uid]);
    $convId = (int)db()->lastInsertId();

    db()->prepare(
        "INSERT INTO conversation_participants(conversation_id, user_id, status, role) VALUES(?, ?, 'accepted', 'admin')"
    )->execute([$convId, $uid]);

    if (is_array($members)) {
        foreach ($members as $memberId) {
            $memberId = (int)$memberId;
            if ($memberId && $memberId !== $uid) {
                db()->prepare(
                    "INSERT IGNORE INTO conversation_participants(conversation_id, user_id, status, role)
                     VALUES(?, ?, 'accepted', 'member')"
                )->execute([$convId, $memberId]);
            }
        }
    }

    $cu = currentUser();
    db()->prepare(
        "INSERT INTO messages(conversation_id, sender_id, type, content) VALUES(?, ?, 'system', ?)"
    )->execute([$convId, $uid, "Groupe créé par " . ($cu['name'] ?? '')]);

    jsonResponse(['conv_id' => $convId]);
    break;

// ----------------------------------------------------------------
case 'get_group_info':
    $convId = (int)($body['conv_id'] ?? 0);
    $stmt   = db()->prepare("SELECT * FROM conversations WHERE id = ? AND type IN ('group','channel')");
    $stmt->execute([$convId]);
    $info = $stmt->fetch();
    if (!$info) jsonResponse(['error' => 'Groupe introuvable']);

    $stmt = db()->prepare(
        "SELECT u.id, u.name, u.phone, u.avatar, cp.role,
                cp.banned_at, cp.ban_reason, cp.status
         FROM conversation_participants cp
         JOIN users u ON u.id = cp.user_id
         WHERE cp.conversation_id = ?
         ORDER BY
           FIELD(cp.role,'admin','moderator','member') ASC,
           u.name ASC"
    );
    $stmt->execute([$convId]);
    $members = $stmt->fetchAll();

    $stmt = db()->prepare(
        "SELECT role, status, banned_at FROM conversation_participants
         WHERE conversation_id = ? AND user_id = ?"
    );
    $stmt->execute([$convId, $uid]);
    $myPart  = $stmt->fetch();
    $myRole  = $myPart['role']   ?? 'member';
    $myStatus= $myPart['status'] ?? 'accepted';

    // Compter membres actifs
    $memberCount = count(array_filter($members, fn($m) => $m['status'] === 'accepted'));
    $bannedCount = count(array_filter($members, fn($m) => !is_null($m['banned_at'])));

    jsonResponse([
        'info'         => $info,
        'members'      => $members,
        'my_role'      => $myRole,
        'my_status'    => $myStatus,
        'member_count' => $memberCount,
        'banned_count' => $bannedCount,
    ]);
    break;

// ----------------------------------------------------------------
case 'set_member_role':
    // Admin peut promouvoir/rétrograder
    $convId   = (int)($body['conv_id']   ?? 0);
    $memberId = (int)($body['user_id']   ?? 0);
    $newRole  = $body['role']            ?? '';

    $allowed = ['member','moderator','admin'];
    if (!in_array($newRole, $allowed)) jsonResponse(['error' => 'Rôle invalide']);

    // Vérifier que l'appelant est admin
    $stmt = db()->prepare("SELECT role FROM conversation_participants WHERE conversation_id=? AND user_id=?");
    $stmt->execute([$convId, $uid]);
    $myRole = $stmt->fetchColumn();
    if ($myRole !== 'admin') jsonResponse(['error' => 'Seul un administrateur peut changer les rôles']);
    if ($memberId === $uid) jsonResponse(['error' => 'Vous ne pouvez pas changer votre propre rôle']);

    // Vérifier que la cible est dans le groupe
    $stmt->execute([$convId, $memberId]);
    if (!$stmt->fetchColumn()) jsonResponse(['error' => 'Membre introuvable']);

    db()->prepare("UPDATE conversation_participants SET role=? WHERE conversation_id=? AND user_id=?")
        ->execute([$newRole, $convId, $memberId]);

    // Message système
    $targetUser = db()->prepare("SELECT name FROM users WHERE id=?");
    $targetUser->execute([$memberId]);
    $targetName = $targetUser->fetchColumn();
    $cu = currentUser();
    $roleLabels = ['member'=>'Membre','moderator'=>'Modérateur','admin'=>'Administrateur'];
    db()->prepare("INSERT INTO messages(conversation_id,sender_id,type,content) VALUES(?,?,'system',?)")
        ->execute([$convId, $uid, "{$cu['name']} a promu $targetName en {$roleLabels[$newRole]}"]);

    jsonResponse(['success' => true]);
    break;

// ----------------------------------------------------------------
case 'ban_member':
    $convId    = (int)($body['conv_id']    ?? 0);
    $memberId  = (int)($body['user_id']    ?? 0);
    $reason    = sanitize($body['reason']  ?? '');
    $permanent = (bool)($body['permanent'] ?? false);

    // Admin ou modérateur peut bannir
    $stmt = db()->prepare("SELECT role FROM conversation_participants WHERE conversation_id=? AND user_id=?");
    $stmt->execute([$convId, $uid]);
    $myRole = $stmt->fetchColumn();
    if (!in_array($myRole, ['admin','moderator'])) jsonResponse(['error' => 'Non autorisé']);

    // Un modérateur ne peut pas bannir un admin
    $stmt->execute([$convId, $memberId]);
    $targetRole = $stmt->fetchColumn();
    if ($myRole === 'moderator' && $targetRole === 'admin') jsonResponse(['error' => 'Vous ne pouvez pas bannir un administrateur']);
    if ($memberId === $uid) jsonResponse(['error' => 'Vous ne pouvez pas vous bannir vous-même']);

    db()->prepare(
        "UPDATE conversation_participants
         SET status='spam', banned_at=NOW(), banned_by=?, ban_reason=?
         WHERE conversation_id=? AND user_id=?"
    )->execute([$uid, $reason, $convId, $memberId]);

    $targetUser = db()->prepare("SELECT name FROM users WHERE id=?");
    $targetUser->execute([$memberId]);
    $targetName = $targetUser->fetchColumn();
    $cu = currentUser();
    db()->prepare("INSERT INTO messages(conversation_id,sender_id,type,content) VALUES(?,?,'system',?)")
        ->execute([$convId, $uid, "{$cu['name']} a banni $targetName" . ($reason ? " : $reason" : "")]);

    jsonResponse(['success' => true]);
    break;

// ----------------------------------------------------------------
case 'unban_member':
    $convId   = (int)($body['conv_id']  ?? 0);
    $memberId = (int)($body['user_id']  ?? 0);

    $stmt = db()->prepare("SELECT role FROM conversation_participants WHERE conversation_id=? AND user_id=?");
    $stmt->execute([$convId, $uid]);
    if (!in_array($stmt->fetchColumn(), ['admin','moderator'])) jsonResponse(['error' => 'Non autorisé']);

    db()->prepare(
        "UPDATE conversation_participants
         SET status='accepted', banned_at=NULL, banned_by=NULL, ban_reason=NULL
         WHERE conversation_id=? AND user_id=?"
    )->execute([$convId, $memberId]);

    jsonResponse(['success' => true]);
    break;

// ----------------------------------------------------------------
case 'kick_member':
    $convId   = (int)($body['conv_id']  ?? 0);
    $memberId = (int)($body['user_id']  ?? 0);

    $stmt = db()->prepare("SELECT role FROM conversation_participants WHERE conversation_id=? AND user_id=?");
    $stmt->execute([$convId, $uid]);
    $myRole = $stmt->fetchColumn();
    if (!in_array($myRole, ['admin','moderator'])) jsonResponse(['error' => 'Non autorisé']);

    $stmt->execute([$convId, $memberId]);
    $targetRole = $stmt->fetchColumn();
    if ($myRole === 'moderator' && $targetRole === 'admin') jsonResponse(['error' => 'Non autorisé']);

    db()->prepare("DELETE FROM conversation_participants WHERE conversation_id=? AND user_id=?")
        ->execute([$convId, $memberId]);

    $targetUser = db()->prepare("SELECT name FROM users WHERE id=?");
    $targetUser->execute([$memberId]);
    $targetName = $targetUser->fetchColumn() ?: 'Membre';
    $cu = currentUser();
    db()->prepare("INSERT INTO messages(conversation_id,sender_id,type,content) VALUES(?,?,'system',?)")
        ->execute([$convId, $uid, "{$cu['name']} a exclu $targetName du groupe"]);

    jsonResponse(['success' => true]);
    break;

// ================================================================
// CANAUX
// ================================================================
case 'create_channel':
    $name        = sanitize($_POST['name']        ?? $body['name']        ?? '');
    $description = sanitize($_POST['description'] ?? $body['description'] ?? '');
    $isPublic    = (int)($body['public']           ?? 1);
    if (!$name) jsonResponse(['error' => 'Nom requis']);

    $channelAvatar = null;
    if (!empty($_FILES['avatar']['tmp_name'])) {
        $file  = $_FILES['avatar'];
        $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fname = 'ch_' . uniqid() . '.' . $ext;
        if (!is_dir(UPLOAD_DIR . 'avatars')) mkdir(UPLOAD_DIR . 'avatars', 0755, true);
        if (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . 'avatars/' . $fname)) $channelAvatar = $fname;
    }

    db()->prepare(
        "INSERT INTO conversations(type, group_name, group_avatar, group_description, group_created_by, channel_public)
         VALUES('channel', ?, ?, ?, ?, ?)"
    )->execute([$name, $channelAvatar, $description, $uid, $isPublic]);
    $channelId = (int)db()->lastInsertId();

    // Créateur = admin
    db()->prepare(
        "INSERT INTO conversation_participants(conversation_id,user_id,status,role) VALUES(?,?,'accepted','admin')"
    )->execute([$channelId, $uid]);

    db()->prepare("INSERT INTO messages(conversation_id,sender_id,type,content) VALUES(?,?,'system',?)")
        ->execute([$channelId, $uid, "Canal créé"]);

    jsonResponse(['conv_id' => $channelId, 'type' => 'channel']);
    break;

case 'get_channels':
    // Canaux publics + canaux où je suis abonné
    $stmt = db()->prepare("
        SELECT c.id, c.group_name as name, c.group_avatar as avatar,
               c.group_description as description, c.channel_public,
               c.created_at,
               (SELECT COUNT(*) FROM conversation_participants cp2
                WHERE cp2.conversation_id=c.id AND cp2.status='accepted') as sub_count,
               (SELECT 1 FROM conversation_participants cp3
                WHERE cp3.conversation_id=c.id AND cp3.user_id=? AND cp3.status='accepted') as subscribed,
               (SELECT role FROM conversation_participants cp4
                WHERE cp4.conversation_id=c.id AND cp4.user_id=?) as my_role
        FROM conversations c
        WHERE c.type='channel'
          AND (c.channel_public=1 OR EXISTS(
              SELECT 1 FROM conversation_participants cp5
              WHERE cp5.conversation_id=c.id AND cp5.user_id=? AND cp5.status='accepted'
          ))
        ORDER BY sub_count DESC
    ");
    $stmt->execute([$uid, $uid, $uid]);
    jsonResponse(['channels' => $stmt->fetchAll()]);
    break;

case 'subscribe_channel':
    $channelId = (int)($body['channel_id'] ?? 0);
    $stmt = db()->prepare("SELECT id,channel_public FROM conversations WHERE id=? AND type='channel'");
    $stmt->execute([$channelId]);
    $ch = $stmt->fetch();
    if (!$ch) jsonResponse(['error' => 'Canal introuvable']);

    db()->prepare(
        "INSERT IGNORE INTO conversation_participants(conversation_id,user_id,status,role) VALUES(?,?,'accepted','member')"
    )->execute([$channelId, $uid]);

    jsonResponse(['success' => true]);
    break;

case 'unsubscribe_channel':
    $channelId = (int)($body['channel_id'] ?? 0);
    // Vérifier qu'on n'est pas le dernier admin
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM conversation_participants WHERE conversation_id=? AND role='admin' AND status='accepted'"
    );
    $stmt->execute([$channelId]);
    $adminCount = (int)$stmt->fetchColumn();

    $stmt = db()->prepare("SELECT role FROM conversation_participants WHERE conversation_id=? AND user_id=?");
    $stmt->execute([$channelId, $uid]);
    $myRole = $stmt->fetchColumn();

    if ($myRole === 'admin' && $adminCount <= 1) {
        jsonResponse(['error' => 'Vous êtes le seul administrateur. Promouvez un autre membre avant de partir.']);
    }

    db()->prepare("DELETE FROM conversation_participants WHERE conversation_id=? AND user_id=?")
        ->execute([$channelId, $uid]);
    jsonResponse(['success' => true]);
    break;

case 'get_channel_info':
    $channelId = (int)($body['channel_id'] ?? $body['conv_id'] ?? 0);
    $stmt = db()->prepare("SELECT * FROM conversations WHERE id=? AND type='channel'");
    $stmt->execute([$channelId]);
    $info = $stmt->fetch();
    if (!$info) jsonResponse(['error' => 'Canal introuvable']);

    $stmt = db()->prepare(
        "SELECT u.id, u.name, u.avatar, cp.role
         FROM conversation_participants cp
         JOIN users u ON u.id=cp.user_id
         WHERE cp.conversation_id=? AND cp.status='accepted'
         ORDER BY FIELD(cp.role,'admin','moderator','member')"
    );
    $stmt->execute([$channelId]);
    $members = $stmt->fetchAll();

    $stmt = db()->prepare(
        "SELECT role FROM conversation_participants WHERE conversation_id=? AND user_id=?"
    );
    $stmt->execute([$channelId, $uid]);
    $myRole = $stmt->fetchColumn();

    jsonResponse([
        'info'         => $info,
        'members'      => $members,
        'my_role'      => $myRole ?: null,
        'member_count' => count($members),
    ]);
    break;

// ----------------------------------------------------------------
case 'post_to_channel':
    // Seuls admin/moderator peuvent poster dans un canal (mode Telegram)
    $convId  = (int)($body['conv_id']  ?? 0);
    $content = trim($body['content']   ?? '');
    $replyTo = (int)($body['reply_to'] ?? 0) ?: null;
    if (!$content) jsonResponse(['error' => 'Message vide']);

    $stmt = db()->prepare("SELECT type FROM conversations WHERE id=?");
    $stmt->execute([$convId]);
    $convType = $stmt->fetchColumn();

    if ($convType === 'channel') {
        $stmt2 = db()->prepare("SELECT role FROM conversation_participants WHERE conversation_id=? AND user_id=?");
        $stmt2->execute([$convId, $uid]);
        $role = $stmt2->fetchColumn();
        if (!in_array($role, ['admin','moderator'])) {
            jsonResponse(['error' => 'Seuls les administrateurs peuvent poster dans un canal']);
        }
    }

    $stmt = db()->prepare(
        "INSERT INTO messages(conversation_id,sender_id,type,content,reply_to) VALUES(?,?,'text',?,?)"
    );
    $stmt->execute([$convId, $uid, $content, $replyTo]);
    $msgs = fetchMessages(null, null, (int)db()->lastInsertId());
    jsonResponse(['message' => $msgs[0] ?? null]);
    break;



// ----------------------------------------------------------------
case 'leave_group':
    $convId = (int)($body['conv_id'] ?? 0);
    db()->prepare(
        "UPDATE conversation_participants SET status = 'spam' WHERE conversation_id = ? AND user_id = ?"
    )->execute([$convId, $uid]);
    $cu = currentUser();
    db()->prepare(
        "INSERT INTO messages(conversation_id, sender_id, type, content) VALUES(?, ?, 'system', ?)"
    )->execute([$convId, $uid, ($cu['name'] ?? '') . " a quitté le groupe"]);
    jsonResponse(['success' => true]);
    break;

// ----------------------------------------------------------------
case 'remove_group_member':
    // Alias de kick_member pour rétrocompatibilité
    $convId   = (int)($body['conv_id']  ?? 0);
    $memberId = (int)($body['user_id']  ?? 0);
    $stmt     = db()->prepare("SELECT role FROM conversation_participants WHERE conversation_id=? AND user_id=?");
    $stmt->execute([$convId, $uid]);
    if (!in_array($stmt->fetchColumn(), ['admin','moderator'])) jsonResponse(['error' => 'Non autorisé']);
    db()->prepare("DELETE FROM conversation_participants WHERE conversation_id=? AND user_id=?")->execute([$convId, $memberId]);
    jsonResponse(['success' => true]);
    break;

// ----------------------------------------------------------------
case 'add_group_member':
    $convId = (int)($body['conv_id'] ?? 0);
    $phone  = trim($body['phone']    ?? '');
    $stmt   = db()->prepare(
        "SELECT role FROM conversation_participants WHERE conversation_id = ? AND user_id = ?"
    );
    $stmt->execute([$convId, $uid]);
    if ($stmt->fetchColumn() !== 'admin') jsonResponse(['error' => 'Non autorisé']);

    $stmt = db()->prepare("SELECT id FROM users WHERE phone = ?");
    $stmt->execute([$phone]);
    $user = $stmt->fetch();
    if (!$user) jsonResponse(['error' => "Numéro non trouvé"]);
    db()->prepare(
        "INSERT IGNORE INTO conversation_participants(conversation_id, user_id, status, role)
         VALUES(?, ?, 'accepted', 'member')"
    )->execute([$convId, $user['id']]);
    jsonResponse(['success' => true]);
    break;

// ============================================================
// STATUS
// ============================================================
case 'get_statuses':
    $cu = currentUser();
    $stmt = db()->prepare("SELECT COUNT(*) FROM statuses WHERE user_id = ? AND expires_at > NOW()");
    $stmt->execute([$uid]);
    $myCount = (int)$stmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT s.id, s.type, s.content, s.file_path, s.bg_color, s.created_at,
                u.name, u.avatar, u.id as user_id,
                (SELECT COUNT(*) FROM status_views sv  WHERE sv.status_id  = s.id) as views,
                (SELECT COUNT(*) FROM status_views sv2 WHERE sv2.status_id = s.id AND sv2.viewer_id = ?) as seen
         FROM statuses s
         JOIN users u ON u.id = s.user_id
         WHERE s.user_id != ? AND s.expires_at > NOW()
           AND s.user_id IN (
               SELECT cp2.user_id
               FROM conversation_participants cp
               JOIN conversation_participants cp2
                   ON cp2.conversation_id = cp.conversation_id AND cp2.user_id != ?
               WHERE cp.user_id = ? AND cp.status = 'accepted'
           )
         ORDER BY seen ASC, s.created_at DESC"
    );
    $stmt->execute([$uid, $uid, $uid, $uid]);
    $statuses = $stmt->fetchAll();

    $userStatuses = [];
    foreach ($statuses as $s) {
        if (!isset($userStatuses[$s['user_id']])) $userStatuses[$s['user_id']] = $s;
    }
    jsonResponse([
        'statuses'    => array_values($userStatuses),
        'my_statuses' => $myCount,
        'my_avatar'   => $cu['avatar'],
        'my_name'     => $cu['name'],
    ]);
    break;

// ----------------------------------------------------------------
case 'get_user_statuses':
    $userId = (int)($body['user_id'] ?? 0);
    $stmt   = db()->prepare(
        "SELECT s.*, u.name as user_name, u.avatar as user_avatar,
                (SELECT COUNT(*) FROM status_views sv WHERE sv.status_id = s.id) as views
         FROM statuses s
         JOIN users u ON u.id = s.user_id
         WHERE s.user_id = ? AND s.expires_at > NOW()
         ORDER BY s.created_at ASC"
    );
    $stmt->execute([$userId]);
    jsonResponse(['statuses' => $stmt->fetchAll()]);
    break;

// ----------------------------------------------------------------
case 'publish_status':
    $type    = $_POST['type']    ?? 'text';
    $content = sanitize($_POST['content'] ?? '');
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

    if ($type === 'text') {
        $bgColor   = $_POST['bg_color']   ?? '#1DA1F2';
        $fontStyle = $_POST['font_style'] ?? 'normal';
        db()->prepare(
            "INSERT INTO statuses(user_id, type, content, bg_color, font_style, expires_at)
             VALUES(?, 'text', ?, ?, ?, ?)"
        )->execute([$uid, $content, $bgColor, $fontStyle, $expires]);
    } else {
        if (empty($_FILES['media']['tmp_name'])) jsonResponse(['error' => 'Fichier requis']);
        $file  = $_FILES['media'];
        $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $dir   = ($type === 'video') ? 'videos' : 'images';
        $fname = 'status_' . uniqid() . '.' . $ext;
        $path  = UPLOAD_DIR . $dir . '/' . $fname;
        if (!is_dir(UPLOAD_DIR . $dir)) mkdir(UPLOAD_DIR . $dir, 0755, true);
        if (!move_uploaded_file($file['tmp_name'], $path)) jsonResponse(['error' => 'Upload failed']);
        db()->prepare(
            "INSERT INTO statuses(user_id, type, content, file_path, expires_at) VALUES(?, ?, ?, ?, ?)"
        )->execute([$uid, $type, $content, 'uploads/' . $dir . '/' . $fname, $expires]);
    }
    jsonResponse(['success' => true]);
    break;

// ----------------------------------------------------------------
case 'mark_status_viewed':
    $statusId = (int)($body['status_id'] ?? 0);
    db()->prepare("INSERT IGNORE INTO status_views(status_id, viewer_id) VALUES(?, ?)")->execute([$statusId, $uid]);
    jsonResponse(['success' => true]);
    break;

// ============================================================
// PROFILE
// ============================================================
case 'update_profile':
    $name = sanitize($body['name'] ?? '');
    $bio  = sanitize($body['bio']  ?? '');
    if (!$name) jsonResponse(['error' => 'Nom requis']);
    db()->prepare("UPDATE users SET name = ?, bio = ? WHERE id = ?")->execute([$name, $bio, $uid]);
    jsonResponse(['success' => true]);
    break;

// ----------------------------------------------------------------
case 'upload_avatar':
    if (empty($_FILES['avatar']['tmp_name'])) jsonResponse(['error' => 'No file']);
    $file = $_FILES['avatar'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) jsonResponse(['error' => 'Format non supporté']);
    $fname = 'av_' . $uid . '_' . time() . '.' . $ext;
    if (!is_dir(UPLOAD_DIR . 'avatars')) mkdir(UPLOAD_DIR . 'avatars', 0755, true);
    if (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . 'avatars/' . $fname)) {
        db()->prepare("UPDATE users SET avatar = ? WHERE id = ?")->execute([$fname, $uid]);
        jsonResponse(['avatar' => $fname]);
    }
    jsonResponse(['error' => 'Upload failed']);
    break;

// ----------------------------------------------------------------
case 'toggle_theme':
    db()->prepare("UPDATE users SET dark_mode = 1 - dark_mode WHERE id = ?")->execute([$uid]);
    jsonResponse(['success' => true]);
    break;

// ----------------------------------------------------------------
case 'lookup_phone':
    $phone = trim($body['phone'] ?? '');
    if (!$phone) jsonResponse(['not_found' => true]);
    $stmt  = db()->prepare("SELECT id, name, avatar, phone FROM users WHERE phone = ?");
    $stmt->execute([$phone]);
    $user  = $stmt->fetch();
    if ($user && (int)$user['id'] !== $uid) jsonResponse(['user' => $user]);
    else jsonResponse(['not_found' => true]);
    break;


// ============================================================
// SIGNALEMENTS
// ============================================================
case 'report_user':
    $reportedId  = (int)($body['reported_id']  ?? 0);
    $reason      = $body['reason']              ?? 'other';
    $description = sanitize($body['description'] ?? '');
    $convId2     = (int)($body['conv_id']       ?? 0) ?: null;
    $msgId2      = (int)($body['message_id']    ?? 0) ?: null;

    $allowed = ['spam','harassment','inappropriate','fake','other'];
    if (!in_array($reason, $allowed)) jsonResponse(['error' => 'Raison invalide']);
    if (!$reportedId || $reportedId === $uid) jsonResponse(['error' => 'Signalement invalide']);

    // Vérifier que l'utilisateur signalé existe
    $stmt = db()->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$reportedId]);
    if (!$stmt->fetch()) jsonResponse(['error' => 'Utilisateur introuvable']);

    // Anti-spam : max 3 signalements du même user par 24h
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM reports
         WHERE reporter_id = ? AND reported_user_id = ?
           AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    $stmt->execute([$uid, $reportedId]);
    if ((int)$stmt->fetchColumn() >= 3) jsonResponse(['error' => 'Vous avez déjà signalé cet utilisateur récemment']);

    $stmt = db()->prepare(
        "INSERT INTO reports(reporter_id, reported_user_id, conversation_id, message_id, reason, description)
         VALUES(?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$uid, $reportedId, $convId2, $msgId2, $reason, $description]);
    jsonResponse(['success' => true, 'message' => 'Signalement envoyé. Notre équipe va examiner ce contenu.']);
    break;

case 'report_problem':
    $reason      = sanitize($body['reason']      ?? '');
    $description = sanitize($body['description'] ?? '');
    if (!$reason || !$description) jsonResponse(['error' => 'Remplissez tous les champs']);
    if (strlen($description) < 10) jsonResponse(['error' => 'Description trop courte (min 10 caractères)']);

    $stmt = db()->prepare(
        "INSERT INTO reports(reporter_id, reason, description)
         VALUES(?, 'other', ?)"
    );
    $stmt->execute([$uid, "PROBLÈME TECHNIQUE: $reason

$description"]);
    jsonResponse(['success' => true, 'message' => 'Rapport envoyé. Merci pour votre retour !']);
    break;

// ============================================================
// PARAMÈTRES / COMPTE
// ============================================================
case 'change_password':
    $oldPwd = $body['old_password'] ?? '';
    $newPwd = $body['new_password'] ?? '';
    $confPwd= $body['confirm_password'] ?? '';

    if (!$oldPwd || !$newPwd || !$confPwd) jsonResponse(['error' => 'Tous les champs sont requis']);
    if (strlen($newPwd) < 6) jsonResponse(['error' => 'Le nouveau mot de passe doit faire au moins 6 caractères']);
    if ($newPwd !== $confPwd) jsonResponse(['error' => 'Les mots de passe ne correspondent pas']);

    $stmt = db()->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!password_verify($oldPwd, $user['password_hash'])) {
        jsonResponse(['error' => 'Mot de passe actuel incorrect']);
    }
    if (password_verify($newPwd, $user['password_hash'])) {
        jsonResponse(['error' => 'Le nouveau mot de passe doit être différent de l\'ancien']);
    }

    $newHash = password_hash($newPwd, PASSWORD_DEFAULT);
    db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $uid]);
    jsonResponse(['success' => true, 'message' => 'Mot de passe changé avec succès']);
    break;

case 'get_settings':
    $cu = currentUser();
    // Stats compte
    $stmt = db()->prepare("SELECT COUNT(*) FROM messages WHERE sender_id = ?");
    $stmt->execute([$uid]);
    $msgCount = (int)$stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COUNT(*) FROM conversation_participants WHERE user_id = ? AND status = 'accepted'");
    $stmt->execute([$uid]);
    $convCount = (int)$stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COUNT(*) FROM blocks WHERE blocker_id = ?");
    $stmt->execute([$uid]);
    $blockCount = (int)$stmt->fetchColumn();

    jsonResponse([
        'user'        => [
            'name'       => $cu['name'],
            'phone'      => $cu['phone'],
            'bio'        => $cu['bio'],
            'avatar'     => $cu['avatar'],
            'dark_mode'  => (bool)$cu['dark_mode'],
            'created_at' => $cu['created_at'],
        ],
        'stats'       => [
            'messages'      => $msgCount,
            'conversations' => $convCount,
            'blocked'       => $blockCount,
        ],
    ]);
    break;

case 'delete_account':
    $pwd = $body['password'] ?? '';
    if (!$pwd) jsonResponse(['error' => 'Mot de passe requis']);

    $stmt = db()->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!password_verify($pwd, $user['password_hash'])) {
        jsonResponse(['error' => 'Mot de passe incorrect']);
    }

    // Anonymiser le compte (RGPD) plutôt que supprimer
    $anon = 'Utilisateur supprimé';
    db()->prepare("UPDATE users SET name=?, bio=NULL, avatar='default.svg', phone=CONCAT('deleted_',id,'_',UNIX_TIMESTAMP()), password_hash='deleted' WHERE id=?")->execute([$anon, $uid]);
    // Supprimer messages personnels
    db()->prepare("UPDATE messages SET content='[Message supprimé]', is_deleted=1 WHERE sender_id=?")->execute([$uid]);
    // Détruire la session
    session_destroy();
    jsonResponse(['success' => true, 'redirect' => 'login.php']);
    break;

case 'get_blocked_users':
    $stmt = db()->prepare(
        "SELECT u.id, u.name, u.avatar, u.phone, b.created_at as blocked_at
         FROM blocks b
         JOIN users u ON u.id = b.blocked_id
         WHERE b.blocker_id = ?
         ORDER BY b.created_at DESC"
    );
    $stmt->execute([$uid]);
    jsonResponse(['blocked' => $stmt->fetchAll()]);
    break;

case 'update_notifications':
    // Stocké en session/cookie côté client — juste retourner succès
    jsonResponse(['success' => true]);
    break;


// ================================================================
// SUPPRIMER GROUPE
// ================================================================
case 'delete_group':
    $convId = (int)($body['conv_id'] ?? 0);
    $stmt = db()->prepare("SELECT role FROM conversation_participants WHERE conversation_id=? AND user_id=?");
    $stmt->execute([$convId, $uid]);
    if ($stmt->fetchColumn() !== 'admin') jsonResponse(['error' => 'Seul l\'admin peut supprimer le groupe']);
    // Supprimer messages, participants, puis la conv
    db()->prepare("DELETE FROM messages WHERE conversation_id=?")->execute([$convId]);
    db()->prepare("DELETE FROM conversation_participants WHERE conversation_id=?")->execute([$convId]);
    db()->prepare("DELETE FROM conversations WHERE id=?")->execute([$convId]);
    jsonResponse(['success' => true]);
    break;

// ================================================================
// PROFIL UTILISATEUR
// ================================================================
case 'get_user_profile':
    $userId = (int)($body['user_id'] ?? 0);
    if (!$userId) jsonResponse(['error' => 'user_id requis']);
    $stmt = db()->prepare("SELECT id, name, bio, avatar, status, last_seen, created_at, phone FROM users WHERE id=?");
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
    if (!$profile) jsonResponse(['error' => 'Utilisateur introuvable']);

    // Nb de convs en commun
    $stmt2 = db()->prepare("
        SELECT COUNT(DISTINCT cp1.conversation_id) FROM conversation_participants cp1
        JOIN conversation_participants cp2 ON cp2.conversation_id=cp1.conversation_id AND cp2.user_id=?
        WHERE cp1.user_id=?
    ");
    $stmt2->execute([$userId, $uid]);
    $commonConvs = (int)$stmt2->fetchColumn();

    // Est-ce bloqué ?
    $stmt3 = db()->prepare("SELECT id FROM blocks WHERE blocker_id=? AND blocked_id=?");
    $stmt3->execute([$uid, $userId]);
    $isBlocked = (bool)$stmt3->fetch();

    // Conv directe existante ?
    $stmt4 = db()->prepare("
        SELECT cp1.conversation_id FROM conversation_participants cp1
        JOIN conversation_participants cp2 ON cp2.conversation_id=cp1.conversation_id AND cp2.user_id=?
        JOIN conversations c ON c.id=cp1.conversation_id AND c.type='direct'
        WHERE cp1.user_id=?
        LIMIT 1
    ");
    $stmt4->execute([$userId, $uid]);
    $existingConv = $stmt4->fetchColumn();

    // Masquer le téléphone sauf si on a une conv
    if (!$existingConv) unset($profile['phone']);

    jsonResponse([
        'profile'       => $profile,
        'is_blocked'    => $isBlocked,
        'common_convs'  => $commonConvs,
        'existing_conv' => $existingConv ? (int)$existingConv : null,
    ]);
    break;

// ================================================================
// RETIRER DU SPAM
// ================================================================
case 'unspam_conversation':
    $convId = (int)($body['conv_id'] ?? 0);
    db()->prepare("UPDATE conversation_participants SET status='accepted' WHERE conversation_id=? AND user_id=?")
        ->execute([$convId, $uid]);
    jsonResponse(['success' => true]);
    break;

// ================================================================
// CRÉER CANAL (POST JSON)
// ================================================================
case 'create_channel_json':
    $name        = sanitize($body['name']        ?? '');
    $description = sanitize($body['description'] ?? '');
    $isPublic    = (int)($body['public']          ?? 1);
    if (!$name) jsonResponse(['error' => 'Nom requis']);

    db()->prepare(
        "INSERT INTO conversations(type, group_name, group_description, group_created_by, channel_public)
         VALUES('channel', ?, ?, ?, ?)"
    )->execute([$name, $description, $uid, $isPublic]);
    $channelId = (int)db()->lastInsertId();
    db()->prepare("INSERT INTO conversation_participants(conversation_id,user_id,status,role) VALUES(?,?,'accepted','admin')")->execute([$channelId, $uid]);
    db()->prepare("INSERT INTO messages(conversation_id,sender_id,type,content) VALUES(?,?,'system','Canal créé')")->execute([$channelId, $uid]);
    jsonResponse(['conv_id' => $channelId, 'type' => 'channel']);
    break;

// ----------------------------------------------------------------
default:
    jsonResponse(['error' => 'Action inconnue : ' . $action]);
}


// ============================================================
// HELPER : checkSendPermission
// Vérifie que l'expéditeur ($uid) peut envoyer dans $convId
// Retourne null si OK, sinon un message d'erreur
// ============================================================
function checkSendPermission($convId, $uid) {
    $stmt = db()->prepare("SELECT status FROM conversation_participants WHERE conversation_id = ? AND user_id = ?");
    $stmt->execute([$convId, $uid]);
    $myStatus = $stmt->fetchColumn();
    if (!$myStatus) return 'Non autorisé';
    if ($myStatus === 'deleted') return 'Vous avez supprimé cette conversation';
    if ($myStatus === 'spam')    return 'Cette conversation est marquée comme spam';

    $stmt = db()->prepare("SELECT type FROM conversations WHERE id = ?");
    $stmt->execute([$convId]);
    $conv = $stmt->fetch();

    // Canal : vérifier que l'expéditeur est admin ou modérateur
    if ($conv && $conv['type'] === 'channel') {
        $stmt2 = db()->prepare("SELECT role FROM conversation_participants WHERE conversation_id=? AND user_id=?");
        $stmt2->execute([$convId, $uid]);
        $role = $stmt2->fetchColumn();
        if (!in_array($role, ['admin','moderator'])) return 'Seuls les administrateurs peuvent poster dans un canal';
        return null; // OK pour canal
    }

    // Pour conv directe uniquement
    if ($conv && $conv['type'] === 'direct') {
        // Trouver le destinataire
        $stmt2 = db()->prepare(
            "SELECT user_id, status FROM conversation_participants WHERE conversation_id = ? AND user_id != ?"
        );
        $stmt2->execute([$convId, $uid]);
        $recipient = $stmt2->fetch();
        if ($recipient) {
            $recipientId = $recipient['user_id'];
            // Destinataire a supprimé → il ne recevra pas, mais on peut quand même écrire
            // (comportement WhatsApp — message envoyé, pas reçu)
            // Par contre si bloqué → refus total
            $stmt3 = db()->prepare("SELECT id FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
            $stmt3->execute([$recipientId, $uid]);
            if ($stmt3->fetch()) return 'Vous avez été bloqué par ce contact';
            $stmt4 = db()->prepare("SELECT id FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
            $stmt4->execute([$uid, $recipientId]);
            if ($stmt4->fetch()) return 'Vous avez bloqué ce contact';
        }
    }
    return null; // OK
}

// ============================================================
// HELPER : fetchMessages
// ============================================================
function fetchMessages($convId = null, $afterId = null, $specificId = null, $afterDate = null) {
    $where  = '';
    $params = [];

    if ($specificId) {
        $where  = 'WHERE m.id = ?';
        $params = [(int)$specificId];
    } elseif ($convId) {
        $where  = 'WHERE m.conversation_id = ?';
        $params = [(int)$convId];
        if ($afterId) {
            $where   .= ' AND m.id > ?';
            $params[] = (int)$afterId;
        }
        // Masquer les messages AVANT la date de suppression (historique effacé)
        if ($afterDate) {
            $where   .= ' AND m.sent_at > ?';
            $params[] = $afterDate;
        }
    }

    $stmt = db()->prepare(
        "SELECT m.*,
                u.name   as sender_name,
                u.avatar as sender_avatar,
                rm.content as reply_content,
                rm.type    as reply_type,
                ru.name    as reply_sender
         FROM messages m
         JOIN users u ON u.id = m.sender_id
         LEFT JOIN messages rm ON rm.id = m.reply_to
         LEFT JOIN users ru ON ru.id = rm.sender_id
         {$where}
         ORDER BY m.sent_at ASC
         LIMIT 300"
    );
    $stmt->execute($params);
    $msgs = $stmt->fetchAll();

    foreach ($msgs as &$msg) {
        $rs = db()->prepare("SELECT user_id, emoji FROM message_reactions WHERE message_id = ?");
        $rs->execute([$msg['id']]);
        $msg['reactions'] = $rs->fetchAll();
    }
    return $msgs;
}
