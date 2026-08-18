// HAFATRA v3 - app.js complet

// Variables globales exposées sur window pour call.js
let currentConvId = null, currentContactId = null, currentConvType = 'direct';
window._hafatra = { get convId(){ return currentConvId; }, get contactId(){ return currentContactId; }, get convType(){ return currentConvType; } };
let lastMsgId = 0, pollInterval = null, activeTab = 'chats';
let selectedMsgId = null, replyToId = null, editingMsgId = null;
let groupMembers = [], groupAvatarFile = null;
let statusBgColor = '#1DA1F2', statusFont = 'normal', statusType = 'text', statusMediaFile = null;
let mediaRecorder = null, audioChunks = [], voiceTimer = null, voiceSeconds = 0;
let voiceStream = null;
let statusList = [], currentStatusIdx = 0, statusTimer = null;

// ===== INIT =====
document.addEventListener('DOMContentLoaded', () => {
    loadConversations();
    pollInterval = setInterval(() => {
        if (activeTab === 'chats') loadConversations(true);
        if (currentConvId) pollMessages();
    }, 3000);
    document.addEventListener('click', handleGlobalClick);
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { cancelEdit(); cancelReply(); closeAllMenus(); }
    });
    const msgInput = document.getElementById('msgInput');
    if (msgInput) msgInput.addEventListener('input', handleInputChange);
});

function handleInputChange() {
    const val = document.getElementById('msgInput').value.trim();
    document.getElementById('voiceBtn').style.display = val ? 'none' : 'flex';
    document.getElementById('sendBtn').style.display  = val ? 'flex' : 'none';
}

// ===== API =====
async function api(action, data = {}) {
    try {
        const r = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action, ...data })
        });
        // Session expirée → rediriger vers login
        if (r.status === 401 || r.status === 403) {
            const body = await r.json().catch(() => ({}));
            if (body.redirect || body.error === 'session_expired') {
                window.location.href = 'login.php';
                return { error: 'session_expired' };
            }
        }
        if (!r.ok) { console.error('HTTP', r.status); return { error: 'Erreur serveur (' + r.status + ')' }; }
        const text = await r.text();
        try { return JSON.parse(text); }
        catch (e) { console.error('JSON error:', text.substring(0,200)); return { error: 'Réponse invalide' }; }
    } catch (e) { return { error: 'Erreur réseau' }; }
}

async function safeJson(resp) {
    try { return JSON.parse(await resp.text()); } catch(e) { return { error: 'Réponse invalide' }; }
}

// ===== TABS =====
function switchTab(tab) {
    activeTab = tab;
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelector(`[data-tab="${tab}"]`)?.classList.add('active');
    const statusPanel = document.getElementById('statusPanel');
    const mainEmpty   = document.getElementById('emptyState');
    const chatWin     = document.getElementById('chatWindow');
    const search      = document.getElementById('sidebarSearch');
    const convList    = document.getElementById('convList');

    if (tab === 'status') {
        search.style.display = 'none';
        convList.style.display = 'none';
        mainEmpty.style.display = 'none';
        chatWin.style.display = 'none';
        statusPanel.style.display = 'flex';
        if (window.innerWidth <= 768) {
            document.getElementById('sidebar').classList.add('hidden');
        }
        loadStatusPanel();
    } else {
        search.style.display = '';
        convList.style.display = '';
        statusPanel.style.display = 'none';
        if (window.innerWidth <= 768) {
            document.getElementById('sidebar').classList.remove('hidden');
        }
        if (!currentConvId) mainEmpty.style.display = 'flex';
        loadConversations();
    }
}

// ===== CONVERSATIONS =====
async function loadConversations(silent = false) {
    const list = document.getElementById('convList');
    if (!silent) list.innerHTML = '<div class="loading-convs"><i class="fa-solid fa-spinner fa-spin"></i></div>';
    const r = await api('get_conversations', { tab: activeTab });
    if (r.error) {
        list.innerHTML = `<div class="empty-convs"><i class="fa-solid fa-triangle-exclamation"></i>${esc(r.error)}</div>`;
        return;
    }
    const req = await api('get_request_count');
    const badge = document.getElementById('reqBadge');
    if (req.count > 0) { badge.style.display = ''; badge.textContent = req.count; }
    else badge.style.display = 'none';
    renderConversations(r.convs || []);
}

function renderConversations(convs) {
    const list = document.getElementById('convList');
    const q = (document.getElementById('convSearch')?.value || '').toLowerCase();
    const filtered = convs.filter(c => (c.name||'').toLowerCase().includes(q));
    if (!filtered.length) {
        const icons = { chats:'fa-message', requests:'fa-clock', spam:'fa-ban' };
        const msgs  = { chats:'Aucune conversation', requests:'Aucune demande', spam:'Aucun spam' };
        list.innerHTML = `<div class="empty-convs"><i class="fa-solid ${icons[activeTab]||'fa-message'}"></i>${msgs[activeTab]||''}</div>`;
        return;
    }
    list.innerHTML = filtered.map(c => {
        const isActive  = c.id == currentConvId ? 'active' : '';
        const av        = c.avatar || 'default.svg';
        const isGroup   = c.type === 'group';
        const onlineDot = (!isGroup && c.status === 'online') ? 'online' : '';
        const lastMsg   = c.last_type && c.last_type !== 'text'
            ? ({image:'📷 Photo',video:'🎥 Vidéo',file:'📎 Fichier',voice:'🎤 Vocal'}[c.last_type]||'Fichier')
            : (c.last_msg ? esc(c.last_msg).substring(0,40) : '<em>Démarrer…</em>');
        const unread    = c.unread > 0 ? `<span class="conv-unread">${c.unread}</span>` : '';
        const typeBadge = isGroup ? '<span class="conv-type-badge">Groupe</span>' : '';
        return `<div class="conv-item ${isActive}" onclick="openConversation(${c.id},${c.contact_id||0},'${c.type||'direct'}')">
          <div class="conv-avatar-wrap">
            <img src="uploads/avatars/${av}" class="avatar${isGroup?' group-avatar':''}" onerror="this.src='uploads/avatars/default.svg'">
            ${!isGroup ? `<span class="conv-status-dot ${onlineDot}"></span>` : ''}
          </div>
          <div class="conv-info">
            <div class="conv-name">${esc(c.name||'?')} ${typeBadge}</div>
            <div class="conv-preview">${lastMsg}</div>
          </div>
          <div class="conv-meta">
            <span class="conv-time">${formatTime(c.last_time)}</span>
            ${unread}
          </div>
        </div>`;
    }).join('');
}

function filterConvs() { loadConversations(true); }

