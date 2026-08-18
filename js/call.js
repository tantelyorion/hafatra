// HAFATRA - call.js v3 | Audio + Vidéo WebRTC

(function () {
'use strict';

// ─── HELPERS accès variables de app.js ────────────────────────────────────
function getConvId()    { return window._hafatra?.convId    ?? null; }
function getContactId() { return window._hafatra?.contactId ?? null; }
function getConvType()  { return window._hafatra?.convType  ?? 'direct'; }

// ─── STATE ─────────────────────────────────────────────────────────────────
const S = { IDLE:'idle', OUT:'ringing_out', IN:'ringing_in', CONNECTED:'connected' };

let state       = S.IDLE;
let callId      = null;
let peerConn    = null;
let localStream = null;
let remoteUid   = null;
let remoteName  = null;
let remoteAv    = null;
let callConvId  = null;
let callType    = 'audio';   // 'audio' | 'video'
let isMuted     = false;
let isSpeaker   = true;
let videoOn     = true;
let callStart   = null;
let timerInt    = null;
let pollInt     = null;
let sigBuf      = [];

// ─── RTC CONFIG ────────────────────────────────────────────────────────────
// ⚠️ IMPORTANT : les serveurs STUN seuls ne suffisent PAS toujours à établir
// une connexion directe entre deux appareils (réseaux 4G/5G, NAT symétrique,
// réseaux d'entreprise...). Dans ces cas, un serveur TURN (relais) est
// nécessaire, sinon la connexion reste bloquée en "disconnected"/"failed".
// Si les appels échouent souvent hors réseau local, ajoutez vos identifiants
// TURN ci-dessous (coturn auto-hébergé, ou un service comme Twilio/Xirsys/
// Metered.ca) :
//   { urls: 'turn:VOTRE_SERVEUR:3478', username: '...', credential: '...' }
const RTC = {
    iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' },
        { urls: 'stun:stun.cloudflare.com:3478' },
        // { urls: 'turn:VOTRE_SERVEUR:3478', username: 'USER', credential: 'PASS' },
    ],
    iceCandidatePoolSize: 10,
};

// Délai de grâce (ms) avant de raccrocher automatiquement après une
// déconnexion réseau détectée — laisse le temps à ICE de se rétablir
// tout seul avant d'abandonner.
const RECONNECT_GRACE_MS = 12000;
let reconnectTimer = null;

// ─── INIT ──────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    // Nettoyer les appels fantômes dès le chargement (refresh de page)
    capi('cleanup_stale_calls');
    pollIncoming();
    pollInt = setInterval(pollIncoming, 2000);
});

async function pollIncoming() {
    if (state === S.IDLE) {
        const r = await capi('poll_incoming_call');
        if (r.call) handleIncoming(r.call);
    } else if ((state === S.OUT || state === S.CONNECTED) && callId) {
        pollSignals();
    }
}

