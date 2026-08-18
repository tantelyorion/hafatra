<?php
require_once 'config.php';
$user = currentUser();
db()->prepare("UPDATE users SET status='online', last_seen=NOW() WHERE id=?")->execute([$user['id']]);
db()->prepare("DELETE FROM statuses WHERE expires_at < NOW()")->execute();
?>
<!DOCTYPE html>
<html lang="fr" data-theme="<?= $user['dark_mode'] ? 'dark' : 'light' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HAFATRA</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="icon" type="image/svg+xml" href="res/logo.svg">
<link rel="stylesheet" href="css/app.css">
<style>
/* ===== VOICE PLAYER ===== */
.msg-voice {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 4px 0;
    min-width: 200px;
}
.voice-play-btn {
    width: 38px; height: 38px;
    border-radius: 50%;
    background: var(--blue, #1DA1F2);
    color: #fff;
    border: none;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
    transition: transform .15s, opacity .15s;
}
.voice-play-btn:hover   { opacity: .85; transform: scale(1.05); }
.msg-voice.playing .voice-play-btn { background: var(--blue-dark, #0d8fd8); }
.voice-waveform {
    display: flex; align-items: center; gap: 2px; flex: 1; height: 30px;
}
.voice-bar {
    width: 3px; border-radius: 2px;
    background: var(--blue, #1DA1F2);
    opacity: .5;
    transition: opacity .2s;
    min-height: 4px;
}
.msg-voice.playing .voice-bar { opacity: .9; }
.voice-duration {
    font-size: 12px;
    color: var(--text2, #666);
    min-width: 32px;
    text-align: right;
    flex-shrink: 0;
}

/* ===== RÉACTIONS ===== */
.msg-react-btn {
    background: none; border: none; cursor: pointer;
    color: var(--text3, #999);
    font-size: 16px;
    padding: 4px 6px;
    border-radius: 50%;
    opacity: 0;
    transition: opacity .15s, color .15s;
    align-self: center;
    flex-shrink: 0;
}
.msg-bubble-wrap { display: flex; align-items: flex-end; gap: 4px; }
.msg-wrap.out .msg-bubble-wrap { flex-direction: row-reverse; }
.msg-bubble-wrap:hover .msg-react-btn { opacity: 1; }
.msg-react-btn:hover { color: var(--blue, #1DA1F2); }

.msg-reactions {
    display: flex; flex-wrap: wrap; gap: 4px;
    margin-top: 4px;
}
.reaction-badge {
    display: inline-flex; align-items: center; gap: 3px;
    background: var(--bg2, #f0f2f5);
    border: 1.5px solid var(--border, #e0e0e0);
    border-radius: 12px;
    padding: 2px 8px;
    font-size: 14px;
    cursor: pointer;
    transition: background .15s, transform .1s;
    user-select: none;
}
.reaction-badge:hover   { background: var(--bg3, #e4e6e9); transform: scale(1.08); }
.reaction-badge.mine    { border-color: var(--blue, #1DA1F2); background: rgba(29,161,242,.1); }
.reaction-count { font-size: 11px; font-weight: 600; color: var(--text2, #666); }

#reactionPicker {
    position: fixed;
    background: var(--bg, #fff);
    border: 1px solid var(--border, #ddd);
    border-radius: 24px;
    padding: 8px 12px;
    display: none;
    gap: 6px;
    align-items: center;
    box-shadow: 0 8px 24px rgba(0,0,0,.15);
    z-index: 2000;
}
#reactionPicker button {
    background: none; border: none; cursor: pointer;
    font-size: 22px; padding: 4px;
    border-radius: 50%;
    transition: transform .15s;
    line-height: 1;
}
#reactionPicker button:hover { transform: scale(1.3); }

/* ===== REPLY QUOTE ===== */
.reply-quote {
    background: rgba(0,0,0,.07);
    border-left: 3px solid var(--blue, #1DA1F2);
    border-radius: 4px;
    padding: 5px 10px;
    margin-bottom: 6px;
    cursor: pointer;
    font-size: 13px;
}
.reply-quote-sender { font-weight: 600; color: var(--blue, #1DA1F2); font-size: 12px; margin-bottom: 2px; }
.reply-quote-text   { opacity: .8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 240px; }

/* ===== CONTEXT MENU ===== */
.context-menu {
    position: fixed;
    background: var(--bg, #fff);
    border: 1px solid var(--border, #ddd);
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0,0,0,.15);
    z-index: 3000;
    overflow: hidden;
    min-width: 170px;
    display: none;
}
.context-menu button {
    display: flex; align-items: center; gap: 10px;
    width: 100%; padding: 11px 16px;
    background: none; border: none; cursor: pointer;
    font-family: inherit; font-size: 14px;
    color: var(--text, #333);
    text-align: left;
    transition: background .12s;
}
.context-menu button:hover  { background: var(--bg2, #f5f5f5); }
.context-menu button.danger { color: #e53e3e; }
.context-menu button i      { width: 16px; text-align: center; }

/* ===== MSG BODY ===== */
.msg-body { display: flex; flex-direction: column; }
.msg-wrap.out .msg-body { align-items: flex-end; }
.msg-wrap.in  .msg-body { align-items: flex-start; }
.msg-avatar {
    width: 30px; height: 30px; border-radius: 50%;
    object-fit: cover; flex-shrink: 0;
    align-self: flex-end; margin-bottom: 18px;
}
.msg-sender-name { font-size: 12px; font-weight: 600; color: var(--blue,#1DA1F2); margin-bottom: 2px; padding-left: 2px; }

/* ===== EDIT BANNER ===== */
.edit-banner {
    display: none;
    align-items: center; gap: 10px;
    padding: 8px 16px;
    background: rgba(29,161,242,.1);
    border-top: 2px solid var(--blue,#1DA1F2);
    font-size: 13px;
    color: var(--blue,#1DA1F2);
}
.edit-banner i { font-size: 14px; }
</style>
</head>
<body>

<!-- ===== SIDEBAR ===== -->
<div class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">
      <img src="res/original.png" style="width:auto; height: 36px;" class="logo-img" alt="HAFATRA" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
      <div class="logo-mark" style="display:none"><i class="fa-solid fa-message"></i></div>
      <span class="logo-text">HAFATRA</span>
    </div>
    <div class="sidebar-actions">
      <button class="icon-btn" onclick="openNewConv()" title="Nouvelle conversation"><i class="fa-solid fa-pen-to-square"></i></button>
      <button class="icon-btn" onclick="openCreateGroup()" title="Nouveau groupe"><i class="fa-solid fa-users"></i></button>
      <!--button class="icon-btn" onclick="toggleTheme()" id="themeBtn" title="Thème">
        <i class="fa-solid <?= $user['dark_mode'] ? 'fa-sun' : 'fa-moon' ?>"></i>
      </button-->
      <button class="icon-btn" id="createChannelBtn" onclick="openCreateChannel()" title="Nouveau canal" style="display:none"><i class="fa-solid fa-tower-broadcast"></i></button>
      <button class="icon-btn" onclick="openSettings()" title="Paramètres"><i class="fa-solid fa-bars"></i></button>
    </div>
  </div>

  <div class="sidebar-tabs">
    <button class="tab-btn active" data-tab="chats"    onclick="switchTab('chats')"><i class="fa-solid fa-message"></i><span>Messages</span></button>
    <button class="tab-btn"        data-tab="channels" onclick="switchTab('channels')"><i class="fa-solid fa-tower-broadcast"></i><span>Canaux</span></button>
    <button class="tab-btn"        data-tab="status"   onclick="switchTab('status')"><i class="fa-solid fa-circle-notch"></i><span>Statuts</span></button>
    <button class="tab-btn"        data-tab="requests" onclick="switchTab('requests')"><i class="fa-solid fa-clock"></i><span>Demandes</span><span class="badge" id="reqBadge" style="display:none"></span></button>
    <button class="tab-btn"        data-tab="spam"     onclick="switchTab('spam')"><i class="fa-solid fa-ban"></i><span>Spam</span></button>
  </div>

  <div class="sidebar-search" id="sidebarSearch">
    <div class="search-input-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" id="convSearch" placeholder="Rechercher…" oninput="filterConvs()">
    </div>
  </div>

  <div class="conv-list" id="convList">
    <div class="loading-convs"><i class="fa-solid fa-spinner fa-spin"></i></div>
  </div>

  <!--div class="sidebar-footer">
    <div class="profile-mini" onclick="openProfile()">
      <img src="uploads/avatars/<?= htmlspecialchars($user['avatar']) ?>" class="avatar avatar-sm"
           onerror="this.src='uploads/avatars/default.svg'" id="myAvatarFooter">
      <div class="profile-mini-info">
        <span class="profile-name"  id="myNameFooter"><?= htmlspecialchars($user['name']) ?></span>
        <span class="profile-phone"><?= htmlspecialchars($user['phone']) ?></span>
      </div>
    </div>
    <a href="logout.php" class="icon-btn danger" title="Déconnexion"><i class="fa-solid fa-arrow-right-from-bracket"></i></a>
  </div-->
</div>

<!-- ===== MAIN ===== -->
<div class="main-content" id="mainContent">

  <!-- Empty state -->
  <div class="empty-state" id="emptyState">
    <div class="empty-icon"><img src="res/logo.svg" style="width:66px; height:66px;"></div>
    <h2>Bienvenue sur HAFATRA</h2>
    <p>Messagerie sécurisée, ouverte à tous.</p>
    <div class="empty-actions">
      <button class="btn-primary"   onclick="openNewConv()"><i class="fa-solid fa-plus"></i> Nouvelle conversation</button>
      <button class="btn-secondary" onclick="openCreateGroup()"><i class="fa-solid fa-users"></i> Créer un groupe</button>
    </div>
  </div>

  <!-- Status panel -->
  <div class="status-panel" id="statusPanel" style="display:none">
    <div class="status-panel-header">
      <button class="icon-btn" onclick="closeStatusPanel()"><i class="fa-solid fa-arrow-left"></i></button>
      <h3>Statuts</h3>
      <button class="btn-primary btn-sm" onclick="openAddStatus()"><i class="fa-solid fa-plus"></i> Ajouter</button>
    </div>
    <div class="status-panel-body" id="statusPanelBody">
      <div class="loading-convs"><i class="fa-solid fa-spinner fa-spin"></i></div>
    </div>
  </div>

  <!-- Chat window -->
  <div class="chat-window" id="chatWindow" style="display:none">

    <!-- Header -->
    <div class="chat-header">
      <button class="icon-btn back-btn" onclick="closeChat()" id="backBtn" style="display:none"><i class="fa-solid fa-arrow-left"></i></button>
      <div class="chat-contact-info" id="chatContactInfo" onclick="onChatHeaderClick()">
        <img src="" alt="" class="avatar" id="chatAvatar" onerror="this.src='uploads/avatars/default.svg'">
        <div>
          <div class="chat-contact-name"   id="chatContactName"></div>
          <div class="chat-contact-status" id="chatContactStatus"></div>
        </div>
      </div>
      <div class="chat-header-right">
        <button class="icon-btn call-btn" id="callBtn" onclick="startCall('audio')" title="Appel audio" style="display:none">
          <i class="fa-solid fa-phone"></i>
        </button>
        <button class="icon-btn call-btn" id="videoCallBtn" onclick="startCall('video')" title="Appel vidéo" style="display:none">
          <i class="fa-solid fa-video"></i>
        </button>
        <button class="icon-btn" onclick="toggleConvMenu()"><i class="fa-solid fa-ellipsis-vertical"></i></button>
        <div class="dropdown-menu" id="convMenu">
          <button onclick="markAsSpam()"    id="menuSpam"><i class="fa-solid fa-ban"></i> Spam</button>
          <button onclick="blockContact()"  id="menuBlock"><i class="fa-solid fa-user-slash"></i> Bloquer</button>
          <button onclick="reportContact()" id="menuReport"><i class="fa-solid fa-flag"></i> Signaler</button>
          <button onclick="openGroupInfo()" id="menuGroupInfo"  style="display:none"><i class="fa-solid fa-circle-info"></i> Info groupe</button>
          <button onclick="leaveGroup()"     id="menuLeaveGroup" style="display:none" class="danger"><i class="fa-solid fa-right-from-bracket"></i> Quitter</button>
          <button onclick="deleteConv()" class="danger"><i class="fa-solid fa-trash"></i> Supprimer</button>
        </div>
      </div>
    </div>

    <!-- Messages -->
    <div class="messages-area" id="messagesArea"></div>

    <!-- Banners -->
    <div class="request-banner" id="requestBanner" style="display:none">
      <p><i class="fa-solid fa-user-clock"></i> Cette personne souhaite vous contacter</p>
      <div class="request-actions">
        <button class="btn-accept" onclick="acceptConv()"><i class="fa-solid fa-check"></i> Accepter</button>
        <button class="btn-spam"   onclick="markAsSpam()"><i class="fa-solid fa-ban"></i> Spam</button>
        <button class="btn-block"  onclick="blockContact()"><i class="fa-solid fa-user-slash"></i> Bloquer</button>
      </div>
    </div>
    <div class="request-banner" id="spamBanner" style="display:none;background:rgba(244,33,46,.06);border-top:2px solid rgba(244,33,46,.3)">
      <p><i class="fa-solid fa-ban" style="color:var(--danger)"></i> Cette conversation est classée comme spam</p>
      <div class="request-actions">
        <button class="btn-accept" onclick="removeFromSpam()"><i class="fa-solid fa-check"></i> Ce n'est pas du spam</button>
        <button class="btn-block"  onclick="blockContact()"><i class="fa-solid fa-user-slash"></i> Bloquer</button>
      </div>
    </div>
    <div class="blocked-banner" id="blockedBanner" style="display:none">
      <i class="fa-solid fa-user-slash"></i> Contact bloqué.
      <button onclick="unblockContact()">Débloquer</button>
    </div>

    <!-- Reply preview -->
    <div class="reply-preview" id="replyPreview" style="display:none">
      <div class="reply-preview-inner"><i class="fa-solid fa-reply"></i><span id="replyPreviewText"></span></div>
      <button class="icon-btn" onclick="cancelReply()"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <!-- Edit banner -->
    <div class="edit-banner" id="editBanner">
      <i class="fa-solid fa-pen"></i> Mode édition — Échap pour annuler
      <button class="icon-btn" onclick="cancelEdit()" style="margin-left:auto"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <!-- Zone de saisie -->
    <div class="chat-input-area" id="chatInputArea">
      <div class="input-left">
        <button class="icon-btn attach-btn" onclick="toggleAttachMenu()" title="Joindre"><i class="fa-solid fa-paperclip"></i></button>
        <div class="attach-menu" id="attachMenu" style="display:none">
          <label for="imgInput"  class="attach-item"><i class="fa-solid fa-image"  style="color:#1DA1F2"></i> Image/Vidéo</label>
          <label for="fileInput" class="attach-item"><i class="fa-solid fa-file"   style="color:#ff9500"></i> Fichier</label>
          <input type="file" id="imgInput"  style="display:none" onchange="sendFile(this)" accept="image/*,video/*">
          <input type="file" id="fileInput" style="display:none" onchange="sendFile(this)" accept=".pdf,.doc,.docx,.zip,.txt,.xls,.xlsx,.ppt,.pptx">
        </div>
      </div>
      <div class="message-input-wrap">
        <textarea id="msgInput" placeholder="Écrire un message…" rows="1"
                  onkeydown="handleMsgKey(event)" oninput="autoResize(this);handleInputChange()"></textarea>
      </div>
      <button class="send-btn" id="voiceBtn" onclick="toggleVoiceRecord()" title="Message vocal">
        <i class="fa-solid fa-microphone"></i>
      </button>
      <button class="send-btn" id="sendBtn" onclick="sendMessage()" style="display:none">
        <i class="fa-solid fa-paper-plane"></i>
      </button>
    </div>

    <!-- Enregistrement vocal -->
    <div class="voice-recording" id="voiceRecording" style="display:none">
      <div class="voice-rec-pulse"></div>
      <span class="voice-rec-time" id="voiceRecTime">0:00</span>
      <div class="voice-rec-wave" id="voiceRecWave">
        <span></span><span></span><span></span><span></span><span></span>
        <span></span><span></span><span></span><span></span><span></span>
      </div>
      <button class="icon-btn danger" onclick="cancelVoice()" title="Annuler"><i class="fa-solid fa-trash"></i></button>
      <button class="voice-send-btn"  onclick="sendVoice()"   title="Envoyer"><i class="fa-solid fa-paper-plane"></i></button>
    </div>
  </div><!-- /chat-window -->
</div><!-- /main-content -->

<!-- ===== MODALS ===== -->

<!-- Nouvelle conversation -->
<div class="modal-overlay" id="newConvModal" style="display:none" onclick="closeModal('newConvModal',event)">
  <div class="modal">
    <div class="modal-header">
      <h3><i class="fa-solid fa-user-plus"></i> Nouvelle conversation</h3>
      <button class="icon-btn" onclick="closeModal('newConvModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <p class="modal-hint"><i class="fa-solid fa-info-circle"></i> Entrez le numéro de téléphone.</p>
      <div class="form-group">
        <label>Numéro de téléphone</label>
        <div class="input-wrap"><i class="fa-solid fa-phone"></i><input type="tel" id="newContactPhone" placeholder="+261 XX XX XXX XX" onblur="lookupPhone()"></div>
      </div>
      <div class="form-group">
        <label>Surnom (optionnel)</label>
        <div class="input-wrap"><i class="fa-solid fa-tag"></i><input type="text" id="newContactNickname" placeholder="Surnom affiché"></div>
      </div>
      <div id="newConvError"   class="error-msg"      style="display:none"></div>
      <div id="newConvPreview" class="contact-preview" style="display:none">
        <img src="" class="avatar" id="previewAvatar" onerror="this.src='uploads/avatars/default.svg'">
        <div><strong id="previewName"></strong><span id="previewPhone"></span></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('newConvModal')">Annuler</button>
      <button class="btn-primary"   onclick="startConversation()"><i class="fa-solid fa-paper-plane"></i> Démarrer</button>
    </div>
  </div>
</div>

<!-- Créer groupe -->
<div class="modal-overlay" id="createGroupModal" style="display:none" onclick="closeModal('createGroupModal',event)">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3><i class="fa-solid fa-users"></i> Créer un groupe</h3>
      <button class="icon-btn" onclick="closeModal('createGroupModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="group-avatar-wrap">
        <div class="group-avatar-preview" id="groupAvatarPreview"><i class="fa-solid fa-users"></i></div>
        <label for="groupAvatarInput" class="avatar-upload-btn"><i class="fa-solid fa-camera"></i></label>
        <input type="file" id="groupAvatarInput" style="display:none" accept="image/*">
      </div>
      <div class="form-group">
        <label>Nom du groupe *</label>
        <div class="input-wrap"><i class="fa-solid fa-users"></i><input type="text" id="groupName" placeholder="Nom du groupe" maxlength="100"></div>
      </div>
      <div class="form-group">
        <label>Description</label>
        <textarea id="groupDesc" placeholder="Description…" style="width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:12px;background:var(--bg2);font-family:inherit;font-size:14px;color:var(--text);resize:none;min-height:70px;outline:none;"></textarea>
      </div>
      <div class="form-group">
        <label>Ajouter des membres</label>
        <div class="group-member-add">
          <div class="input-wrap" style="flex:1"><i class="fa-solid fa-phone"></i><input type="tel" id="groupMemberPhone" placeholder="+261 XX XX XXX XX"></div>
          <button class="btn-primary btn-sm" onclick="addGroupMember()"><i class="fa-solid fa-plus"></i></button>
        </div>
        <div id="groupMembersPreview" class="group-members-list"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('createGroupModal')">Annuler</button>
      <button class="btn-primary"   onclick="createGroup()"><i class="fa-solid fa-check"></i> Créer</button>
    </div>
  </div>
</div>

<!-- Info groupe -->
<div class="modal-overlay" id="groupInfoModal" style="display:none" onclick="closeModal('groupInfoModal',event)">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3><i class="fa-solid fa-circle-info"></i> Info du groupe</h3>
      <button class="icon-btn" onclick="closeModal('groupInfoModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" id="groupInfoBody">
      <div class="loading-convs"><i class="fa-solid fa-spinner fa-spin"></i></div>
    </div>
  </div>
</div>

<!-- Ajouter statut -->
<div class="modal-overlay" id="addStatusModal" style="display:none" onclick="closeModal('addStatusModal',event)">
  <div class="modal">
    <div class="modal-header">
      <h3><i class="fa-solid fa-circle-notch"></i> Nouveau statut</h3>
      <button class="icon-btn" onclick="closeModal('addStatusModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="status-type-tabs">
        <button class="status-type-btn active" data-type="text"  onclick="switchStatusType('text')"><i class="fa-solid fa-t"></i> Texte</button>
        <button class="status-type-btn"         data-type="image" onclick="switchStatusType('image')"><i class="fa-solid fa-image"></i> Photo</button>
        <button class="status-type-btn"         data-type="video" onclick="switchStatusType('video')"><i class="fa-solid fa-video"></i> Vidéo</button>
      </div>
      <div id="statusTextForm">
        <div class="status-preview-text" id="statusTextPreview" style="background:#1DA1F2">
          <span id="statusPreviewContent">Votre statut…</span>
        </div>
        <div class="form-group">
          <label>Texte</label>
          <textarea id="statusText" placeholder="Que pensez-vous ?" oninput="updateStatusPreview()"
            style="width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:12px;background:var(--bg2);font-family:inherit;font-size:14px;color:var(--text);resize:none;min-height:80px;outline:none;"></textarea>
        </div>
        <div class="form-group">
          <label>Couleur de fond</label>
          <div class="color-palette" id="statusColorPalette">
            <div class="color-swatch active" style="background:#1DA1F2"  onclick="setStatusColor('#1DA1F2',this)"></div>
            <div class="color-swatch" style="background:#00ba7c"         onclick="setStatusColor('#00ba7c',this)"></div>
            <div class="color-swatch" style="background:#f4212e"         onclick="setStatusColor('#f4212e',this)"></div>
            <div class="color-swatch" style="background:#ff9500"         onclick="setStatusColor('#ff9500',this)"></div>
            <div class="color-swatch" style="background:#7856ff"         onclick="setStatusColor('#7856ff',this)"></div>
            <div class="color-swatch" style="background:#ff2d78"         onclick="setStatusColor('#ff2d78',this)"></div>
            <div class="color-swatch" style="background:#0f1419"         onclick="setStatusColor('#0f1419',this)"></div>
          </div>
        </div>
        <div class="form-group">
          <label>Style</label>
          <div class="font-style-tabs">
            <button class="font-style-btn active" onclick="setStatusFont('normal',this)" style="font-weight:400">Normal</button>
            <button class="font-style-btn"        onclick="setStatusFont('bold',this)"   style="font-weight:700">Gras</button>
            <button class="font-style-btn"        onclick="setStatusFont('italic',this)" style="font-style:italic">Italique</button>
            <button class="font-style-btn"        onclick="setStatusFont('serif',this)"  style="font-family:Georgia">Serif</button>
          </div>
        </div>
      </div>
      <div id="statusMediaForm" style="display:none">
        <div class="status-media-upload" id="statusMediaUpload" onclick="document.getElementById('statusMediaInput').click()">
          <i class="fa-solid fa-cloud-arrow-up"></i>
          <p>Cliquez pour choisir</p>
          <span id="statusMediaName"></span>
        </div>
        <input type="file" id="statusMediaInput" style="display:none" onchange="previewStatusMedia(this)">
        <div id="statusMediaPreviewWrap" style="display:none;text-align:center;margin-top:12px">
          <img  id="statusMediaPreviewImg" style="max-width:100%;max-height:200px;border-radius:12px;display:none">
          <video id="statusMediaPreviewVid" style="max-width:100%;max-height:200px;border-radius:12px;display:none" controls></video>
        </div>
        <div class="form-group" style="margin-top:12px">
          <label>Légende</label>
          <div class="input-wrap"><i class="fa-solid fa-pen"></i><input type="text" id="statusCaption" placeholder="Ajouter une légende…"></div>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('addStatusModal')">Annuler</button>
      <button class="btn-primary"   onclick="publishStatus()"><i class="fa-solid fa-paper-plane"></i> Publier (24h)</button>
    </div>
  </div>
</div>

<!-- Visionneuse statut -->
<div class="status-viewer" id="statusViewer" style="display:none">
  <div class="status-viewer-header">
    <button class="icon-btn" style="color:white" onclick="closeStatusViewer()"><i class="fa-solid fa-xmark"></i></button>
    <div class="status-viewer-user">
      <img src="" class="avatar avatar-sm" id="svAvatar" onerror="this.src='uploads/avatars/default.svg'">
      <div><strong id="svName"></strong><span id="svTime"></span></div>
    </div>
    <span class="status-views-count" id="svViews"></span>
  </div>
  <div class="status-progress-bar"><div class="status-progress-fill" id="svProgress"></div></div>
  <div class="status-viewer-content" id="svContent"></div>
  <div class="status-viewer-nav">
    <button onclick="prevStatus()"><i class="fa-solid fa-chevron-left"></i></button>
    <button onclick="nextStatus()"><i class="fa-solid fa-chevron-right"></i></button>
  </div>
</div>

<!-- Profil -->
<div class="modal-overlay" id="profileModal" style="display:none" onclick="closeModal('profileModal',event)">
  <div class="modal">
    <div class="modal-header">
      <h3><i class="fa-solid fa-user-circle"></i> Mon profil</h3>
      <button class="icon-btn" onclick="closeModal('profileModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="avatar-upload-wrap">
        <img src="uploads/avatars/<?= htmlspecialchars($user['avatar']) ?>" class="avatar avatar-xl" id="profileAvatarPreview"
             onerror="this.src='uploads/avatars/default.svg'">
        <label for="avatarInput" class="avatar-upload-btn"><i class="fa-solid fa-camera"></i></label>
        <input type="file" id="avatarInput" style="display:none" accept="image/*" onchange="uploadAvatar(this)">
      </div>
      <div class="form-group">
        <label>Nom</label>
        <div class="input-wrap"><i class="fa-solid fa-user"></i><input type="text" id="profileName" value="<?= htmlspecialchars($user['name']) ?>"></div>
      </div>
      <div class="form-group">
        <label>Bio</label>
        <textarea id="profileBio" placeholder="Quelques mots sur vous…"
          style="width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:12px;background:var(--bg2);font-family:inherit;font-size:14px;color:var(--text);resize:none;min-height:80px;outline:none;"><?= htmlspecialchars($user['bio']??'') ?></textarea>
      </div>
      <div class="form-group">
        <label>Téléphone</label>
        <div class="input-wrap"><i class="fa-solid fa-phone"></i><input type="text" value="<?= htmlspecialchars($user['phone']) ?>" readonly></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('profileModal')">Fermer</button>
      <button class="btn-primary"   onclick="saveProfile()"><i class="fa-solid fa-floppy-disk"></i> Sauvegarder</button>
    </div>
  </div>
</div>

<!-- ===== REACTION PICKER ===== -->
<div id="reactionPicker">
  <button onclick="sendReaction('❤️')"  title="Amour">❤️</button>
  <button onclick="sendReaction('👍')"  title="J'aime">👍</button>
  <button onclick="sendReaction('😂')"  title="Haha">😂</button>
  <button onclick="sendReaction('😮')"  title="Wow">😮</button>
  <button onclick="sendReaction('😢')"  title="Triste">😢</button>
  <button onclick="sendReaction('🙏')"  title="Merci">🙏</button>
  <button onclick="sendReaction('🔥')"  title="Feu">🔥</button>
  <button onclick="sendReaction('🎉')"  title="Fête">🎉</button>
</div>

<!-- ===== CONTEXT MENU ===== -->
<div class="context-menu" id="msgContextMenu">
  <button id="replyMsgBtn"  onclick="replyToMsg()"><i class="fa-solid fa-reply"></i> Répondre</button>
  <button                   onclick="openReactionPicker(event,selectedMsgId)"><i class="fa-regular fa-face-smile"></i> Réagir</button>
  <button id="copyMsgBtn"   onclick="copyMsg()"><i class="fa-solid fa-copy"></i> Copier</button>
  <button id="editMsgBtn"   onclick="editMsg()"><i class="fa-solid fa-pen"></i> Modifier</button>
  <button id="deleteMsgBtn" onclick="deleteMsg()" class="danger"><i class="fa-solid fa-trash"></i> Supprimer</button>
</div>

<!-- Toasts -->
<div class="toast-container" id="toastContainer"></div>

<!-- Visionneuse image -->
<div class="img-viewer-overlay" id="imgViewer" style="display:none" onclick="closeImgViewer()">
  <button class="img-viewer-close" onclick="closeImgViewer()"><i class="fa-solid fa-xmark"></i></button>
  <img src="" id="imgViewerImg" alt="">
</div>

<!-- ===== VIDEO CALL OVERLAY ===== -->
<div class="video-call-overlay" id="videoCallOverlay" style="display:none">
  <!-- Vidéos injectées par JS : remote-video (fond) + local-video (coin) -->

  <!-- Affiché tant que la vidéo distante n'est pas encore arrivée -->
  <div class="remote-video-placeholder" id="remoteVideoPlaceholder">
    <img src="" id="remoteVideoPlaceholderAvatar" class="call-avatar" onerror="this.src='uploads/avatars/default.svg'">
    <div class="remote-video-placeholder-spinner"></div>
    <div class="remote-video-placeholder-text">En attente de la vidéo…</div>
  </div>

  <!-- Header avec nom + timer -->
  <div class="video-call-header">
    <div class="video-call-info">
      <span id="videoCallName" style="font-size:16px;font-weight:700;color:#fff"></span>
      <span id="videoCallStatus" style="font-size:12px;color:rgba(255,255,255,.65)">Connexion…</span>
    </div>
    <button class="icon-btn" style="color:rgba(255,255,255,.8)" onclick="minimizeVideoCall()" title="Réduire">
      <i class="fa-solid fa-compress"></i>
    </button>
  </div>

  <!-- Contrôles bas de page -->
  <div class="video-call-controls">
    <div class="video-control-wrap">
      <button class="call-btn-action mute-btn" id="vidMuteBtn" onclick="toggleMute()">
        <i class="fa-solid fa-microphone"></i>
      </button>
      <span>Micro</span>
    </div>
    <div class="video-control-wrap">
      <button class="call-btn-action" id="toggleVideoBtn" onclick="toggleVideo()">
        <i class="fa-solid fa-video"></i>
      </button>
      <span>Caméra</span>
    </div>
    <div class="video-control-wrap">
      <button class="call-btn-action hangup-btn" onclick="hangupCall()">
        <i class="fa-solid fa-phone-slash"></i>
      </button>
      <span>Raccrocher</span>
    </div>
    <div class="video-control-wrap">
      <button class="call-btn-action speaker-btn" id="vidSpeakerBtn" onclick="toggleSpeaker()">
        <i class="fa-solid fa-volume-high"></i>
      </button>
      <span>Son</span>
    </div>
    <div class="video-control-wrap">
      <button class="call-btn-action" onclick="switchCamera()" title="Retourner">
        <i class="fa-solid fa-camera-rotate"></i>
      </button>
      <span>Retourner</span>
    </div>
  </div>
</div>

<!-- ===== CALL UI ===== -->
<div class="call-overlay" id="outgoingCallOverlay" style="display:none">
  <div class="call-bg-blur"></div>
  <div class="call-card">
    <div class="call-status-label">Appel en cours…</div>
    <div class="call-avatar-ring">
      <div class="call-ring-anim ring1"></div><div class="call-ring-anim ring2"></div><div class="call-ring-anim ring3"></div>
      <img src="" id="outCallAvatar" class="call-avatar" onerror="this.src='uploads/avatars/default.svg'">
    </div>
    <div class="call-name" id="outCallName"></div>
    <div class="call-sub"  id="outCallSub">Sonnerie…</div>
    <div class="call-actions-row">
      <div class="call-action-wrap">
        <button class="call-btn-action mute-btn" id="outMuteBtn" onclick="toggleMute()"><i class="fa-solid fa-microphone"></i></button>
        <span>Micro</span>
      </div>
      <div class="call-action-wrap">
        <button class="call-btn-action speaker-btn" id="outSpeakerBtn" onclick="toggleSpeaker()"><i class="fa-solid fa-volume-high"></i></button>
        <span>Son</span>
      </div>
      <div class="call-action-wrap">
        <button class="call-btn-action hangup-btn" onclick="hangupCall()"><i class="fa-solid fa-phone-slash"></i></button>
        <span>Raccrocher</span>
      </div>
    </div>
  </div>
</div>

<div class="call-overlay" id="incomingCallOverlay" style="display:none">
  <div class="call-bg-blur"></div>
  <div class="call-card">
    <div class="call-status-label incoming-label"><i class="fa-solid fa-phone-volume"></i> Appel entrant</div>
    <div class="call-avatar-ring">
      <div class="call-ring-anim ring1"></div><div class="call-ring-anim ring2"></div><div class="call-ring-anim ring3"></div>
      <img src="" id="inCallAvatar" class="call-avatar" onerror="this.src='uploads/avatars/default.svg'">
    </div>
    <div class="call-name" id="inCallName"></div>
    <div class="call-sub" id="inCallType">Appel audio HAFATRA</div>
    <div class="call-actions-row incoming-actions">
      <div class="call-action-wrap">
        <button class="call-btn-action reject-btn" onclick="rejectCall()"><i class="fa-solid fa-phone-slash"></i></button>
        <span>Refuser</span>
      </div>
      <div class="call-action-wrap">
        <button class="call-btn-action accept-btn" onclick="acceptCall()"><i class="fa-solid fa-phone"></i></button>
        <span>Répondre</span>
      </div>
    </div>
  </div>
</div>

<div class="active-call-bar" id="activeCallBar" style="display:none">
  <div class="active-call-info">
    <div class="active-call-dot"></div>
    <img src="" id="activeCallAvatar" class="avatar avatar-xs" onerror="this.src='uploads/avatars/default.svg'">
    <div>
      <div class="active-call-name"  id="activeCallName"></div>
      <div class="active-call-timer" id="activeCallTimer">0:00</div>
    </div>
  </div>
  <div class="active-call-btns">
    <button class="icon-btn" id="barMuteBtn"    onclick="toggleMute()"><i class="fa-solid fa-microphone"></i></button>
    <button class="icon-btn" id="barSpeakerBtn" onclick="toggleSpeaker()"><i class="fa-solid fa-volume-high"></i></button>
    <button class="icon-btn danger"             onclick="hangupCall()"><i class="fa-solid fa-phone-slash"></i></button>
    <button class="icon-btn"                    onclick="maximizeCall()"><i class="fa-solid fa-up-right-and-down-left-from-center"></i></button>
  </div>
</div>



<!-- ===== MODAL CRÉER CANAL ===== -->
<div class="modal-overlay" id="createChannelModal" style="display:none" onclick="closeModal('createChannelModal',event)">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3><i class="fa-solid fa-tower-broadcast"></i> Créer un canal</h3>
      <button class="icon-btn" onclick="closeModal('createChannelModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="group-avatar-wrap">
        <div class="group-avatar-preview" id="channelAvatarPreview"><i class="fa-solid fa-tower-broadcast"></i></div>
        <label for="channelAvatarInput" class="avatar-upload-btn"><i class="fa-solid fa-camera"></i></label>
        <input type="file" id="channelAvatarInput" style="display:none" accept="image/*">
      </div>
      <div class="form-group">
        <label>Nom du canal *</label>
        <div class="input-wrap"><i class="fa-solid fa-tower-broadcast"></i><input type="text" id="channelName" placeholder="Ex: Actualités HAFATRA" maxlength="100"></div>
      </div>
      <div class="form-group">
        <label>Description</label>
        <textarea id="channelDesc" placeholder="À quoi sert ce canal ?" style="width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:12px;background:var(--bg2);font-family:inherit;font-size:14px;color:var(--text);resize:none;min-height:80px;outline:none;"></textarea>
      </div>
      <div class="form-group">
        <label>Visibilité</label>
        <div style="display:flex;gap:10px">
          <label class="report-reason-item" style="flex:1">
            <input type="radio" name="channelVisibility" value="1" checked> <span><strong>Public</strong> — Tout le monde peut s'abonner</span>
          </label>
          <label class="report-reason-item" style="flex:1">
            <input type="radio" name="channelVisibility" value="0"> <span><strong>Privé</strong> — Sur invitation</span>
          </label>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('createChannelModal')">Annuler</button>
      <button class="btn-primary" onclick="createChannel()"><i class="fa-solid fa-check"></i> Créer le canal</button>
    </div>
  </div>
</div>

<!-- ===== MODAL GESTION MEMBRE GROUPE ===== -->
<div class="modal-overlay" id="memberActionModal" style="display:none" onclick="closeModal('memberActionModal',event)">
  <div class="modal">
    <div class="modal-header">
      <h3 id="memberActionTitle"><i class="fa-solid fa-user-gear"></i> Gérer le membre</h3>
      <button class="icon-btn" onclick="closeModal('memberActionModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" id="memberActionBody"></div>
  </div>
</div>


<!-- ===== MODAL PROFIL UTILISATEUR ===== -->
<div class="modal-overlay" id="userProfileModal" style="display:none" onclick="closeModal('userProfileModal',event)">
  <div class="modal">
    <div class="modal-header">
      <h3><i class="fa-solid fa-user"></i> Profil</h3>
      <button class="icon-btn" onclick="closeModal('userProfileModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" id="userProfileContent">
      <div class="loading-convs"><i class="fa-solid fa-spinner fa-spin"></i></div>
    </div>
  </div>
</div>

<!-- ===== MODAL SIGNALEMENT UTILISATEUR ===== -->
<div class="modal-overlay" id="reportModal" style="display:none" onclick="closeModal('reportModal',event)">
  <div class="modal">
    <div class="modal-header">
      <h3><i class="fa-solid fa-flag" style="color:#f4212e"></i> Signaler</h3>
      <button class="icon-btn" onclick="closeModal('reportModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:13px;color:var(--text2);margin-bottom:16px">
        Votre signalement sera examiné par notre équipe. Merci de nous aider à maintenir une communauté sûre.
      </p>
      <div class="form-group">
        <label>Raison du signalement *</label>
        <div class="report-reasons" id="reportReasons">
          <label class="report-reason-item">
            <input type="radio" name="reportReason" value="spam"> <span><strong>Spam</strong> — Messages non sollicités</span>
          </label>
          <label class="report-reason-item">
            <input type="radio" name="reportReason" value="harassment"> <span><strong>Harcèlement</strong> — Comportement abusif</span>
          </label>
          <label class="report-reason-item">
            <input type="radio" name="reportReason" value="inappropriate"> <span><strong>Contenu inapproprié</strong> — Images ou textes choquants</span>
          </label>
          <label class="report-reason-item">
            <input type="radio" name="reportReason" value="fake"> <span><strong>Faux profil</strong> — Usurpation d'identité</span>
          </label>
          <label class="report-reason-item">
            <input type="radio" name="reportReason" value="other"> <span><strong>Autre</strong></span>
          </label>
        </div>
      </div>
      <div class="form-group">
        <label>Détails supplémentaires (optionnel)</label>
        <textarea id="reportDescription" placeholder="Décrivez le problème…"
          style="width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:12px;background:var(--bg2);font-family:inherit;font-size:14px;color:var(--text);resize:none;min-height:80px;outline:none;"></textarea>
      </div>
      <div id="reportError" class="error-msg" style="display:none"></div>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeModal('reportModal')">Annuler</button>
      <button class="btn-primary" style="background:linear-gradient(135deg,#f4212e,#c0392b)" onclick="submitReport()">
        <i class="fa-solid fa-flag"></i> Envoyer le signalement
      </button>
    </div>
  </div>
</div>

<!-- ===== MODAL PARAMÈTRES ===== -->
<div class="modal-overlay" id="settingsModal" style="display:none" onclick="closeModal('settingsModal',event)">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3><i class="fa-solid fa-gear"></i> Paramètres</h3>
      <button class="icon-btn" onclick="closeModal('settingsModal')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" style="padding:0">
      <!-- Tabs paramètres -->
      <div class="settings-tabs">
        <button class="settings-tab active" data-panel="account"  onclick="switchSettingsTab('account')"><i class="fa-solid fa-user"></i> Compte</button>
        <button class="settings-tab"        data-panel="security" onclick="switchSettingsTab('security')"><i class="fa-solid fa-lock"></i> Sécurité</button>
        <button class="settings-tab"        data-panel="blocked"  onclick="switchSettingsTab('blocked')"><i class="fa-solid fa-user-slash"></i> Bloqués</button>
        <button class="settings-tab"        data-panel="report"   onclick="switchSettingsTab('report')"><i class="fa-solid fa-flag"></i> Signaler</button>
        <button class="settings-tab"        data-panel="about"    onclick="switchSettingsTab('about')"><i class="fa-solid fa-circle-info"></i> À propos</button>
      </div>

      <!-- Panel Compte -->
      <div class="settings-panel active" id="panelAccount" style="padding:20px">
        <div id="settingsAccountContent"><div class="loading-convs"><i class="fa-solid fa-spinner fa-spin"></i></div></div>
      </div>

      <!-- Panel Sécurité -->
      <div class="settings-panel" id="panelSecurity" style="display:none;padding:20px">
        <h4 class="settings-section-title"><i class="fa-solid fa-key"></i> Changer le mot de passe</h4>
        <div class="form-group">
          <label>Mot de passe actuel</label>
          <div class="input-wrap"><i class="fa-solid fa-lock"></i><input type="password" id="pwdOld" placeholder="••••••••"></div>
        </div>
        <div class="form-group">
          <label>Nouveau mot de passe</label>
          <div class="input-wrap"><i class="fa-solid fa-lock"></i><input type="password" id="pwdNew" placeholder="Min. 6 caractères"></div>
        </div>
        <div class="form-group">
          <label>Confirmer le nouveau mot de passe</label>
          <div class="input-wrap"><i class="fa-solid fa-lock"></i><input type="password" id="pwdConfirm" placeholder="Répéter le mot de passe"></div>
        </div>
        <div id="pwdError"   class="error-msg" style="display:none"></div>
        <div id="pwdSuccess" style="display:none;background:#f0fff4;border:1px solid #00ba7c;color:#00ba7c;padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:12px">
          <i class="fa-solid fa-check"></i> Mot de passe changé avec succès
        </div>
        <button class="btn-primary" onclick="changePassword()" style="width:100%">
          <i class="fa-solid fa-floppy-disk"></i> Mettre à jour le mot de passe
        </button>

        <div style="margin-top:28px;padding-top:20px;border-top:1px solid var(--border)">
          <h4 class="settings-section-title" style="color:var(--danger)"><i class="fa-solid fa-triangle-exclamation"></i> Zone dangereuse</h4>
          <p style="font-size:13px;color:var(--text2);margin-bottom:14px">
            La suppression de votre compte est irréversible. Toutes vos données seront anonymisées.
          </p>
          <button class="btn-danger" onclick="confirmDeleteAccount()" style="width:100%">
            <i class="fa-solid fa-trash"></i> Supprimer mon compte
          </button>
        </div>
      </div>

      <!-- Panel Bloqués -->
      <div class="settings-panel" id="panelBlocked" style="display:none;padding:20px">
        <h4 class="settings-section-title"><i class="fa-solid fa-user-slash"></i> Utilisateurs bloqués</h4>
        <div id="blockedList"><div class="loading-convs"><i class="fa-solid fa-spinner fa-spin"></i></div></div>
      </div>

      <!-- Panel Signaler un problème -->
      <div class="settings-panel" id="panelReport" style="display:none;padding:20px">
        <h4 class="settings-section-title"><i class="fa-solid fa-bug"></i> Signaler un problème technique</h4>
        <p style="font-size:13px;color:var(--text2);margin-bottom:16px">
          Vous rencontrez un bug ou un problème ? Décrivez-le ici et notre équipe le traitera rapidement.
        </p>
        <div class="form-group">
          <label>Catégorie</label>
          <select id="problemCategory" style="width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:12px;background:var(--bg2);font-family:inherit;font-size:14px;color:var(--text);outline:none;appearance:none">
            <option value="bug">🐛 Bug / Erreur</option>
            <option value="perf">🐢 Lenteur / Performance</option>
            <option value="ui">🎨 Problème d'affichage</option>
            <option value="feature">💡 Suggestion de fonctionnalité</option>
            <option value="other">❓ Autre</option>
          </select>
        </div>
        <div class="form-group">
          <label>Description *</label>
          <textarea id="problemDesc" placeholder="Décrivez le problème en détail…"
            style="width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:12px;background:var(--bg2);font-family:inherit;font-size:14px;color:var(--text);resize:none;min-height:120px;outline:none;"></textarea>
        </div>
        <div id="problemError"   class="error-msg" style="display:none"></div>
        <div id="problemSuccess" style="display:none;background:#f0fff4;border:1px solid #00ba7c;color:#00ba7c;padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:12px">
          <i class="fa-solid fa-check"></i> Rapport envoyé ! Merci.
        </div>
        <button class="btn-primary" onclick="submitProblem()" style="width:100%">
          <i class="fa-solid fa-paper-plane"></i> Envoyer le rapport
        </button>
      </div>

      <!-- Panel À propos -->
      <div class="settings-panel" id="panelAbout" style="display:none;padding:20px">
        <div style="text-align:center;padding:20px 0">
          <div style="width:72px;height:72px;background:linear-gradient(135deg,var(--blue),#4fc3f7);border-radius:20px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;box-shadow:0 8px 24px rgba(29,161,242,.3)">
            <i class="fa-solid fa-message" style="color:#fff;font-size:28px"></i>
          </div>
          <h3 style="font-size:22px;font-weight:800;margin-bottom:4px">HAFATRA</h3>
          <p style="color:var(--text3);font-size:13px">Version 2.0 — Messagerie sécurisée</p>
        </div>
        <div class="about-links">
          <div class="about-item"><i class="fa-solid fa-shield-halved"></i><div><strong>Chiffrement</strong><span>Messages protégés</span></div></div>
          <div class="about-item"><i class="fa-solid fa-globe"></i><div><strong>Open</strong><span>Ouvert à tous</span></div></div>
          <div class="about-item"><i class="fa-solid fa-heart"></i><div><strong>Gratuit</strong><span>Sans publicité</span></div></div>
        </div>
        <div style="text-align:center;margin-top:24px;color:var(--text3);font-size:12px">
          © 2025 HAFATRA · Tous droits réservés
        </div>
      </div>
    </div>
  </div>
</div>

<audio id="remoteAudio" autoplay></audio>

<script>
const CURRENT_USER_ID    = <?= (int)$user['id'] ?>;
const CURRENT_USER_NAME  = <?= json_encode($user['name']) ?>;
const CURRENT_USER_PHONE = <?= json_encode($user['phone']) ?>;
const CURRENT_USER_AVATAR= <?= json_encode($user['avatar']) ?>;
</script>
<script src="js/app.js"></script>
<script src="js/call.js"></script>
<script src="js/features.js"></script>
</body>
</html>