// ===== OPEN CONV =====
async function openConversation(convId, contactId, type = 'direct') {
    currentConvId    = convId;
    currentContactId = contactId;
    currentConvType  = type;
    lastMsgId        = 0;

    document.getElementById('emptyState').style.display  = 'none';
    document.getElementById('statusPanel').style.display = 'none';
    document.getElementById('chatWindow').style.display  = 'flex';

    if (window.innerWidth <= 768) {
        document.getElementById('sidebar').classList.add('hidden');
        document.getElementById('backBtn').style.display = 'flex';
    }

    document.querySelectorAll('.conv-item').forEach(i => i.classList.remove('active'));
    document.querySelector(`.conv-item[onclick*="openConversation(${convId},"]`)?.classList.add('active');

    const isGroup = type === 'group';
    document.getElementById('menuSpam').style.display       = isGroup ? 'none' : '';
    document.getElementById('menuBlock').style.display      = isGroup ? 'none' : '';
    document.getElementById('menuGroupInfo').style.display  = isGroup ? '' : 'none';
    document.getElementById('menuLeaveGroup').style.display = isGroup ? '' : 'none';
    const callBtn = document.getElementById('callBtn');
    if (callBtn) callBtn.style.display = (!isGroup && contactId) ? 'flex' : 'none';

    const r = await api('get_conv_info', { conv_id: convId });
    if (r.error) { showToast(r.error, 'error'); return; }
    if (r.info) {
        const i = r.info;
        const av = document.getElementById('chatAvatar');
        av.src = `uploads/avatars/${i.avatar||'default.svg'}`;
        av.className = isGroup ? 'avatar group-avatar' : 'avatar';
        document.getElementById('chatContactName').textContent = i.name || '';
        const sEl = document.getElementById('chatContactStatus');
        if (isGroup) {
            sEl.textContent = `${r.member_count||''} membres`;
            sEl.className = 'chat-contact-status';
        } else {
            sEl.textContent = i.status === 'online' ? 'En ligne' : `Vu ${formatTime(i.last_seen)}`;
            sEl.className = 'chat-contact-status' + (i.status==='online'?' online':'');
        }
        const isSpam = (r.my_status === 'spam');
        document.getElementById('requestBanner').style.display  = (r.my_status==='pending'&&!isGroup) ? 'block' : 'none';
        document.getElementById('blockedBanner').style.display   = (r.is_blocked&&!isGroup) ? 'block' : 'none';
        const spamBanner = document.getElementById('spamBanner');
        if (spamBanner) spamBanner.style.display = (isSpam&&!isGroup) ? 'block' : 'none';
        const showInput = !r.is_blocked && !isSpam && (r.my_status==='accepted'||isGroup);
        document.getElementById('chatInputArea').style.display  = showInput ? 'flex' : 'none';
        document.getElementById('voiceRecording').style.display = 'none';
    }
    loadMessages();
}

function closeChat() {
    document.getElementById('sidebar').classList.remove('hidden');
    document.getElementById('chatWindow').style.display = 'none';
    document.getElementById('emptyState').style.display = 'flex';
    currentConvId = null;
    if (window.innerWidth <= 768) document.getElementById('backBtn').style.display = 'none';
}

// ===== MESSAGES =====
async function loadMessages() {
    const area = document.getElementById('messagesArea');
    area.innerHTML = '<div class="loading-msgs"><i class="fa-solid fa-spinner fa-spin"></i></div>';
    const r = await api('get_messages', { conv_id: currentConvId });
    area.innerHTML = '';
    if (r.error) { area.innerHTML = `<div class="loading-msgs" style="color:var(--danger)">${esc(r.error)}</div>`; return; }
    if (r.messages?.length) {
        renderMessages(r.messages, area);
        lastMsgId = Math.max(...r.messages.map(m => m.id));
        scrollBottom();
    }
    api('mark_read', { conv_id: currentConvId });
}

let _polling = false; // Mutex pour éviter les polls simultanés
async function pollMessages() {
    if (!currentConvId || _polling) return;
    _polling = true;
    try {
        const r = await api('get_new_messages', { conv_id: currentConvId, after_id: lastMsgId||0 });
        if (r.messages?.length) {
            const area = document.getElementById('messagesArea');
            // Filtrer les messages déjà dans le DOM (envoyés localement)
            const newMsgs = r.messages.filter(m => !document.getElementById('msg-' + m.id));
            if (newMsgs.length) {
                renderMessages(newMsgs, area, true);
                scrollBottom();
                api('mark_read', { conv_id: currentConvId });
                loadConversations(true);
            }
            // Toujours mettre à jour lastMsgId même si pas de nouveaux msgs affichés
            lastMsgId = Math.max(...r.messages.map(m => m.id));
        }
    } finally {
        _polling = false;
    }
}

function renderMessages(msgs, container, append = false) {
    let lastDate = null, html = '';
    msgs.forEach(msg => {
        const d = (msg.sent_at||'').split(' ')[0];
        if (d !== lastDate) { html += `<div class="date-separator"><span>${formatDate(msg.sent_at)}</span></div>`; lastDate = d; }
        html += buildMsgHtml(msg);
    });
    if (append) container.insertAdjacentHTML('beforeend', html);
    else container.innerHTML = html;
}

// ===== BUILD MSG HTML =====
function buildMsgHtml(msg) {
    const isOut = msg.sender_id == CURRENT_USER_ID;
    const dir   = isOut ? 'out' : 'in';

    if (msg.type === 'system') {
        return `<div class="msg-wrap msg-system"><span>${esc(msg.content)}</span></div>`;
    }
    if (msg.is_deleted) {
        return `<div class="msg-wrap ${dir}" id="msg-${msg.id}">
          <div class="msg-bubble"><div class="msg-deleted"><i class="fa-solid fa-ban"></i> Message supprimé</div></div>
        </div>`;
    }

    let content = '';

    // Citation réponse
    if (msg.reply_to) {
        const replyText = msg.reply_content
            ? esc(msg.reply_content.substring(0,60))
            : (msg.reply_type && msg.reply_type !== 'text' ? `<em>${({image:'📷 Photo',video:'🎥 Vidéo',file:'📎 Fichier',voice:'🎤 Vocal'}[msg.reply_type]||'Média')}</em>` : '');
        content += `<div class="reply-quote" onclick="scrollToMsg(${msg.reply_to})">
            <div class="reply-quote-sender">${esc(msg.reply_sender||'')}</div>
            <div class="reply-quote-text">${replyText}</div>
        </div>`;
    }

    // Corps du message
    if (msg.type === 'image') {
        content += `<div class="msg-image"><img src="${esc(msg.file_path)}" loading="lazy" onclick="openImgViewer('${esc(msg.file_path)}')"></div>`;
    } else if (msg.type === 'video') {
        content += `<div class="msg-video"><video controls src="${esc(msg.file_path)}" preload="metadata"></video></div>`;
    } else if (msg.type === 'file') {
        content += `<div class="msg-file">
            <div class="msg-file-icon"><i class="fa-solid fa-file"></i></div>
            <div class="msg-file-info">
                <div class="msg-file-name">${esc(msg.file_name||'Fichier')}</div>
                <div class="msg-file-size">${formatFileSize(msg.file_size)}</div>
            </div>
            <a href="${esc(msg.file_path)}" download class="icon-btn" title="Télécharger"><i class="fa-solid fa-download"></i></a>
        </div>`;
    } else if (msg.type === 'voice') {
        const dur = msg.duration ? formatDuration(msg.duration) : '0:00';
        content += buildVoicePlayer(msg.id, msg.file_path, dur, msg.duration || 0);
    } else {
        content += `<div class="msg-text">${esc(msg.content||'').replace(/\n/g,'<br>')}</div>`;
    }

    // Réactions groupées
    let reactionsHtml = '';
    if (msg.reactions?.length) {
        const grouped = {};
        msg.reactions.forEach(r => {
            if (!grouped[r.emoji]) grouped[r.emoji] = { count:0, mine:false };
            grouped[r.emoji].count++;
            if (r.user_id == CURRENT_USER_ID) grouped[r.emoji].mine = true;
        });
        reactionsHtml = `<div class="msg-reactions">` +
            Object.entries(grouped).map(([e,d]) =>
                `<span class="reaction-badge${d.mine?' mine':''}" onclick="quickReact(${msg.id},'${e}')">${e}<span class="reaction-count">${d.count}</span></span>`
            ).join('') + `</div>`;
    }

    const edited = msg.is_edited ? '<span class="msg-edited"> · modifié</span>' : '';
    const check  = isOut ? '<i class="fa-solid fa-check-double msg-check"></i>' : '';
    const senderName = (!isOut && currentConvType==='group')
        ? `<div class="msg-sender-name">${esc(msg.sender_name||'')}</div>` : '';
    const av = msg.sender_avatar||'default.svg';
    const avatarEl = !isOut
        ? `<img src="uploads/avatars/${av}" class="msg-avatar" onerror="this.src='uploads/avatars/default.svg'">` : '';

    return `<div class="msg-wrap ${dir}" id="msg-${msg.id}">
      ${avatarEl}
      <div class="msg-body">
        ${senderName}
        <div class="msg-bubble-wrap">
          <div class="msg-bubble" oncontextmenu="showCtxMenu(event,${msg.id},${isOut?1:0})" ontouchstart="touchStartMsg(event,${msg.id},${isOut?1:0})" ontouchend="touchEndMsg()">
            ${content}
            <div class="msg-meta">
              <span class="msg-time">${formatTime(msg.sent_at)}${edited}</span>
              ${check}
            </div>
          </div>
          <button class="msg-react-btn" onclick="openReactionPicker(event,${msg.id})" title="Réagir">
            <i class="fa-regular fa-face-smile"></i>
          </button>
        </div>
        ${reactionsHtml}
      </div>
    </div>`;
}