// ─── APPEL SORTANT ─────────────────────────────────────────────────────────
window.startCall = async function (type = 'audio') {
    if (state !== S.IDLE) { toast('Vous êtes déjà en appel'); return; }

    const contactId = getContactId();
    const convType  = getConvType();
    const convId    = getConvId();

    if (!convId)                    { toast('Ouvrez une conversation d\'abord'); return; }
    if (convType !== 'direct')      { toast('Appel disponible en conversation directe uniquement'); return; }
    if (!contactId || contactId==0) { toast('Contact non identifié'); return; }
    // Vérifier HTTPS (obligatoire pour getUserMedia)
    if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
        toast('⚠️ HTTPS requis pour les appels audio/vidéo'); return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        toast('Navigateur incompatible ou HTTPS manquant'); return;
    }

    callType   = type;
    remoteUid  = contactId;
    remoteName = document.getElementById('chatContactName')?.textContent?.trim() || 'Contact';
    remoteAv   = document.getElementById('chatAvatar')?.src || '';
    callConvId = convId;

    // Initier l'appel en base
    const r = await capi('initiate_call', { callee_id: remoteUid, conv_id: callConvId, call_type: callType });
    if (r.error) { toast(r.error); return; }
    callId = r.call_id;

    // Obtenir flux local
    try {
        localStream = await navigator.mediaDevices.getUserMedia(mediaConstraints(callType));
    } catch (e) {
        // Si vidéo échoue, essayer audio seulement
        if (callType === 'video' && e.name !== 'NotAllowedError') {
            toast('Caméra indisponible — passage en audio seul');
            try {
                localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
                callType = 'audio';
            } catch (e2) {
                toast('Microphone inaccessible — vérifiez les permissions navigateur');
                await capi('update_call_status', { call_id: callId, status: 'ended' });
                callId = null; return;
            }
        } else {
            let errMsg = 'Accès refusé';
            if (e.name === 'NotFoundError')        errMsg = 'Microphone introuvable';
            else if (e.name === 'NotAllowedError')  errMsg = "Permission refusée — cliquez sur l'icône 🔒 dans la barre d'adresse et autorisez le micro/caméra";
            else if (e.name === 'NotReadableError') errMsg = 'Micro/caméra occupé par une autre application';
            toast(errMsg);
            await capi('update_call_status', { call_id: callId, status: 'ended' });
            callId = null; return;
        }
    }

    state = S.OUT;
    uiOutgoing();
    ringTone(true);
    if (callType === 'video') showLocalVid(localStream);

    // Créer PeerConnection + offer
    mkPeer();
    localStream.getTracks().forEach(t => peerConn.addTrack(t, localStream));
    const offer = await peerConn.createOffer({ offerToReceiveAudio: true, offerToReceiveVideo: callType === 'video' });
    await peerConn.setLocalDescription(offer);
    await capi('send_signal', { call_id: callId, to_user: remoteUid, signal_type: 'offer', payload: JSON.stringify(peerConn.localDescription) });

    // Timeout 40s
    setTimeout(() => { if (state === S.OUT) hangupCall('missed'); }, 40000);
};

// ─── APPEL ENTRANT ─────────────────────────────────────────────────────────
function handleIncoming(call) {
    if (state !== S.IDLE) { capi('update_call_status', { call_id: call.id, status: 'busy' }); return; }
    callId     = call.id;
    remoteUid  = call.caller_id;
    remoteName = call.caller_name;
    remoteAv   = `uploads/avatars/${call.caller_avatar || 'default.svg'}`;
    callConvId = call.conversation_id;
    callType   = call.call_type || 'audio';

    state = S.IN;
    uiIncoming();
    ringTone(false);
    setTimeout(() => { if (state === S.IN) rejectCall('missed'); }, 40000);
}

window.acceptCall = async function () {
    if (state !== S.IN) return;
    stopRing(); hideAll();

    try {
        localStream = await navigator.mediaDevices.getUserMedia(mediaConstraints(callType));
    } catch (e) {
        let e2msg = 'Accès refusé';
        if (e.name === 'NotFoundError')       e2msg = 'Micro/caméra introuvable';
        else if (e.name === 'NotAllowedError') e2msg = 'Autorisez le micro/caméra dans le navigateur';
        else if (e.name === 'NotReadableError') e2msg = 'Périphérique occupé par une autre appli';
        toast(e2msg);
        rejectCall(); return;
    }

    await capi('update_call_status', { call_id: callId, status: 'accepted' });
    mkPeer();
    localStream.getTracks().forEach(t => peerConn.addTrack(t, localStream));
    if (callType === 'video') showLocalVid(localStream);

    // Récupérer l'offer
    const r = await capi('get_signals', { call_id: callId, signal_type: 'offer' });
    if (!r.signals?.length) { toast('Erreur de connexion'); return; }
    const offer = JSON.parse(r.signals[0].payload);
    await peerConn.setRemoteDescription(new RTCSessionDescription(offer));
    for (const c of sigBuf) await peerConn.addIceCandidate(new RTCIceCandidate(c));
    sigBuf = [];

    const answer = await peerConn.createAnswer();
    await peerConn.setLocalDescription(answer);
    await capi('send_signal', { call_id: callId, to_user: remoteUid, signal_type: 'answer', payload: JSON.stringify(peerConn.localDescription) });

    connected();
};

window.rejectCall = async function (status = 'rejected') {
    if (state !== S.IN) return;
    stopRing(); hideAll();
    await capi('update_call_status', { call_id: callId, status });
    await capi('send_signal', { call_id: callId, to_user: remoteUid, signal_type: 'hangup', payload: '{}' });
    reset();
};

