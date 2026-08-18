// HAFATRA - features.js
// Canaux, gestion membres, profil utilisateur, spam, appel vidéo
// Ce fichier s'ajoute APRÈS app.js et call.js

(function () {
'use strict';

// ─── Map membres groupe (évite JSON.stringify dans onclick) ─────────────────
window._GM = {}; // groupMembers cache
window._GM_role = 'member'; // mon rôle dans le groupe affiché

// ================================================================
// GESTION MEMBRES GROUPE
// ================================================================

window.openGroupInfo = function () {
    closeAllMenus();
    document.getElementById('groupInfoBody').innerHTML =
        '<div class="loading-convs"><i class="fa-solid fa-spinner fa-spin"></i></div>';
    openModal('groupInfoModal');
    _loadGroupInfo();
};

async function _loadGroupInfo() {
    const r = await api('get_group_info', { conv_id: currentConvId });
    if (!r.info) {
        document.getElementById('groupInfoBody').innerHTML = '<p style="padding:16px;color:var(--danger)">Erreur de chargement</p>';
        return;
    }

    // Cache membres
    window._GM = {};
    window._GM_role = r.my_role || 'member';
    (r.members || []).forEach(m => { window._GM[m.id] = m; });

    const isAdmin = r.my_role === 'admin';
    const isMod   = r.my_role === 'moderator';
    const canMgr  = isAdmin || isMod;

    const rl = { admin:'👑 Admin', moderator:'🛡️ Modérateur', member:'👤 Membre' };
    const active = (r.members||[]).filter(m => m.status==='accepted' && !m.banned_at);
    const banned = (r.members||[]).filter(m => !!m.banned_at);

    let h = `<div class="group-info-header">
        <img src="uploads/avatars/${esc(r.info.group_avatar||'default.svg')}" onerror="this.src='uploads/avatars/default.svg'">
        <h3>${esc(r.info.group_name)}</h3>
        ${r.info.group_description ? `<p>${esc(r.info.group_description)}</p>` : ''}
        <p style="font-size:12px;color:var(--text3)">${active.length} membre(s)${banned.length ? ' · '+banned.length+' banni(s)' : ''}</p>
    </div>`;

    if (isAdmin) {
        h += `<div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
            <button class="btn-secondary" style="font-size:12px;padding:6px 12px" onclick="promptAddMember()">
                <i class="fa-solid fa-user-plus"></i> Ajouter
            </button>
            <button class="btn-secondary" style="font-size:12px;padding:6px 12px;color:var(--danger);border-color:var(--danger)" onclick="_deleteGroup()">
                <i class="fa-solid fa-trash"></i> Supprimer le groupe
            </button>
        </div>`;
    }

    h += `<div class="group-members-section"><h4>Membres</h4><div class="group-members-list">`;
    active.forEach(m => {
        const clickable = canMgr && m.id != CURRENT_USER_ID;
        h += `<div class="group-member-item${clickable?' gmi-click':''}"
            ${clickable ? `onclick="openMemberActions(${m.id})"` : ''}>
            <img src="uploads/avatars/${esc(m.avatar||'default.svg')}" class="avatar avatar-xs" onerror="this.src='uploads/avatars/default.svg'">
            <span style="flex:1;font-size:13px;font-weight:600">${esc(m.name)}
                ${m.id==CURRENT_USER_ID ? '<span style="color:var(--text3);font-size:10px"> (vous)</span>' : ''}
            </span>
            <span class="role-badge ${m.role==='admin'?'admin-badge':m.role==='moderator'?'mod-badge':''}">${rl[m.role]||'Membre'}</span>
            ${clickable ? '<i class="fa-solid fa-chevron-right" style="color:var(--text3);font-size:11px;margin-left:6px"></i>' : ''}
        </div>`;
    });
    h += `</div></div>`;

    if (isAdmin && banned.length) {
        h += `<div class="group-members-section" style="margin-top:14px"><h4 style="color:var(--danger)">🚫 Bannis (${banned.length})</h4><div class="group-members-list">`;
        banned.forEach(m => {
            h += `<div class="group-member-item gmi-click" onclick="openMemberActions(${m.id})" style="opacity:.7">
                <img src="uploads/avatars/${esc(m.avatar||'default.svg')}" class="avatar avatar-xs" onerror="this.src='uploads/avatars/default.svg'">
                <span style="flex:1;font-size:13px">${esc(m.name)}</span>
                <span class="role-badge" style="background:rgba(244,33,46,.1);color:var(--danger)">Banni</span>
                ${m.ban_reason ? `<span style="font-size:10px;color:var(--text3);margin-left:4px">${esc(m.ban_reason)}</span>` : ''}
            </div>`;
        });
        h += `</div></div>`;
    }

    if (isAdmin) {
        h += `<button class="add-member-btn" onclick="promptAddMember()"><i class="fa-solid fa-user-plus"></i> Ajouter un membre</button>`;
    }

    document.getElementById('groupInfoBody').innerHTML = h;
}

window.openMemberActions = function (memberId) {
    const m = window._GM[memberId];
    if (!m) return;
    const myRole  = window._GM_role;
    const isAdmin = myRole === 'admin';
    const isMod   = myRole === 'moderator';
    const isBanned= !!m.banned_at;
    const canMgr  = (isAdmin || (isMod && m.role==='member')) && !isBanned;
    const rl = { admin:'👑 Administrateur', moderator:'🛡️ Modérateur', member:'👤 Membre' };

    let b = `<div style="display:flex;align-items:center;gap:12px;padding:12px;background:var(--bg2);border-radius:12px;margin-bottom:16px">
        <img src="uploads/avatars/${esc(m.avatar||'default.svg')}" class="avatar" onerror="this.src='uploads/avatars/default.svg'">
        <div>
            <div style="font-weight:700;font-size:15px">${esc(m.name)}</div>
            <div style="font-size:12px;color:var(--text3)">${rl[m.role]||'Membre'}</div>
            ${isBanned ? `<div style="font-size:11px;color:var(--danger);margin-top:2px">🚫 Banni${m.ban_reason?' : '+esc(m.ban_reason):''}</div>` : ''}
        </div>
    </div>`;

    // Voir profil
    b += `<button class="settings-action-btn" onclick="closeModal('memberActionModal');viewUserProfile(${m.id})">
        <i class="fa-solid fa-user"></i> Voir le profil
    </button>`;

    if (isBanned && isAdmin) {
        b += `<button class="settings-action-btn" style="color:var(--success)" onclick="_doUnban(${m.id})">
            <i class="fa-solid fa-user-check"></i> Lever le bannissement
        </button>`;
    } else if (canMgr) {
        if (isAdmin) {
            const roles = ['member','moderator','admin'].filter(r2=>r2!==m.role);
            const rl2 = { member:'👤 Membre', moderator:'🛡️ Modérateur', admin:'👑 Administrateur' };
            b += `<div style="margin:14px 0 8px;font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px">Changer le rôle</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">`;
            roles.forEach(r2 => {
                b += `<button class="btn-secondary" style="font-size:12px;padding:7px 14px" onclick="_doRole(${m.id},'${r2}')">${rl2[r2]}</button>`;
            });
            b += `</div>`;
        }
        b += `<button class="settings-action-btn" onclick="_doKick(${m.id})">
            <i class="fa-solid fa-user-xmark"></i> Exclure du groupe
        </button>
        <div style="margin-top:10px">
            <input id="banR${m.id}" type="text" placeholder="Raison du bannissement (optionnel)"
                style="width:100%;padding:9px 13px;border:1.5px solid var(--border);border-radius:10px;background:var(--bg2);font-family:inherit;font-size:13px;color:var(--text);margin-bottom:8px;outline:none;box-sizing:border-box">
            <button class="settings-action-btn danger" onclick="_doBan(${m.id})">
                <i class="fa-solid fa-ban"></i> Bannir
            </button>
        </div>`;
    }

    document.getElementById('memberActionTitle').innerHTML = `<i class="fa-solid fa-user-gear"></i> ${esc(m.name)}`;
    document.getElementById('memberActionBody').innerHTML  = b;
    openModal('memberActionModal');
};

window._doRole = async function(mid, role) {
    const r = await api('set_member_role', { conv_id: currentConvId, user_id: mid, role });
    if (r.success) { closeModal('memberActionModal'); showToast('Rôle mis à jour','success'); _loadGroupInfo(); }
    else showToast(r.error||'Erreur','error');
};
window._doKick = async function(mid) {
    if (!confirm('Exclure ce membre ?')) return;
    const r = await api('kick_member', { conv_id: currentConvId, user_id: mid });
    if (r.success) { closeModal('memberActionModal'); showToast('Membre exclu','success'); _loadGroupInfo(); }
    else showToast(r.error||'Erreur','error');
};
window._doBan = async function(mid) {
    const reason = document.getElementById('banR'+mid)?.value.trim()||'';
    if (!confirm('Bannir ce membre ?')) return;
    const r = await api('ban_member', { conv_id: currentConvId, user_id: mid, reason });
    if (r.success) { closeModal('memberActionModal'); showToast('Membre banni','success'); _loadGroupInfo(); }
    else showToast(r.error||'Erreur','error');
};
window._doUnban = async function(mid) {
    const r = await api('unban_member', { conv_id: currentConvId, user_id: mid });
    if (r.success) { closeModal('memberActionModal'); showToast('Bannissement levé','success'); _loadGroupInfo(); }
    else showToast(r.error||'Erreur','error');
};
window._deleteGroup = async function() {
    closeModal('groupInfoModal');
    if (!confirm('Supprimer définitivement ce groupe et tous ses messages ?')) return;
    const r = await api('delete_group', { conv_id: currentConvId });
    if (r.success) { showToast('Groupe supprimé','success'); closeChat(); loadConversations(); }
    else showToast(r.error||'Erreur','error');
};

// ================================================================
// PROFIL UTILISATEUR
// ================================================================

window.viewUserProfile = async function(userId) {
    if (!userId) return;
    openModal('userProfileModal');
    document.getElementById('userProfileContent').innerHTML =
        '<div class="loading-convs"><i class="fa-solid fa-spinner fa-spin"></i></div>';

    const r = await api('get_user_profile', { user_id: userId });
    if (r.error) {
        document.getElementById('userProfileContent').innerHTML =
            `<p style="padding:16px;color:var(--danger)">${esc(r.error)}</p>`;
        return;
    }
    const u = r.profile;
    const joined = new Date(u.created_at||Date.now()).toLocaleDateString('fr',{day:'numeric',month:'long',year:'numeric'});
    const online = u.status==='online';

    document.getElementById('userProfileContent').innerHTML = `
        <div style="text-align:center;padding:12px 0 20px;border-bottom:1px solid var(--border);margin-bottom:16px">
            <img src="uploads/avatars/${esc(u.avatar||'default.svg')}"
                style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid var(--blue);box-shadow:0 4px 16px rgba(29,161,242,.2)"
                onerror="this.src='uploads/avatars/default.svg'">
            <div style="font-size:20px;font-weight:800;margin-top:10px">${esc(u.name)}</div>
            <div style="font-size:13px;color:${online?'var(--success)':'var(--text3)'};margin-top:3px">
                ${online?'🟢 En ligne':'⚪ '+formatTime(u.last_seen)}
            </div>
            ${u.bio?`<div style="font-size:13px;color:var(--text2);margin-top:8px;font-style:italic">"${esc(u.bio)}"</div>`:''}
        </div>
        <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:18px">
            ${u.phone?`<div class="prof-row"><i class="fa-solid fa-phone"></i><span>${esc(u.phone)}</span></div>`:''}
            <div class="prof-row"><i class="fa-solid fa-calendar-days"></i><span>Membre depuis ${joined}</span></div>
            <div class="prof-row"><i class="fa-solid fa-comments"></i><span>${r.common_convs||0} conversation(s) en commun</span></div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            ${r.existing_conv
                ? `<button class="btn-primary" style="flex:1" onclick="closeModal('userProfileModal');openConversation(${r.existing_conv},${u.id},'direct')">
                    <i class="fa-solid fa-message"></i> Message
                   </button>`
                : (u.phone
                    ? `<button class="btn-primary" style="flex:1" onclick="_startFromProfile('${esc(u.phone)}')">
                        <i class="fa-solid fa-message"></i> Contacter
                       </button>`
                    : '')}
            ${r.is_blocked
                ? `<button class="btn-secondary" style="flex:1" onclick="_unblockFromProf(${u.id})">
                    <i class="fa-solid fa-user-check"></i> Débloquer
                   </button>`
                : `<button class="btn-secondary" style="flex:1;color:var(--danger);border-color:var(--danger)" onclick="_blockFromProf(${u.id})">
                    <i class="fa-solid fa-user-slash"></i> Bloquer
                   </button>`}
        </div>`;
};

window._startFromProfile = async function(phone) {
    closeModal('userProfileModal');
    const r = await api('start_conversation', { phone });
    if (r.conv_id) { await loadConversations(); openConversation(r.conv_id, r.contact_id, 'direct'); }
    else showToast(r.error||'Erreur','error');
};
window._blockFromProf = async function(uid) {
    if (!confirm('Bloquer cet utilisateur ?')) return;
    await api('block_user', { blocked_id: uid });
    showToast('Utilisateur bloqué','success');
    closeModal('userProfileModal');
};
window._unblockFromProf = async function(uid) {
    await api('unblock_user', { blocked_id: uid });
    showToast('Débloqué','success');
    viewUserProfile(uid);
};

// ================================================================
// CLIC HEADER CONVERSATION → profil / info groupe / info canal
// ================================================================
window.onChatHeaderClick = function() {
    if (currentConvType === 'group')   { window.openGroupInfo(); }
    else if (currentConvType === 'channel') { openChannelInfo(currentConvId); }
    else if (currentConvType === 'direct' && currentContactId) {
        window.viewUserProfile(currentContactId);
    }
};

// ================================================================
// SPAM — bannière + retirer du spam
// ================================================================

// openConversation : patch léger pour spam banner + bouton vidéo
// On utilise DOMContentLoaded pour éviter les conflits de chargement

// Pas de patch api() pour éviter boucles infinies
// Le spam banner est géré dans openConversation via l'original app.js

window.removeFromSpam = async function() {
    const r = await api('update_conv_status', { conv_id: currentConvId, status: 'accepted' });
    if (r.success) {
        const sb = document.getElementById('spamBanner');
        const ia = document.getElementById('chatInputArea');
        if (sb) sb.style.display = 'none';
        if (ia) ia.style.display = 'flex';
        showToast('Retiré du spam ✓','success');
        loadConversations(true);
    }
};

// ================================================================
// CANAUX
// ================================================================
let _chAv = null;

window.openCreateChannel = function() {
    _chAv = null;
    const np = document.getElementById('channelName');
    const dp = document.getElementById('channelDesc');
    const ap = document.getElementById('channelAvatarPreview');
    if (np) np.value = '';
    if (dp) dp.value = '';
    if (ap) ap.innerHTML = '<i class="fa-solid fa-tower-broadcast"></i>';

    const inp = document.getElementById('channelAvatarInput');
    if (inp) inp.onchange = e => {
        _chAv = e.target.files[0];
        if (_chAv) {
            const url = URL.createObjectURL(_chAv);
            ap.innerHTML = `<img src="${url}" style="width:100%;height:100%;object-fit:cover;border-radius:50%">`;
        }
    };
    openModal('createChannelModal');
};

window.createChannel = async function() {
    const name   = document.getElementById('channelName')?.value.trim();
    const desc   = document.getElementById('channelDesc')?.value.trim() || '';
    const pub    = document.querySelector('input[name="channelVisibility"]:checked')?.value || '1';
    if (!name) { showToast('Nom du canal requis','error'); return; }

    let r;
    if (_chAv) {
        const fd = new FormData();
        fd.append('action','create_channel');
        fd.append('name', name);
        fd.append('description', desc);
        fd.append('public', pub);
        fd.append('avatar', _chAv);
        r = await safeJson(await fetch('api.php',{method:'POST',body:fd}));
    } else {
        r = await api('create_channel_json', { name, description: desc, public: parseInt(pub) });
    }

    if (r.conv_id) {
        document.getElementById('createChannelModal').style.display = 'none';
        showToast('Canal créé !','success');
        loadChannels();
        openChannel(r.conv_id, name);
    } else showToast(r.error||'Erreur création','error');
};

window.loadChannels = async function() {
    const list = document.getElementById('convList');
    list.innerHTML = '<div class="loading-convs"><i class="fa-solid fa-spinner fa-spin"></i></div>';
    const r = await api('get_channels');
    if (!r.channels?.length) {
        list.innerHTML = `<div class="empty-convs">
            <i class="fa-solid fa-tower-broadcast"></i>Aucun canal<br>
            <button class="btn-primary" style="margin-top:12px;font-size:13px" onclick="openCreateChannel()">
              <i class="fa-solid fa-plus"></i> Créer un canal
            </button></div>`;
        return;
    }
    list.innerHTML = r.channels.map(c => {
        const av  = c.avatar||'default.svg';
        const sub = c.subscribed
            ? '<span style="font-size:9px;background:var(--blue-light);color:var(--blue);padding:1px 6px;border-radius:8px;font-weight:600;margin-left:4px">Abonné</span>' : '';
        return `<div class="conv-item" onclick="openChannel(${c.id},'${esc(c.name||'')}')">
          <div class="conv-avatar-wrap">
            <img src="uploads/avatars/${av}" class="avatar" style="border-radius:10px" onerror="this.src='uploads/avatars/default.svg'">
          </div>
          <div class="conv-info">
            <div class="conv-name"><i class="fa-solid fa-tower-broadcast" style="font-size:10px;color:var(--blue);margin-right:4px"></i>${esc(c.name)}${sub}</div>
            <div class="conv-preview">${c.sub_count||0} abonné(s)${c.description?' · '+esc((c.description||'').substring(0,30)):''}</div>
          </div>
          <div class="conv-meta">
            ${c.my_role==='admin'?'<span style="font-size:10px;color:var(--blue);font-weight:600">Admin</span>':''}
          </div>
        </div>`;
    }).join('');
};

window.openChannel = async function(convId, name) {
    const r = await api('get_channel_info', { conv_id: convId });
    if (!r.my_role) { await api('subscribe_channel', { channel_id: convId }); }

    currentConvId    = convId;
    currentContactId = 0;
    currentConvType  = 'channel';
    lastMsgId        = 0;

    document.getElementById('emptyState').style.display  = 'none';
    document.getElementById('statusPanel').style.display = 'none';
    document.getElementById('chatWindow').style.display  = 'flex';

    if (window.innerWidth <= 768) {
        document.getElementById('sidebar').classList.add('hidden');
        document.getElementById('backBtn').style.display = 'flex';
    }

    const info = r.info || {};
    const av = document.getElementById('chatAvatar');
    av.src = `uploads/avatars/${info.group_avatar||'default.svg'}`;
    av.style.borderRadius = '10px';
    document.getElementById('chatContactName').textContent = info.group_name || name || 'Canal';
    document.getElementById('chatContactStatus').textContent = `${r.member_count||0} abonné(s)`;

    // Boutons appel cachés
    ['callBtn','videoCallBtn'].forEach(id => {
        const b = document.getElementById(id);
        if (b) b.style.display = 'none';
    });
    // Menu
    document.getElementById('menuSpam').style.display      = 'none';
    document.getElementById('menuBlock').style.display     = 'none';
    document.getElementById('menuGroupInfo').style.display = '';
    document.getElementById('menuGroupInfo').onclick = () => openChannelInfo(convId);
    document.getElementById('menuLeaveGroup').style.display = '';
    document.getElementById('menuLeaveGroup').innerHTML = '<i class="fa-solid fa-right-from-bracket"></i> Se désabonner';
    document.getElementById('menuLeaveGroup').onclick = () => _unsubChannel(convId);

    // Saisie : admins/mods seulement
    const canWrite = r.my_role==='admin' || r.my_role==='moderator';
    document.getElementById('chatInputArea').style.display = canWrite ? 'flex' : 'none';
    const blocked = document.getElementById('blockedBanner');
    if (!canWrite && blocked) {
        blocked.innerHTML = '<i class="fa-solid fa-tower-broadcast"></i> Canal en lecture seule';
        blocked.style.display = 'block';
    } else if (blocked) blocked.style.display = 'none';

    document.getElementById('requestBanner').style.display = 'none';
    document.getElementById('spamBanner') && (document.getElementById('spamBanner').style.display = 'none');
    document.getElementById('voiceRecording').style.display = 'none';

    loadMessages();
};

window.openChannelInfo = async function(convId) {
    closeAllMenus();
    document.getElementById('groupInfoBody').innerHTML = '<div class="loading-convs"><i class="fa-solid fa-spinner fa-spin"></i></div>';
    openModal('groupInfoModal');
    const r = await api('get_channel_info', { conv_id: convId || currentConvId });
    if (!r.info) return;
    const isAdmin = r.my_role === 'admin';
    document.getElementById('groupInfoBody').innerHTML = `
        <div class="group-info-header">
            <img src="uploads/avatars/${esc(r.info.group_avatar||'default.svg')}" onerror="this.src='uploads/avatars/default.svg'">
            <h3><i class="fa-solid fa-tower-broadcast" style="color:var(--blue)"></i> ${esc(r.info.group_name)}</h3>
            ${r.info.group_description?`<p>${esc(r.info.group_description)}</p>`:''}
            <p style="font-size:12px;color:var(--text3)">${r.member_count||0} abonné(s)</p>
        </div>
        <div class="group-members-section"><h4>Membres</h4>
            <div class="group-members-list">
            ${(r.members||[]).map(m=>`
            <div class="group-member-item">
                <img src="uploads/avatars/${esc(m.avatar||'default.svg')}" class="avatar avatar-xs" onerror="this.src='uploads/avatars/default.svg'">
                <span style="flex:1;font-size:13px;font-weight:600">${esc(m.name)}</span>
                <span class="role-badge ${m.role==='admin'?'admin-badge':m.role==='moderator'?'mod-badge':''}">${{admin:'👑 Admin',moderator:'🛡️ Modér.',member:'👤 Membre'}[m.role]||'Membre'}</span>
            </div>`).join('')}
            </div>
        </div>`;
};

window._unsubChannel = async function(convId) {
    closeAllMenus();
    if (!confirm('Se désabonner de ce canal ?')) return;
    const r = await api('unsubscribe_channel', { channel_id: convId });
    if (r.success) { showToast('Désabonné',''); closeChat(); loadChannels(); }
    else showToast(r.error||'Erreur','error');
};

// ================================================================
// MISE À JOUR switchTab pour onglet Canaux
// ================================================================
const _origSwitchFeat = window.switchTab;
window.switchTab = function(tab) {
    const cBtn = document.getElementById('createChannelBtn');
    if (tab === 'channels') {
        if (cBtn) cBtn.style.display = 'flex';
        activeTab = 'channels';
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelector('[data-tab="channels"]')?.classList.add('active');
        document.getElementById('sidebarSearch').style.display = 'none';
        document.getElementById('convList').style.display      = '';
        document.getElementById('statusPanel').style.display   = 'none';
        if (!currentConvId) document.getElementById('emptyState').style.display = 'flex';
        window.loadChannels();
    } else {
        if (cBtn) cBtn.style.display = 'none';
        if (_origSwitchFeat) _origSwitchFeat(tab);
    }
};

// ================================================================
// VIDÉO — Minimiser l'overlay
// ================================================================
window.minimizeVideoCall = function() {
    const vo = document.getElementById('videoCallOverlay');
    const ab = document.getElementById('activeCallBar');
    if (vo) vo.style.display = 'none';
    if (ab) ab.style.display = 'flex';
};

// ================================================================
// CSS DYNAMIQUE pour nouvelles classes
// ================================================================
(function injectCSS() {
    const s = document.createElement('style');
    s.textContent = `
        .gmi-click { cursor:pointer; }
        .gmi-click:hover { background:var(--bg3) !important; }
        .mod-badge { background:rgba(120,86,255,.15); color:#7856ff; }
        .prof-row { display:flex; align-items:center; gap:10px; font-size:13px; color:var(--text2); }
        .prof-row i { color:var(--blue); width:16px; text-align:center; flex-shrink:0; }
        #spamBanner { background:rgba(244,33,46,.06); border-top:2px solid rgba(244,33,46,.25); padding:14px 18px; text-align:center; }
        #spamBanner p { color:var(--danger); font-size:13px; margin-bottom:10px; }
    `;
    document.head.appendChild(s);
})();

})(); // end IIFE