// ===== VOICE PLAYER =====

function buildVoicePlayer(msgId, filePath, duration, rawDuration) {
    const seed = parseInt(msgId) || 1;
    const bars = Array.from({length: 18}, (_, i) => {
        const h = 4 + Math.abs(Math.sin(seed * 0.4 + i * 0.9) * 14);
        return `<div class="vbar" style="height:${Math.round(h)}px"></div>`;
    }).join('');

    // On encode le chemin en base64 pour éviter tout problème de guillemets
    const safeId = parseInt(msgId);
    const knownDuration = (typeof rawDuration === 'number' && isFinite(rawDuration) && rawDuration > 0) ? rawDuration : 0;

    const html = `<div class="msg-voice" id="vp-${safeId}" data-known-duration="${knownDuration}">
        <button class="vpbtn" id="vpb-${safeId}" type="button" onclick="vPlay(${safeId})">
            <i class="fa-solid fa-play" id="vpi-${safeId}"></i>
        </button>
        <div class="vwave" onclick="vPlay(${safeId})">${bars}</div>
        <span class="vtime" id="vt-${safeId}">${duration}</span>
        <audio id="vau-${safeId}" preload="metadata"
            onloadedmetadata="vMeta(${safeId})"
            ontimeupdate="vUpdate(${safeId})"
            onended="vEnd(${safeId})"
            onerror="vErr(${safeId})"></audio>
    </div>`;
    // Initialiser le src après rendu (requestAnimationFrame assure que le DOM est prêt)
    requestAnimationFrame(function() {
        var a = document.getElementById("vau-" + safeId);
        if (a && !a.getAttribute("src")) {
            a.setAttribute("src", filePath);
            a.load();
        }
    });
    return html;
}

// Récupère une durée fiable : celle de l'audio si elle est finie, sinon celle connue côté serveur (fallback pour le bug Chrome/Android où audio.duration vaut Infinity sur certains blobs webm)
function vGetDuration(msgId, audio) {
    if (audio && isFinite(audio.duration) && audio.duration > 0) return audio.duration;
    var wrap = document.getElementById("vp-" + msgId);
    var known = wrap ? parseFloat(wrap.getAttribute("data-known-duration")) : 0;
    return (known && isFinite(known)) ? known : 0;
}

// Initialiser le src séparément (évite les problèmes de guillemets dans href)
window._voiceSrcs = window._voiceSrcs || {};

function vPlay(msgId) {
    var audio = document.getElementById("vau-" + msgId);
    var icon  = document.getElementById("vpi-" + msgId);
    var wrap  = document.getElementById("vp-"  + msgId);
    if (!audio) { console.error("Audio element not found: vau-" + msgId); return; }

    // Arrêter les autres
    document.querySelectorAll(".msg-voice.vplaying").forEach(function(v) {
        var oid = v.id.replace("vp-", "");
        if (oid != msgId) {
            var oa = document.getElementById("vau-" + oid);
            var oi = document.getElementById("vpi-" + oid);
            if (oa) oa.pause();
            if (oi) { oi.className = "fa-solid fa-play"; }
            v.classList.remove("vplaying");
        }
    });

    if (audio.paused) {
        audio.play().then(function() {
            if (icon) icon.className = "fa-solid fa-pause";
            if (wrap) wrap.classList.add("vplaying");
        }).catch(function(e) {
            console.error("Erreur audio:", e.message);
            showToast("Erreur lecture : " + e.message, "error");
        });
    } else {
        audio.pause();
        if (icon) icon.className = "fa-solid fa-play";
        if (wrap) wrap.classList.remove("vplaying");
    }
}

function vMeta(msgId) {
    var audio = document.getElementById("vau-" + msgId);
    var label = document.getElementById("vt-"  + msgId);
    if (!audio || !label) return;
    var total = vGetDuration(msgId, audio);
    if (total > 0) label.textContent = formatDuration(Math.floor(total));
}

function vUpdate(msgId) {
    var audio = document.getElementById("vau-" + msgId);
    var label = document.getElementById("vt-"  + msgId);
    var wrap  = document.getElementById("vp-"  + msgId);
    if (!audio || !label) return;
    var total = vGetDuration(msgId, audio);
    var rem   = total - audio.currentTime;
    label.textContent = formatDuration(Math.floor(rem > 0 ? rem : 0));
    if (wrap && total > 0) {
        var pct  = audio.currentTime / total;
        var bars = wrap.querySelectorAll(".vbar");
        bars.forEach(function(b, i) {
            b.style.opacity = (i / bars.length < pct) ? "1" : "0.35";
        });
    }
}

function vEnd(msgId) {
    var audio = document.getElementById("vau-" + msgId);
    var icon  = document.getElementById("vpi-" + msgId);
    var wrap  = document.getElementById("vp-"  + msgId);
    var label = document.getElementById("vt-"  + msgId);
    if (audio) { audio.currentTime = 0; }
    if (icon)  { icon.className = "fa-solid fa-play"; }
    if (wrap)  {
        wrap.classList.remove("vplaying");
        wrap.querySelectorAll(".vbar").forEach(function(b) { b.style.opacity = "0.35"; });
    }
    if (audio && label) {
        var total = vGetDuration(msgId, audio);
        if (total > 0) label.textContent = formatDuration(Math.floor(total));
    }
}

function vErr(msgId) {
    var btn  = document.getElementById("vpb-" + msgId);
    var icon = document.getElementById("vpi-" + msgId);
    console.error("Audio error for msg", msgId);
    if (icon) icon.className = "fa-solid fa-triangle-exclamation";
    if (btn)  { btn.style.opacity = "0.5"; btn.title = "Fichier introuvable"; }
}

// ===== ENVOYER TEXTE =====
async function sendMessage() {
    const input = document.getElementById('msgInput');
    const text  = input.value.trim();
    if (!text || !currentConvId) return;
    input.value = ''; autoResize(input); handleInputChange();
    const r = await api('send_message', { conv_id: currentConvId, content: text, reply_to: replyToId||'' });
    cancelReply();
    if (r.error) { showToast(r.error, 'error'); return; }
    if (r.message) {
        // Éviter le doublon : si le message est déjà affiché par le poll, ne pas le rajouter
        if (!document.getElementById('msg-' + r.message.id)) {
            appendMessage(r.message);
        }
        // Mettre à jour lastMsgId pour que le poll ne re-fetch pas ce message
        lastMsgId = Math.max(lastMsgId, r.message.id);
        loadConversations(true);
    }
}

function handleMsgKey(e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); } }

// ===== ENVOYER FICHIER =====
async function sendFile(input) {
    const file = input.files[0];
    if (!file || !currentConvId) return;
    if (file.size > 50*1024*1024) { showToast('Fichier trop lourd (max 50MB)', 'error'); return; }
    document.getElementById('attachMenu').style.display = 'none';
    showToast('Envoi en cours…', '');
    const fd = new FormData();
    fd.append('action', 'send_file');
    fd.append('conv_id', currentConvId);
    fd.append('file', file);
    if (replyToId) fd.append('reply_to', replyToId);
    const r = await safeJson(await fetch('api.php', {method:'POST',body:fd}));
    input.value = ''; cancelReply();
    if (r.message) { appendMessage(r.message); showToast('Fichier envoyé!', 'success'); loadConversations(true); }
    else showToast(r.error||'Erreur envoi', 'error');
}