// ─── RACCROCHER ────────────────────────────────────────────────────────────
window.hangupCall = async function (status = 'ended') {
    if (state === S.IDLE) return;
    stopRing(); hideAll(); stopTimer();
    const dur = callStart ? Math.floor((Date.now() - callStart) / 1000) : 0;
    if (callId) {
        await capi('send_signal', { call_id: callId, to_user: remoteUid, signal_type: 'hangup', payload: '{}' });
        await capi('update_call_status', { call_id: callId, status, duration: dur });
    }
    cleanPeer();
    toast(dur > 0 ? `Appel terminé · ${fmtDur(dur)}` : 'Appel terminé');
    reset();
};

// ─── SIGNAUX ───────────────────────────────────────────────────────────────
async function pollSignals() {
    const r = await capi('poll_signals', { call_id: callId, from_user: remoteUid });
    if (!r.signals) return;
    for (const sig of r.signals) {
        if (sig.signal_type === 'hangup')     { remoteHungUp(); return; }
        if (sig.signal_type === 'answer' && state === S.OUT) await onAnswer(sig.payload);
        if (sig.signal_type === 'ice_candidate') await onIce(sig.payload);
    }
}

async function onAnswer(pl) {
    if (!peerConn) return;
    await peerConn.setRemoteDescription(new RTCSessionDescription(JSON.parse(pl)));
    for (const c of sigBuf) await peerConn.addIceCandidate(new RTCIceCandidate(c));
    sigBuf = [];
    connected();
}

async function onIce(pl) {
    const c = JSON.parse(pl);
    if (!c?.candidate) return;
    peerConn?.remoteDescription ? await peerConn.addIceCandidate(new RTCIceCandidate(c)) : sigBuf.push(c);
}

function remoteHungUp() {
    const dur = callStart ? Math.floor((Date.now() - callStart) / 1000) : 0;
    stopRing(); hideAll(); stopTimer(); cleanPeer();
    toast(dur > 0 ? `Appel terminé · ${fmtDur(dur)}` : state === S.IN ? 'Appel annulé' : 'Appel terminé');
    reset();
}

function connected() {
    state      = S.CONNECTED;
    callStart  = Date.now();
    stopRing();
    hideAll();
    if (callType === 'video') {
        // Rester dans l'overlay vidéo mais passer en mode connecté
        const statusEl = document.getElementById('videoCallStatus');
        if (statusEl) statusEl.textContent = 'En cours';
        document.getElementById('videoCallOverlay').style.display = 'flex';
    } else {
        uiBar();
    }
    startTimer();
}

// ─── PEER CONNECTION ────────────────────────────────────────────────────────
function mkPeer() {
    peerConn = new RTCPeerConnection(RTC);

    peerConn.ontrack = (e) => {
        const stream = e.streams[0];
        if (e.track.kind === 'audio') {
            const a = document.getElementById('remoteAudio');
            if (a && a.srcObject !== stream) a.srcObject = stream;
        }
        if (e.track.kind === 'video') showRemoteVid(stream);
    };

    peerConn.onicecandidate = (e) => {
        if (e.candidate && callId) {
            capi('send_signal', { call_id: callId, to_user: remoteUid, signal_type: 'ice_candidate', payload: JSON.stringify(e.candidate) });
        }
    };

    peerConn.onconnectionstatechange = () => {
        const s = peerConn.connectionState;
        console.log('[call] connectionState →', s);

        if (s === 'connected') {
            clearTimeout(reconnectTimer); reconnectTimer = null;
            const sub = document.getElementById('outCallSub');
            if (sub) sub.textContent = 'Connecté';
            const vs = document.getElementById('videoCallStatus');
            if (vs && callType === 'video' && state === S.CONNECTED) vs.textContent = 'En cours';
            hideReconnecting();
        }

        if (s === 'disconnected') {
            // Souvent temporaire (réseau, changement wifi/4G) : on affiche un
            // état "reconnexion" au lieu de raccrocher immédiatement, et on
            // laisse ICE quelques secondes pour se rétablir tout seul.
            showReconnecting();
            clearTimeout(reconnectTimer);
            reconnectTimer = setTimeout(() => {
                if (peerConn && peerConn.connectionState !== 'connected') {
                    toast('Connexion perdue');
                    hangupCall('ended');
                }
            }, RECONNECT_GRACE_MS);
        }

        if (s === 'failed') {
            clearTimeout(reconnectTimer);
            showReconnecting();
            reconnectTimer = setTimeout(() => {
                if (peerConn && peerConn.connectionState !== 'connected') {
                    toast('Connexion perdue');
                    hangupCall('ended');
                }
            }, 4000);
        }
    };

    peerConn.oniceconnectionstatechange = () => {
        console.log('[call] iceConnectionState →', peerConn.iceConnectionState);
    };
}