// ================================================================
// HOOK SUR CHARGEMENT CONV — spam banner + boutons appel
// ================================================================
document.addEventListener('DOMContentLoaded', function() {
    // Observer les changements de conversation pour spam/video btn
    const obs = new MutationObserver(function() {
        // Vérifier si spamBanner doit être caché lors d'un changement
    });

    // Surcharger openConversation une seule fois, proprement
    const _baseOC = window.openConversation;
    if (_baseOC && !window._hafatra_oc_patched) {
        window._hafatra_oc_patched = true;
        window.openConversation = async function(convId, contactId, type) {
            // Reset spam banner
            const sb = document.getElementById('spamBanner');
            if (sb) sb.style.display = 'none';
            // Appel de base
            const result = await _baseOC.call(this, convId, contactId, type);
            // Bouton vidéo
            const vb = document.getElementById('videoCallBtn');
            if (vb) vb.style.display = (type==='direct' && parseInt(contactId)>0) ? 'flex' : 'none';
            return result;
        };
    }
}, false);

// Exposer spam banner depuis get_conv_info (appelé par app.js)
const _origOpenConvBase = window.openConversation;
// On va hooker après que app.js a chargé via DOMContentLoaded
document.addEventListener('hafatra:conv_info', function(e) {
    if (e.detail && e.detail.my_status === 'spam') {
        const sb = document.getElementById('spamBanner');
        const ia = document.getElementById('chatInputArea');
        if (sb) sb.style.display = 'block';
        if (ia) ia.style.display = 'none';
    }
});