function appendMessage(msg) {
    const area = document.getElementById('messagesArea');
    const html = buildMsgHtml(msg);
    area.insertAdjacentHTML('beforeend', html);
    // Attacher listener au bouton vocal si présent
    lastMsgId = Math.max(lastMsgId, msg.id);
    scrollBottom();
}

// ===== ENREGISTREMENT VOCAL =====
async function toggleVoiceRecord() {
    if (!navigator.mediaDevices?.getUserMedia) { showToast('Microphone non disponible', 'error'); return; }
    try {
        voiceStream  = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(voiceStream, { mimeType: getSupportedMimeType() });
        audioChunks  = [];
        mediaRecorder.ondataavailable = e => { if (e.data.size > 0) audioChunks.push(e.data); };
        mediaRecorder.start(100);
        voiceSeconds = 0;
        document.getElementById('chatInputArea').style.display  = 'none';
        document.getElementById('voiceRecording').style.display = 'flex';
        voiceTimer = setInterval(() => {
            voiceSeconds++;
            document.getElementById('voiceRecTime').textContent = formatDuration(voiceSeconds);
        }, 1000);
    } catch(e) { showToast('Accès micro refusé', 'error'); }
}

function getSupportedMimeType() {
    const types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
    for (const t of types) { if (MediaRecorder.isTypeSupported(t)) return t; }
    return '';
}

function cancelVoice() {
    stopRecording();
    audioChunks = [];
    document.getElementById('chatInputArea').style.display  = 'flex';
    document.getElementById('voiceRecording').style.display = 'none';
}

async function sendVoice() {
    stopRecording();
    document.getElementById('chatInputArea').style.display  = 'flex';
    document.getElementById('voiceRecording').style.display = 'none';

    if (!audioChunks.length) { showToast('Enregistrement vide', 'error'); return; }

    // Attendre que MediaRecorder finisse
    await new Promise(res => setTimeout(res, 200));

    const mimeType = mediaRecorder?.mimeType || 'audio/webm';
    const ext      = mimeType.includes('ogg') ? 'ogg' : (mimeType.includes('mp4') ? 'mp4' : 'webm');
    const blob     = new Blob(audioChunks, { type: mimeType });

    if (blob.size < 100) { showToast('Enregistrement trop court', 'error'); return; }

    const fd = new FormData();
    fd.append('action',   'send_voice');
    fd.append('conv_id',  currentConvId);
    fd.append('duration', voiceSeconds);
    fd.append('voice',    blob, `voice.${ext}`);
    if (replyToId) fd.append('reply_to', replyToId);

    showToast('Envoi vocal…', '');
    const r = await safeJson(await fetch('api.php', {method:'POST', body:fd}));
    cancelReply();
    if (r.message) { appendMessage(r.message); showToast('Message vocal envoyé!', 'success'); loadConversations(true); }
    else showToast(r.error||'Erreur envoi vocal', 'error');
}

function stopRecording() {
    clearInterval(voiceTimer);
    try { if (mediaRecorder?.state !== 'inactive') mediaRecorder?.stop(); } catch(e) {}
    voiceStream?.getTracks().forEach(t => t.stop());
}

// ===== RÉPONDRE =====
function startReply(msgId) {
    replyToId = msgId;
    const el  = document.getElementById(`msg-${msgId}`);
    const txt = el?.querySelector('.msg-text')?.textContent
              || el?.querySelector('.msg-file-name')?.textContent
              || (el?.querySelector('.msg-voice') ? '🎤 Message vocal'
              : el?.querySelector('.msg-image')  ? '📷 Photo'
              : 'Message');
    document.getElementById('replyPreviewText').textContent = (typeof txt === 'string' ? txt : 'Message').substring(0,60);
    document.getElementById('replyPreview').style.display   = 'flex';
    document.getElementById('msgInput').focus();
    closeAllMenus();
}
function cancelReply() {
    replyToId = null;
    document.getElementById('replyPreview').style.display = 'none';
}

// ===== MODIFIER =====
function editMsg() {
    closeAllMenus();
    const msgEl  = document.getElementById(`msg-${selectedMsgId}`);
    const textEl = msgEl?.querySelector('.msg-text');
    if (!textEl) { showToast('Seuls les messages texte sont modifiables', 'error'); return; }
    editingMsgId = selectedMsgId;
    const input  = document.getElementById('msgInput');
    input.value  = textEl.textContent;
    autoResize(input);
    input.focus();
    document.getElementById('editBanner').style.display = 'flex';
    handleInputChange();
    document.getElementById('sendBtn').onclick = confirmEdit;
}

async function confirmEdit() {
    const content = document.getElementById('msgInput').value.trim();
    if (!content || !editingMsgId) return;
    const r = await api('edit_message', { msg_id: editingMsgId, content });
    if (r.success) { await refreshMsg(editingMsgId); showToast('Message modifié', 'success'); }
    else showToast(r.error||'Erreur', 'error');
    cancelEdit();
}

function cancelEdit() {
    editingMsgId = null;
    document.getElementById('editBanner').style.display = 'none';
    document.getElementById('msgInput').value = '';
    handleInputChange();
    document.getElementById('sendBtn').onclick = sendMessage;
}

// ===== SUPPRIMER MESSAGE =====
async function deleteMsg() {
    closeAllMenus();
    if (!confirm('Supprimer ce message pour tout le monde ?')) return;
    const r = await api('delete_message', { msg_id: selectedMsgId });
    if (r.success) {
        await refreshMsg(selectedMsgId);
        showToast('Message supprimé', 'success');
    } else showToast(r.error||'Erreur', 'error');
}

async function copyMsg() {
    closeAllMenus();
    const el = document.getElementById(`msg-${selectedMsgId}`)?.querySelector('.msg-text');
    if (el) {
        try { await navigator.clipboard.writeText(el.textContent); showToast('Copié!', 'success'); }
        catch(e) { showToast('Impossible de copier', 'error'); }
    }
}

// ===== RÉACTIONS =====
function openReactionPicker(e, msgId) {
    e.stopPropagation();
    selectedMsgId = msgId;

    // Fermer picker existant si même message
    const existing = document.getElementById('reactionPicker');
    if (existing.dataset.msgid == msgId && existing.style.display === 'flex') {
        existing.style.display = 'none';
        return;
    }

    existing.dataset.msgid   = msgId;
    existing.style.display   = 'flex';

    // Positionnement
    const btnRect = e.target.closest('button').getBoundingClientRect();
    const pw = 280, ph = 50;
    let left = btnRect.left;
    let top  = btnRect.top - ph - 8;
    if (left + pw > window.innerWidth) left = window.innerWidth - pw - 8;
    if (top < 8) top = btnRect.bottom + 8;
    existing.style.left = left + 'px';
    existing.style.top  = top  + 'px';
}

async function sendReaction(emoji) {
    document.getElementById('reactionPicker').style.display = 'none';
    if (!selectedMsgId) return;
    await api('react_message', { msg_id: selectedMsgId, emoji });
    refreshMsg(selectedMsgId);
}

async function quickReact(msgId, emoji) {
    await api('react_message', { msg_id: msgId, emoji });
    refreshMsg(msgId);
}

async function refreshMsg(msgId) {
    const r = await api('get_message', { msg_id: msgId });
    if (r.message) {
        const el = document.getElementById(`msg-${msgId}`);
        if (el) {
            el.outerHTML = buildMsgHtml(r.message);
        }
    }
}