function cleanPeer() {
    clearTimeout(reconnectTimer); reconnectTimer = null;
    localStream?.getTracks().forEach(t => t.stop());
    localStream = null;
    peerConn?.close(); peerConn = null;
    const a = document.getElementById('remoteAudio');
    if (a) a.srcObject = null;
    // Nettoyer les éléments vidéo
    ['localVideo','remoteVideo'].forEach(id => {
        const v = document.getElementById(id);
        if (v) { v.srcObject = null; v.remove(); }
    });
    hideReconnecting();
}

// ─── ÉTAT "RECONNEXION…" ────────────────────────────────────────────────────
function showReconnecting() {
    const sub = document.getElementById('outCallSub');
    if (sub && callType !== 'video') sub.textContent = '🔄 Reconnexion…';
    const vs = document.getElementById('videoCallStatus');
    if (vs && callType === 'video') vs.textContent = '🔄 Reconnexion…';
    const bar = document.getElementById('activeCallBar');
    if (bar) bar.classList.add('reconnecting');
}
function hideReconnecting() {
    const bar = document.getElementById('activeCallBar');
    if (bar) bar.classList.remove('reconnecting');
}

// ─── CONTRÔLES ─────────────────────────────────────────────────────────────
window.toggleMute = function () {
    if (!localStream) return;
    isMuted = !isMuted;
    localStream.getAudioTracks().forEach(t => t.enabled = !isMuted);
    const icon = isMuted ? 'fa-microphone-slash' : 'fa-microphone';
    ['outMuteBtn','barMuteBtn','vidMuteBtn'].forEach(id => {
        const b = document.getElementById(id);
        if (b) { b.innerHTML = `<i class="fa-solid ${icon}"></i>`; b.classList.toggle('muted', isMuted); }
    });
    toast(isMuted ? 'Micro coupé' : 'Micro activé');
};

window.toggleSpeaker = function () {
    isSpeaker = !isSpeaker;
    const a = document.getElementById('remoteAudio');
    if (a) a.volume = isSpeaker ? 1.0 : 0.3;
    const icon = isSpeaker ? 'fa-volume-high' : 'fa-volume-xmark';
    ['outSpeakerBtn','barSpeakerBtn','vidSpeakerBtn'].forEach(id => {
        const b = document.getElementById(id);
        if (b) { b.innerHTML = `<i class="fa-solid ${icon}"></i>`; b.classList.toggle('muted', !isSpeaker); }
    });
    toast(isSpeaker ? 'Haut-parleur activé' : 'Volume réduit');
};

window.toggleVideo = function () {
    if (!localStream) return;
    videoOn = !videoOn;
    localStream.getVideoTracks().forEach(t => t.enabled = videoOn);
    const btn = document.getElementById('toggleVideoBtn');
    if (btn) {
        btn.innerHTML = videoOn ? '<i class="fa-solid fa-video"></i>' : '<i class="fa-solid fa-video-slash"></i>';
        btn.classList.toggle('muted', !videoOn);
    }
    const lv = document.getElementById('localVideo');
    if (lv) lv.style.opacity = videoOn ? '1' : '0.3';
    toast(videoOn ? 'Caméra activée' : 'Caméra désactivée');
};