// ===== CONTEXT MENU =====
let touchTimerMsg = null;
function showCtxMenu(e, msgId, isOwn) {
    e.preventDefault();
    selectedMsgId = msgId;
    const menu = document.getElementById('msgContextMenu');
    document.getElementById('editMsgBtn').style.display   = isOwn ? 'flex' : 'none';
    document.getElementById('deleteMsgBtn').style.display = isOwn ? 'flex' : 'none';
    document.getElementById('copyMsgBtn').style.display   = 'flex';
    document.getElementById('replyMsgBtn').style.display  = 'flex';
    menu.style.display = 'block';
    const x = Math.min(e.clientX||150, window.innerWidth-200);
    const y = Math.min(e.clientY||200, window.innerHeight-200);
    menu.style.left = x + 'px';
    menu.style.top  = y + 'px';
}
function touchStartMsg(e, msgId, isOwn) {
    touchTimerMsg = setTimeout(() => {
        showCtxMenu({ clientX: e.touches[0].clientX, clientY: e.touches[0].clientY, preventDefault:()=>{} }, msgId, isOwn);
    }, 600);
}
function touchEndMsg() { clearTimeout(touchTimerMsg); }
function replyToMsg()  { closeAllMenus(); startReply(selectedMsgId); }

// ===== CONV MENU =====
function toggleConvMenu() {
    const m = document.getElementById('convMenu');
    m.style.display = m.style.display === 'block' ? 'none' : 'block';
}
function toggleAttachMenu() {
    const m = document.getElementById('attachMenu');
    m.style.display = m.style.display === 'block' ? 'none' : 'block';
}
function handleGlobalClick(e) {
    if (!e.target.closest('#msgContextMenu'))    document.getElementById('msgContextMenu').style.display = 'none';
    if (!e.target.closest('#reactionPicker') && !e.target.closest('.msg-react-btn'))
        document.getElementById('reactionPicker').style.display = 'none';
    if (!e.target.closest('.chat-header-right')) document.getElementById('convMenu').style.display = 'none';
    if (!e.target.closest('.input-left'))        document.getElementById('attachMenu').style.display = 'none';
}
function closeAllMenus() {
    ['msgContextMenu','reactionPicker','convMenu','attachMenu'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
}

// ===== ACTIONS CONVERSATION =====
async function acceptConv() {
    const r = await api('update_conv_status', { conv_id: currentConvId, status:'accepted' });
    if (r.success) {
        document.getElementById('requestBanner').style.display = 'none';
        document.getElementById('chatInputArea').style.display = 'flex';
        showToast('Conversation acceptée', 'success');
        loadConversations(true);
    }
}
async function markAsSpam() {
    closeAllMenus();
    const r = await api('update_conv_status', { conv_id: currentConvId, status:'spam' });
    if (r.success) { showToast('Marqué comme spam',''); closeChat(); loadConversations(); }
}
async function blockContact() {
    closeAllMenus();
    if (!confirm('Bloquer ce contact ?')) return;
    await api('block_user', { blocked_id: currentContactId });
    showToast('Contact bloqué',''); closeChat(); loadConversations();
}
async function unblockContact() {
    await api('unblock_user', { blocked_id: currentContactId });
    document.getElementById('blockedBanner').style.display = 'none';
    document.getElementById('chatInputArea').style.display = 'flex';
    showToast('Contact débloqué','success');
}
async function deleteConv() {
    closeAllMenus();
    if (!confirm('Supprimer cette conversation ?')) return;
    await api('delete_conversation', { conv_id: currentConvId });
    showToast('Conversation supprimée',''); closeChat(); loadConversations();
}
async function leaveGroup() {
    closeAllMenus();
    if (!confirm('Quitter ce groupe ?')) return;
    const r = await api('leave_group', { conv_id: currentConvId });
    if (r.success) { showToast('Vous avez quitté le groupe',''); closeChat(); loadConversations(); }
}

// ===== NOUVELLE CONVERSATION =====
function openNewConv() {
    document.getElementById('newContactPhone').value    = '';
    document.getElementById('newContactNickname').value = '';
    document.getElementById('newConvError').style.display   = 'none';
    document.getElementById('newConvPreview').style.display = 'none';
    openModal('newConvModal');
}
async function lookupPhone() {
    const phone = document.getElementById('newContactPhone').value.trim();
    if (!phone) return;
    const r = await api('lookup_phone', { phone });
    const preview = document.getElementById('newConvPreview');
    const errEl   = document.getElementById('newConvError');
    if (r.user) {
        document.getElementById('previewAvatar').src        = `uploads/avatars/${r.user.avatar||'default.svg'}`;
        document.getElementById('previewName').textContent  = r.user.name;
        document.getElementById('previewPhone').textContent = r.user.phone;
        preview.style.display = 'flex'; errEl.style.display = 'none';
    } else {
        preview.style.display = 'none';
        errEl.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> Numéro non inscrit sur HAFATRA.';
        errEl.style.display = 'flex';
    }
}
async function startConversation() {
    const phone    = document.getElementById('newContactPhone').value.trim();
    const nickname = document.getElementById('newContactNickname').value.trim();
    if (!phone) { showToast('Entrez un numéro','error'); return; }
    const r = await api('start_conversation', { phone, nickname });
    if (r.error) {
        const e = document.getElementById('newConvError');
        e.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i>${esc(r.error)}`;
        e.style.display = 'flex'; return;
    }
    document.getElementById('newConvModal').style.display = 'none';
    switchTab('chats');
    await loadConversations();
    openConversation(r.conv_id, r.contact_id, 'direct');
    showToast('Conversation démarrée!','success');
}

// ===== GROUPES =====
function openCreateGroup() {
    groupMembers = []; groupAvatarFile = null;
    document.getElementById('groupName').value               = '';
    document.getElementById('groupDesc').value               = '';
    document.getElementById('groupMemberPhone').value        = '';
    document.getElementById('groupMembersPreview').innerHTML = '';
    document.getElementById('groupAvatarPreview').innerHTML  = '<i class="fa-solid fa-users"></i>';
    document.getElementById('groupAvatarInput').onchange = e => {
        groupAvatarFile = e.target.files[0];
        if (groupAvatarFile) {
            const url = URL.createObjectURL(groupAvatarFile);
            document.getElementById('groupAvatarPreview').innerHTML = `<img src="${url}" style="width:100%;height:100%;object-fit:cover;border-radius:50%">`;
        }
    };
    openModal('createGroupModal');
}
async function addGroupMember() {
    const phone = document.getElementById('groupMemberPhone').value.trim();
    if (!phone) return;
    if (phone === CURRENT_USER_PHONE) { showToast('Vous êtes déjà dans le groupe','error'); return; }
    if (groupMembers.find(m => m.phone===phone)) { showToast('Déjà ajouté','error'); return; }
    const r = await api('lookup_phone', { phone });
    if (!r.user) { showToast('Numéro non trouvé','error'); return; }
    groupMembers.push({ phone, name:r.user.name, avatar:r.user.avatar, id:r.user.id });
    renderGroupMembersPreview();
    document.getElementById('groupMemberPhone').value = '';
    showToast(`${r.user.name} ajouté!`,'success');
}
function renderGroupMembersPreview() {
    document.getElementById('groupMembersPreview').innerHTML = groupMembers.map((m,i) => `
        <div class="group-member-item">
            <img src="uploads/avatars/${m.avatar||'default.svg'}" class="avatar avatar-xs" onerror="this.src='uploads/avatars/default.svg'">
            <span>${esc(m.name)} <small>${esc(m.phone)}</small></span>
            <button class="icon-btn danger" onclick="removeGroupMember(${i})"><i class="fa-solid fa-xmark"></i></button>
        </div>`).join('');
}
function removeGroupMember(i) { groupMembers.splice(i,1); renderGroupMembersPreview(); }
async function createGroup() {
    const name = document.getElementById('groupName').value.trim();
    const desc = document.getElementById('groupDesc').value.trim();
    if (!name) { showToast('Nom requis','error'); return; }
    const fd = new FormData();
    fd.append('action','create_group'); fd.append('name',name); fd.append('description',desc);
    fd.append('members', JSON.stringify(groupMembers.map(m=>m.id)));
    if (groupAvatarFile) fd.append('avatar',groupAvatarFile);
    const r = await safeJson(await fetch('api.php',{method:'POST',body:fd}));
    if (r.conv_id) {
        document.getElementById('createGroupModal').style.display = 'none';
        switchTab('chats'); await loadConversations();
        openConversation(r.conv_id,0,'group'); showToast('Groupe créé!','success');
    } else showToast(r.error||'Erreur','error');
}
async function openGroupInfo() {
    closeAllMenus();
    document.getElementById('groupInfoBody').innerHTML = '<div class="loading-convs"><i class="fa-solid fa-spinner fa-spin"></i></div>';
    openModal('groupInfoModal');
    const r = await api('get_group_info', { conv_id: currentConvId });
    if (!r.info) return;
    const isAdmin = r.my_role === 'admin';
    document.getElementById('groupInfoBody').innerHTML = `
        <div class="group-info-header">
            <img src="uploads/avatars/${r.info.group_avatar||'default.svg'}" onerror="this.src='uploads/avatars/default.svg'">
            <h3>${esc(r.info.group_name)}</h3>
            ${r.info.group_description?`<p>${esc(r.info.group_description)}</p>`:''}
        </div>
        <div class="group-members-section">
            <h4>${r.members?.length||0} membres</h4>
            <div class="group-members-list">
                ${(r.members||[]).map(m=>`
                <div class="group-member-item">
                    <img src="uploads/avatars/${m.avatar||'default.svg'}" class="avatar avatar-xs" onerror="this.src='uploads/avatars/default.svg'">
                    <span>${esc(m.name)} <small>${esc(m.phone)}</small></span>
                    <span class="role-badge ${m.role==='admin'?'admin-badge':''}">${m.role==='admin'?'Admin':'Membre'}</span>
                    ${isAdmin&&m.id!=CURRENT_USER_ID?`<button class="icon-btn danger" onclick="removeMemberFromGroup(${m.id})"><i class="fa-solid fa-xmark"></i></button>`:''}
                </div>`).join('')}
            </div>
            ${isAdmin?`<button class="add-member-btn" onclick="promptAddMember()"><i class="fa-solid fa-user-plus"></i> Ajouter</button>`:''}
        </div>`;
}
async function removeMemberFromGroup(userId) {
    if (!confirm('Retirer ce membre ?')) return;
    const r = await api('remove_group_member', { conv_id:currentConvId, user_id:userId });
    if (r.success) { showToast('Membre retiré','success'); openGroupInfo(); }
}
async function promptAddMember() {
    const phone = prompt('Numéro du nouveau membre:');
    if (!phone) return;
    const r = await api('add_group_member', { conv_id:currentConvId, phone });
    if (r.success) { showToast('Membre ajouté!','success'); openGroupInfo(); }
    else showToast(r.error||'Erreur','error');
}
function openChatInfo() { if (currentConvType==='group') openGroupInfo(); }

// ===== STATUTS =====
async function loadStatusPanel() {
    const body = document.getElementById('statusPanelBody');
    body.innerHTML = '<div class="loading-convs"><i class="fa-solid fa-spinner fa-spin"></i></div>';
    const r = await api('get_statuses');
    body.innerHTML = '';
    if (r.error) { body.innerHTML = `<div class="empty-convs">${esc(r.error)}</div>`; return; }
    body.insertAdjacentHTML('beforeend', `
        <div class="my-status-card" onclick="openAddStatus()">
            <div class="my-status-avatar-wrap">
                <img src="uploads/avatars/${esc(r.my_avatar||'default.svg')}" class="avatar" onerror="this.src='uploads/avatars/default.svg'">
                <div class="add-status-ring"></div>
            </div>
            <div>
                <div style="font-weight:600">${esc(r.my_name||'Moi')}</div>
                <div style="font-size:12px;opacity:.7">${r.my_statuses>0?r.my_statuses+' statut(s) actif(s)':'Ajouter un statut'}</div>
            </div>
            <i class="fa-solid fa-plus" style="margin-left:auto;color:var(--blue)"></i>
        </div>`);
    if (r.statuses?.length) {
        body.insertAdjacentHTML('beforeend','<div class="status-section-label">Mises à jour récentes</div>');
        r.statuses.forEach(s => {
            const thumb = s.type==='text'
                ? `<div class="status-thumb-text" style="background:${s.bg_color||'#1DA1F2'}">${esc((s.content||'').substring(0,2))}</div>`
                : `<div class="status-thumb"><img src="${esc(s.file_path||'')}" style="width:42px;height:42px;object-fit:cover;border-radius:8px"></div>`;
            body.insertAdjacentHTML('beforeend',`
                <div class="status-item" onclick="viewStatus(${s.user_id})">
                    <div class="status-ring ${s.seen?'seen':''}">
                        <img src="uploads/avatars/${esc(s.avatar||'default.svg')}" class="avatar avatar-sm" onerror="this.src='uploads/avatars/default.svg'">
                    </div>
                    <div class="status-item-info">
                        <div class="status-item-name">${esc(s.name)}</div>
                        <div class="status-item-meta">${formatTime(s.created_at)}</div>
                    </div>${thumb}
                </div>`);
        });
    }
}
function closeStatusPanel() {
    document.getElementById('statusPanel').style.display='none';
    if (window.innerWidth <= 768) {
        document.getElementById('sidebar').classList.remove('hidden');
    }
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelector('[data-tab="chats"]')?.classList.add('active');
    activeTab = 'chats';
    if (!currentConvId) document.getElementById('emptyState').style.display='flex';
}
function openAddStatus() {
    statusType='text'; statusBgColor='#1DA1F2'; statusFont='normal'; statusMediaFile=null;
    document.getElementById('statusText').value='';
    document.getElementById('statusPreviewContent').textContent='Votre statut...';
    document.getElementById('statusTextPreview').style.background='#1DA1F2';
    document.querySelectorAll('.status-type-btn').forEach(b=>b.classList.remove('active'));
    document.querySelector('[data-type="text"]')?.classList.add('active');
    document.getElementById('statusTextForm').style.display='';
    document.getElementById('statusMediaForm').style.display='none';
    openModal('addStatusModal');
}
function switchStatusType(type) {
    statusType=type;
    document.querySelectorAll('.status-type-btn').forEach(b=>b.classList.remove('active'));
    document.querySelector(`[data-type="${type}"]`)?.classList.add('active');
    document.getElementById('statusTextForm').style.display=type==='text'?'':'none';
    document.getElementById('statusMediaForm').style.display=type!=='text'?'':'none';
    document.getElementById('statusMediaInput').accept=type==='video'?'video/*':'image/*';
}
function updateStatusPreview() {
    document.getElementById('statusPreviewContent').textContent=document.getElementById('statusText').value||'Votre statut...';
}
function setStatusColor(color,el) {
    statusBgColor=color;
    document.querySelectorAll('.color-swatch').forEach(s=>s.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('statusTextPreview').style.background=color;
}
function setStatusFont(font,el) {
    statusFont=font;
    document.querySelectorAll('.font-style-btn').forEach(b=>b.classList.remove('active'));
    el.classList.add('active');
    const prev=document.getElementById('statusPreviewContent');
    prev.style.fontWeight=font==='bold'?'700':'400';
    prev.style.fontStyle=font==='italic'?'italic':'normal';
    prev.style.fontFamily=font==='serif'?'Georgia':'inherit';
}
function previewStatusMedia(input) {
    statusMediaFile=input.files[0]; if (!statusMediaFile) return;
    document.getElementById('statusMediaName').textContent=statusMediaFile.name;
    const url=URL.createObjectURL(statusMediaFile);
    document.getElementById('statusMediaPreviewWrap').style.display='';
    const img=document.getElementById('statusMediaPreviewImg');
    const vid=document.getElementById('statusMediaPreviewVid');
    if (statusMediaFile.type.startsWith('image')) { img.src=url; img.style.display=''; vid.style.display='none'; }
    else { vid.src=url; vid.style.display=''; img.style.display='none'; }
}
async function publishStatus() {
    const fd=new FormData();
    fd.append('action','publish_status'); fd.append('type',statusType);
    if (statusType==='text') {
        const text=document.getElementById('statusText').value.trim();
        if (!text) { showToast('Écrivez quelque chose!','error'); return; }
        fd.append('content',text); fd.append('bg_color',statusBgColor); fd.append('font_style',statusFont);
    } else {
        if (!statusMediaFile) { showToast('Choisissez un fichier','error'); return; }
        fd.append('media',statusMediaFile);
        fd.append('content',document.getElementById('statusCaption')?.value.trim()||'');
    }
    const r=await safeJson(await fetch('api.php',{method:'POST',body:fd}));
    if (r.success) { document.getElementById('addStatusModal').style.display='none'; showToast('Statut publié!','success'); loadStatusPanel(); }
    else showToast(r.error||'Erreur','error');
}
async function viewStatus(userId) {
    const r=await api('get_user_statuses',{user_id:userId});
    if (!r.statuses?.length) return;
    statusList=r.statuses; currentStatusIdx=0;
    showStatusAt(0); document.getElementById('statusViewer').style.display='flex';
}
function showStatusAt(idx) {
    clearTimeout(statusTimer);
    if (idx<0||idx>=statusList.length) { closeStatusViewer(); return; }
    currentStatusIdx=idx;
    const s=statusList[idx];
    document.getElementById('svAvatar').src=`uploads/avatars/${s.user_avatar||'default.svg'}`;
    document.getElementById('svName').textContent=s.user_name||'';
    document.getElementById('svTime').textContent=formatTime(s.created_at);
    document.getElementById('svViews').innerHTML=`<i class="fa-solid fa-eye"></i> ${s.views||0}`;
    const fill=document.getElementById('svProgress');
    fill.style.width='0%'; fill.style.transition='none';
    setTimeout(()=>{fill.style.transition='width 5s linear'; fill.style.width='100%';},50);
    const content=document.getElementById('svContent');
    if (s.type==='text') {
        content.innerHTML=`<div class="sv-text-content" style="background:${s.bg_color||'#1DA1F2'};color:#fff">${esc(s.content||'')}</div>`;
        document.getElementById('statusViewer').style.background=s.bg_color||'#1DA1F2';
    } else if (s.type==='image') {
        content.innerHTML=`<img class="sv-img" src="${esc(s.file_path)}">${s.content?`<div class="sv-caption">${esc(s.content)}</div>`:''}`;
        document.getElementById('statusViewer').style.background='#000';
    } else {
        content.innerHTML=`<video class="sv-video" src="${esc(s.file_path)}" autoplay controls></video>${s.content?`<div class="sv-caption">${esc(s.content)}</div>`:''}`;
        document.getElementById('statusViewer').style.background='#000';
    }
    api('mark_status_viewed',{status_id:s.id});
    statusTimer=setTimeout(()=>nextStatus(),5000);
}
function nextStatus()      { showStatusAt(currentStatusIdx+1); }
function prevStatus()      { showStatusAt(currentStatusIdx-1); }
function closeStatusViewer(){ clearTimeout(statusTimer); document.getElementById('statusViewer').style.display='none'; }

// ===== PROFIL =====
function openProfile() { openModal('profileModal'); }
async function saveProfile() {
    const name=document.getElementById('profileName').value.trim();
    const bio=document.getElementById('profileBio').value.trim();
    const r=await api('update_profile',{name,bio});
    if (r.success) { showToast('Profil mis à jour!','success'); document.getElementById('myNameFooter').textContent=name; document.getElementById('profileModal').style.display='none'; }
}
async function uploadAvatar(input) {
    const file=input.files[0]; if (!file) return;
    const fd=new FormData(); fd.append('action','upload_avatar'); fd.append('avatar',file);
    const r=await safeJson(await fetch('api.php',{method:'POST',body:fd}));
    if (r.avatar) {
        const t=Date.now();
        document.getElementById('profileAvatarPreview').src=`uploads/avatars/${r.avatar}?t=${t}`;
        document.getElementById('myAvatarFooter').src=`uploads/avatars/${r.avatar}?t=${t}`;
        showToast('Photo mise à jour!','success');
    }
}

// ===== THÈME =====
async function toggleTheme() {
    const html=document.documentElement;
    const dark=html.getAttribute('data-theme')==='dark';
    html.setAttribute('data-theme',dark?'light':'dark');
    document.getElementById('themeBtn').innerHTML=dark?'<i class="fa-solid fa-moon"></i>':'<i class="fa-solid fa-sun"></i>';
    await api('toggle_theme');
}

// ===== VISIONNEUSE IMAGE =====
function openImgViewer(src) { document.getElementById('imgViewerImg').src=src; document.getElementById('imgViewer').style.display='flex'; }
function closeImgViewer()   { document.getElementById('imgViewer').style.display='none'; }

// ===== MODALS =====
function openModal(id) { document.getElementById(id).style.display='flex'; }
function closeModal(id,e) { if (e&&e.target!==document.getElementById(id)) return; document.getElementById(id).style.display='none'; }

// ===== UTILS =====
function scrollBottom() { const a=document.getElementById('messagesArea'); if(a) a.scrollTop=a.scrollHeight; }
function scrollToMsg(id) { document.getElementById(`msg-${id}`)?.scrollIntoView({behavior:'smooth',block:'center'}); }
function autoResize(el)  { el.style.height='auto'; el.style.height=Math.min(el.scrollHeight,120)+'px'; }

function showToast(msg,type='') {
    const c=document.getElementById('toastContainer');
    if (!c) return;
    const t=document.createElement('div');
    t.className=`toast ${type}`;
    const icons={success:'fa-check',error:'fa-xmark','':'fa-info'};
    t.innerHTML=`<i class="fa-solid ${icons[type]||'fa-info'}"></i>${esc(msg)}`;
    c.appendChild(t);
    setTimeout(()=>t.remove(),3500);
}

function esc(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function formatTime(dt) {
    if (!dt) return '';
    const d=new Date(dt),now=new Date(),diff=(now-d)/1000;
    if (diff<60) return 'maintenant';
    if (diff<3600) return Math.floor(diff/60)+'min';
    if (d.toDateString()===now.toDateString()) return d.toLocaleTimeString('fr',{hour:'2-digit',minute:'2-digit'});
    const y=new Date(now); y.setDate(now.getDate()-1);
    if (d.toDateString()===y.toDateString()) return 'Hier';
    return d.toLocaleDateString('fr',{day:'2-digit',month:'2-digit'});
}
function formatDate(dt) {
    if (!dt) return '';
    const d=new Date(dt),now=new Date();
    if (d.toDateString()===now.toDateString()) return "Aujourd'hui";
    const y=new Date(now); y.setDate(now.getDate()-1);
    if (d.toDateString()===y.toDateString()) return 'Hier';
    return d.toLocaleDateString('fr',{weekday:'long',day:'numeric',month:'long'});
}
function formatFileSize(b) {
    if (!b) return '';
    if (b<1024) return b+' B';
    if (b<1048576) return (b/1024).toFixed(1)+' KB';
    return (b/1048576).toFixed(1)+' MB';
}
function formatDuration(s) {
    if (!s || isNaN(s) || !isFinite(s) || s < 0) return '0:00';
    s=Math.floor(s);
    return Math.floor(s/60)+':'+(s%60<10?'0':'')+s%60;
}

// ================================================================
// SIGNALEMENT
// ================================================================
function reportContact() {
    closeAllMenus();
    if (!currentContactId) { showToast('Aucun contact à signaler', 'error'); return; }
    document.querySelectorAll('input[name="reportReason"]').forEach(r => r.checked = false);
    document.getElementById('reportDescription').value = '';
    document.getElementById('reportError').style.display = 'none';
    openModal('reportModal');
}

async function submitReport() {
    const reason = document.querySelector('input[name="reportReason"]:checked')?.value;
    const desc   = document.getElementById('reportDescription').value.trim();
    const errEl  = document.getElementById('reportError');
    errEl.style.display = 'none';

    if (!reason) {
        errEl.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> Sélectionnez une raison';
        errEl.style.display = 'flex'; return;
    }

    const r = await api('report_user', {
        reported_id: currentContactId,
        conv_id:     currentConvId,
        reason,
        description: desc
    });

    if (r.success) {
        document.getElementById('reportModal').style.display = 'none';
        showToast(r.message || 'Signalement envoyé', 'success');
    } else {
        errEl.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i>${esc(r.error||'Erreur')}`;
        errEl.style.display = 'flex';
    }
}

// ================================================================
// PARAMÈTRES
// ================================================================
async function openSettings() {
    openModal('settingsModal');
    switchSettingsTab('account');
}

function switchSettingsTab(tab) {
    document.querySelectorAll('.settings-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.settings-panel').forEach(p => { p.style.display='none'; p.classList.remove('active'); });
    document.querySelector(`.settings-tab[data-panel="${tab}"]`)?.classList.add('active');
    const panel = document.getElementById('panel' + tab.charAt(0).toUpperCase() + tab.slice(1));
    if (panel) { panel.style.display=''; panel.classList.add('active'); }

    if (tab === 'account') loadSettingsAccount();
    if (tab === 'blocked') loadBlockedUsers();
}

async function loadSettingsAccount() {
    const el = document.getElementById('settingsAccountContent');
    el.innerHTML = '<div class="loading-convs"><i class="fa-solid fa-spinner fa-spin"></i></div>';
    const r = await api('get_settings');
    if (r.error) { el.innerHTML = `<p style="color:var(--danger)">${esc(r.error)}</p>`; return; }
    const u = r.user, s = r.stats;
    el.innerHTML = `
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid var(--border)">
            <img src="uploads/avatars/${esc(u.avatar||'default.svg')}" class="avatar avatar-xl" onerror="this.src='uploads/avatars/default.svg'" style="width:64px;height:64px">
            <div>
                <div style="font-size:18px;font-weight:700">${esc(u.name)}</div>
                <div style="font-size:13px;color:var(--text3)">${esc(u.phone)}</div>
                <div style="font-size:11px;color:var(--text3);margin-top:2px">Membre depuis ${new Date(u.created_at).toLocaleDateString('fr',{day:'numeric',month:'long',year:'numeric'})}</div>
            </div>
        </div>
        <div class="settings-stats">
            <div class="stat-card"><div class="stat-num">${s.messages}</div><div class="stat-label">Messages envoyés</div></div>
            <div class="stat-card"><div class="stat-num">${s.conversations}</div><div class="stat-label">Conversations</div></div>
            <div class="stat-card"><div class="stat-num">${s.blocked}</div><div class="stat-label">Contacts bloqués</div></div>
        </div>
        <div style="margin-top:20px">
            <button class="settings-action-btn" onclick="closeModal('settingsModal');openProfile()">
                <i class="fa-solid fa-user-pen"></i> Modifier mon profil
            </button>
            <button class="settings-action-btn" onclick="switchSettingsTab('security')">
                <i class="fa-solid fa-lock"></i> Changer le mot de passe
            </button>
            <button class="settings-action-btn" onclick="toggleTheme()">
                <i class="fa-solid fa-moon"></i> Basculer thème clair/sombre
            </button>
            <a href="logout.php" class="settings-action-btn danger">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Se déconnecter
            </a>
        </div>`;
}

async function changePassword() {
    const oldPwd  = document.getElementById('pwdOld').value;
    const newPwd  = document.getElementById('pwdNew').value;
    const confirm = document.getElementById('pwdConfirm').value;
    const errEl   = document.getElementById('pwdError');
    const okEl    = document.getElementById('pwdSuccess');
    errEl.style.display = 'none';
    okEl.style.display  = 'none';

    const r = await api('change_password', { old_password: oldPwd, new_password: newPwd, confirm_password: confirm });
    if (r.success) {
        okEl.style.display = 'flex';
        document.getElementById('pwdOld').value = '';
        document.getElementById('pwdNew').value = '';
        document.getElementById('pwdConfirm').value = '';
    } else {
        errEl.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i>${esc(r.error||'Erreur')}`;
        errEl.style.display = 'flex';
    }
}

async function loadBlockedUsers() {
    const el = document.getElementById('blockedList');
    el.innerHTML = '<div class="loading-convs"><i class="fa-solid fa-spinner fa-spin"></i></div>';
    const r = await api('get_blocked_users');
    if (!r.blocked?.length) {
        el.innerHTML = '<div class="empty-convs"><i class="fa-solid fa-user-check"></i>Aucun contact bloqué</div>';
        return;
    }
    el.innerHTML = r.blocked.map(u => `
        <div class="blocked-user-item">
            <img src="uploads/avatars/${esc(u.avatar||'default.svg')}" class="avatar avatar-xs" onerror="this.src='uploads/avatars/default.svg'">
            <div class="blocked-user-info">
                <div style="font-weight:600;font-size:14px">${esc(u.name)}</div>
                <div style="font-size:12px;color:var(--text3)">${esc(u.phone)} · Bloqué ${formatTime(u.blocked_at)}</div>
            </div>
            <button class="btn-secondary" style="padding:6px 14px;font-size:12px" onclick="unblockFromSettings(${u.id},this)">
                Débloquer
            </button>
        </div>`).join('');
}

async function unblockFromSettings(userId, btn) {
    btn.disabled = true; btn.textContent = '…';
    const r = await api('unblock_user', { blocked_id: userId });
    if (r.success) {
        btn.closest('.blocked-user-item').style.opacity = '0.5';
        btn.textContent = 'Débloqué';
        showToast('Contact débloqué', 'success');
    } else {
        btn.disabled = false; btn.textContent = 'Débloquer';
    }
}

async function submitProblem() {
    const cat    = document.getElementById('problemCategory').value;
    const desc   = document.getElementById('problemDesc').value.trim();
    const errEl  = document.getElementById('problemError');
    const okEl   = document.getElementById('problemSuccess');
    errEl.style.display = 'none'; okEl.style.display = 'none';

    if (!desc) {
        errEl.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> Décrivez le problème';
        errEl.style.display = 'flex'; return;
    }

    const r = await api('report_problem', { reason: cat, description: desc });
    if (r.success) {
        okEl.style.display = 'block';
        document.getElementById('problemDesc').value = '';
    } else {
        errEl.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i>${esc(r.error||'Erreur')}`;
        errEl.style.display = 'flex';
    }
}

async function confirmDeleteAccount() {
    const pwd = prompt('Pour confirmer, entrez votre mot de passe :');
    if (!pwd) return;
    if (!confirm('⚠️ Cette action est irréversible. Supprimer votre compte ?')) return;
    const r = await api('delete_account', { password: pwd });
    if (r.success) {
        showToast('Compte supprimé', '');
        setTimeout(() => window.location.href = 'login.php', 1500);
    } else {
        showToast(r.error || 'Erreur', 'error');
    }
}