window.switchCamera = async function () {
    if (!localStream) return;
    const track = localStream.getVideoTracks()[0];
    if (!track) return;
    const facing = track.getSettings().facingMode === 'user' ? 'environment' : 'user';
    try {
        const ns = await navigator.mediaDevices.getUserMedia({ video: { facingMode: facing } });
        const nt = ns.getVideoTracks()[0];
        const sender = peerConn?.getSenders().find(s => s.track?.kind === 'video');
        if (sender) await sender.replaceTrack(nt);
        track.stop();
        localStream.removeTrack(track);
        localStream.addTrack(nt);
        const lv = document.getElementById('localVideo');
        if (lv) lv.srcObject = localStream;
    } catch (e) { toast('Impossible de changer de caméra'); }
};

// ─── VIDÉO UI ───────────────────────────────────────────────────────────────
function showLocalVid(stream) {
    let v = document.getElementById('localVideo');
    if (!v) {
        v = document.createElement('video');
        v.id = 'localVideo'; v.autoplay = true; v.muted = true; v.playsInline = true;
        v.className = 'local-video';
        v.onclick = () => window.toggleVideo?.();
        document.getElementById('videoCallOverlay')?.appendChild(v);
    }
    v.srcObject = stream;
    document.getElementById('videoCallOverlay').style.display = 'flex';
    document.getElementById('videoCallName').textContent = remoteName;
    document.getElementById('videoCallStatus').textContent = 'Connexion…';
    // Tant que le flux distant n'est pas arrivé, afficher l'avatar + spinner
    // plutôt qu'un écran noir qui donne l'impression que l'appel est cassé.
    showRemotePlaceholder();
}

function showRemotePlaceholder() {
    const ph = document.getElementById('remoteVideoPlaceholder');
    if (!ph) return;
    const av = document.getElementById('remoteVideoPlaceholderAvatar');
    if (av) av.src = remoteAv || 'uploads/avatars/default.svg';
    ph.style.display = 'flex';
}
function hideRemotePlaceholder() {
    const ph = document.getElementById('remoteVideoPlaceholder');
    if (ph) ph.style.display = 'none';
}

function showRemoteVid(stream) {
    let v = document.getElementById('remoteVideo');
    if (!v) {
        v = document.createElement('video');
        v.id = 'remoteVideo'; v.autoplay = true; v.playsInline = true;
        v.className = 'remote-video';
        const overlay = document.getElementById('videoCallOverlay');
        if (overlay) overlay.insertBefore(v, overlay.firstChild);
    }
    v.srcObject = stream;
    // Masquer le placeholder dès qu'une frame vidéo est réellement affichée
    // (loadeddata garantit qu'il y a une image, pas juste un flux vide)
    v.onloadeddata = () => hideRemotePlaceholder();
    // Audio aussi
    const a = document.getElementById('remoteAudio');
    if (a && stream.getAudioTracks().length) a.srcObject = stream;
}

window.minimizeVideoCall = function () {
    document.getElementById('videoCallOverlay').style.display = 'none';
    uiBar();
};

window.maximizeCall = function () {
    if (state !== S.CONNECTED) return;
    if (callType === 'video') {
        document.getElementById('videoCallOverlay').style.display = 'flex';
        document.getElementById('videoCallStatus').textContent = callStart ? fmtDur(Math.floor((Date.now()-callStart)/1000)) : '';
        document.getElementById('activeCallBar').style.display = 'none';
    } else {
        uiOutgoing();
        document.getElementById('outCallSub').textContent = callStart ? fmtDur(Math.floor((Date.now()-callStart)/1000)) : 'En cours…';
        document.getElementById('activeCallBar').style.display = 'none';
    }
};

// ─── OVERLAY AUDIO UI ───────────────────────────────────────────────────────
function uiOutgoing() {
    set('outCallName', remoteName);
    setImg('outCallAvatar', remoteAv);
    set('outCallSub', callType === 'video' ? '📹 Appel vidéo…' : 'Sonnerie…');
    show('outgoingCallOverlay');
    hide('incomingCallOverlay');
    hide('activeCallBar');
    if (callType !== 'video') hide('videoCallOverlay');
}

function uiIncoming() {
    set('inCallName', remoteName);
    setImg('inCallAvatar', remoteAv);
    const typeEl = document.getElementById('inCallType');
    if (typeEl) typeEl.textContent = callType === 'video' ? '📹 Appel vidéo HAFATRA' : '🎤 Appel audio HAFATRA';
    show('incomingCallOverlay');
    hide('outgoingCallOverlay');
    hide('activeCallBar');
}

function uiBar() {
    set('activeCallName', remoteName);
    setImg('activeCallAvatar', remoteAv);
    show('activeCallBar');
    hide('outgoingCallOverlay');
    hide('incomingCallOverlay');
    if (callType !== 'video') hide('videoCallOverlay');
}

function hideAll() {
    ['outgoingCallOverlay','incomingCallOverlay','activeCallBar'].forEach(hide);
    if (callType !== 'video') hide('videoCallOverlay');
}

// ─── TIMER ─────────────────────────────────────────────────────────────────
function startTimer() {
    timerInt = setInterval(() => {
        if (!callStart) return;
        const t = fmtDur(Math.floor((Date.now()-callStart)/1000));
        const el = document.getElementById('activeCallTimer');
        if (el) el.textContent = t;
        const sub = document.getElementById('outCallSub');
        if (sub && state === S.CONNECTED && callType !== 'video') sub.textContent = t;
        const vs = document.getElementById('videoCallStatus');
        if (vs && callType === 'video' && state === S.CONNECTED) vs.textContent = t;
    }, 1000);
}
function stopTimer() { clearInterval(timerInt); timerInt = null; }

// ─── SONNERIE ──────────────────────────────────────────────────────────────
let _actx = null, _ri = null;
function ringTone(out) {
    try {
        _actx = new (window.AudioContext || window.webkitAudioContext)();
        const bip = () => {
            if (!_actx) return;
            const o = _actx.createOscillator(), g = _actx.createGain();
            o.connect(g); g.connect(_actx.destination);
            o.frequency.value = out ? 440 : 880; o.type = 'sine';
            g.gain.setValueAtTime(0.3, _actx.currentTime);
            g.gain.exponentialRampToValueAtTime(0.001, _actx.currentTime + 0.5);
            o.start(_actx.currentTime); o.stop(_actx.currentTime + 0.5);
        };
        bip(); _ri = setInterval(bip, out ? 3000 : 1200);
    } catch (e) {}
}
function stopRing() { clearInterval(_ri); _ri = null; try { _actx?.close(); } catch (e) {} _actx = null; }

// ─── RESET ─────────────────────────────────────────────────────────────────
function reset() {
    state = S.IDLE; callId = null; remoteUid = null;
    remoteName = null; remoteAv = null; callConvId = null;
    isMuted = false; videoOn = true; callStart = null; sigBuf = [];
    clearTimeout(reconnectTimer); reconnectTimer = null;
    // Cleanup video elements
    ['localVideo','remoteVideo'].forEach(id => {
        const v = document.getElementById(id);
        if (v) { v.srcObject = null; v.remove(); }
    });
    hideRemotePlaceholder();
    hide('videoCallOverlay');
}

// ─── UTILITAIRES ────────────────────────────────────────────────────────────
function mediaConstraints(type) {
    if (type === 'video') return { audio: true, video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' } };
    return { audio: true, video: false };
}
function fmtDur(s) { const m = Math.floor(s/60); return `${m}:${s%60 < 10?'0':''}${s%60}`; }
function set(id, v)    { const e=document.getElementById(id); if(e) e.textContent=v; }
function setImg(id, v) { const e=document.getElementById(id); if(e) e.src=v; }
function show(id)      { const e=document.getElementById(id); if(e) e.style.display='flex'; }
function hide(id)      { const e=document.getElementById(id); if(e) e.style.display='none'; }
function toast(msg)    { if (typeof window.showToast === 'function') window.showToast(msg,''); }

async function capi(action, data = {}) {
    try {
        const r = await fetch('call_api.php', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
            body: JSON.stringify({ action, ...data })
        });
        return await r.json();
    } catch(e) { return { error:'Réseau' }; }
}

// ─── Exposer pour app.js ─────────────────────────────────────────────────
// openConversation dans app.js met à jour les boutons d'appel
const _origOpen = window.openConversation;
window.openConversation = function(convId, contactId, type) {
    if (_origOpen) _origOpen(convId, contactId, type);
    const direct = type === 'direct' && contactId;
    ['callBtn','videoCallBtn'].forEach(id => {
        const b = document.getElementById(id);
        if (b) b.style.display = direct ? 'flex' : 'none';
    });
};

})();
