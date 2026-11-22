/* Verrouillage de la vue mobile (V3.0) : inactivité, passage en arrière-plan, déverrouillage par PIN/biométrie,
   et PIN vérifié localement (PBKDF2) pour déverrouiller hors ligne. Les empreintes, elles, exigent le serveur. */
(function () {
  'use strict';
  var CFG = window.REV_LOCK || {};
  var DB = 'rechargeplus_lock', ST = 'kv', ITER = 150000;

  /* ---------- stockage clé/valeur (IndexedDB) ---------- */
  function open() {
    return new Promise(function (res, rej) {
      if (!window.indexedDB) { rej(new Error('indexedDB')); return; }
      var r = indexedDB.open(DB, 1);
      r.onupgradeneeded = function () { r.result.createObjectStore(ST); };
      r.onsuccess = function () { res(r.result); };
      r.onerror = function () { rej(r.error); };
    });
  }
  function kvGet(k) {
    return open().then(function (db) {
      return new Promise(function (res, rej) {
        var q = db.transaction(ST, 'readonly').objectStore(ST).get(k);
        q.onsuccess = function () { db.close(); res(q.result); };
        q.onerror = function () { db.close(); rej(q.error); };
      });
    }).catch(function () { return undefined; });
  }
  function kvSet(k, v) {
    return open().then(function (db) {
      return new Promise(function (res, rej) {
        var t = db.transaction(ST, 'readwrite');
        if (v === undefined) t.objectStore(ST).delete(k); else t.objectStore(ST).put(v, k);
        t.oncomplete = function () { db.close(); res(); };
        t.onerror = function () { db.close(); rej(t.error); };
      });
    }).catch(function () { /* stockage indisponible */ });
  }

  /* ---------- vérificateur local du PIN ---------- */
  function b64(buf) { var s = ''; new Uint8Array(buf).forEach(function (c) { s += String.fromCharCode(c); }); return btoa(s); }
  function unb64(s) { var b = atob(s), a = new Uint8Array(b.length); for (var i = 0; i < b.length; i++) a[i] = b.charCodeAt(i); return a; }
  function derive(pin, salt, iter) {
    var enc = new TextEncoder();
    return crypto.subtle.importKey('raw', enc.encode(pin), 'PBKDF2', false, ['deriveBits']).then(function (k) {
      return crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt: salt, iterations: iter }, k, 256);
    });
  }
  function makeVerifier(pin) {
    if (!window.crypto || !crypto.subtle) return Promise.resolve();
    var salt = crypto.getRandomValues(new Uint8Array(16));
    return derive(pin, salt, ITER).then(function (h) { return kvSet('verifier', { salt: b64(salt), hash: b64(h), iter: ITER, fails: 0 }); });
  }
  /** Résultat : 'ok', 'bad', 'none' (pas de vérificateur : connexion nécessaire), 'blocked'. */
  function verifyLocal(pin) {
    return kvGet('verifier').then(function (v) {
      if (!v || !window.crypto || !crypto.subtle) return 'none';
      return derive(pin, unb64(v.salt), v.iter).then(function (h) {
        if (b64(h) === v.hash) { v.fails = 0; return kvSet('verifier', v).then(function () { return 'ok'; }); }
        v.fails = (v.fails || 0) + 1;
        if (v.fails >= (CFG.maxFails || 5)) return kvSet('verifier', undefined).then(function () { return 'blocked'; });
        return kvSet('verifier', v).then(function () { return 'bad'; });
      });
    });
  }

  var minutes = CFG.minutes || 5;
  function touchWindow() { return kvSet('offline_until', Date.now() + minutes * 60000); }
  function markUnlocked() { return kvSet('locked', false).then(touchWindow); }

  window.RevLock = { makeVerifier: makeVerifier, verifyLocal: verifyLocal, markUnlocked: markUnlocked };

  function here() { var p = location.pathname.split('/').pop() || 'index.php'; return p === 'stats.php' ? 'stats.php' : 'index.php'; }
  function lockNow() {
    kvSet('locked', true).then(function () { return kvSet('offline_until', 0); }).then(function () {
      location.replace('deverrouiller.php?lock=1&next=' + here());
    });
  }
  window.RevLock.lockNow = lockNow;

  /* ---------- pages protégées ---------- */
  if (CFG.protect) {
    kvGet('locked').then(function (l) { if (l === true) lockNow(); });
    var last = 0, idle = null, hiddenAt = 0;
    var arm = function () {
      clearTimeout(idle);
      idle = setTimeout(lockNow, minutes * 60000);
      var n = Date.now();
      if (n - last > 10000) { last = n; touchWindow(); }
    };
    ['pointerdown', 'keydown', 'touchstart', 'scroll', 'input'].forEach(function (ev) { window.addEventListener(ev, arm, { passive: true }); });
    arm();
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') { hiddenAt = Date.now(); return; }
      if (hiddenAt && Date.now() - hiddenAt > (CFG.bgSeconds || 15) * 1000) lockNow(); else arm();
    });
    window.addEventListener('pagehide', function () { /* retour sur la page : contrôle via visibilitychange */ });
    var btn = document.getElementById('lock-now');
    if (btn) btn.addEventListener('click', function () { lockNow(); });
  }

  /* ---------- page de déverrouillage ---------- */
  var form = document.getElementById('unlock-form');
  if (!form) return;
  var msg = document.getElementById('unlock-msg');
  var pin = document.getElementById('pin');
  var bioBtn = document.getElementById('bio-btn');
  var next = CFG.next || 'index.php';
  function say(t, cls) { msg.textContent = t || ''; msg.className = 'msg ' + (cls || 'bad'); msg.hidden = !t; }
  function done() { markUnlocked().then(function () { location.replace(next); }); }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var v = pin.value.trim();
    if (!/^\d{4,6}$/.test(v)) { say('Saisissez votre code PIN (4 à 6 chiffres).'); return; }
    var fd = new FormData(form);
    fd.set('json', '1');
    fetch('deverrouiller.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (j.ok) { makeVerifier(v).then(done); return; }
      pin.value = '';
      if (j.locked) { say('Appareil bloqué après trop d\'échecs. Demandez le déblocage à l\'administrateur.'); form.querySelector('button').disabled = true; if (bioBtn) bioBtn.hidden = true; return; }
      say((j.error || 'Code incorrect.') + (j.remaining != null ? ' Essais restants : ' + j.remaining + '.' : ''));
    }).catch(function () {
      /* hors ligne : vérification locale */
      verifyLocal(v).then(function (r) {
        if (r === 'ok') { done(); return; }
        pin.value = '';
        if (r === 'none' || r === 'blocked') say('Hors ligne : connexion nécessaire pour déverrouiller.');
        else say('Code incorrect.');
      });
    });
  });

  /* biométrie */
  function fromB64u(s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '='; return unb64(s); }
  function toB64u(buf) { return b64(buf).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
  var bioFails = 0;
  function bioFail(t) {
    bioFails++;
    if (bioFails >= 3 && bioBtn) { bioBtn.hidden = true; say('Biométrie indisponible : utilisez votre code PIN.', 'warn'); pin.focus(); }
    else say(t);
  }
  function post(action, data) {
    var fd = new FormData(); fd.set('action', action);
    Object.keys(data || {}).forEach(function (k) { fd.set(k, data[k]); });
    return fetch('bio.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  if (bioBtn) bioBtn.addEventListener('click', function () {
    if (!window.PublicKeyCredential || !navigator.credentials) { bioFail('Biométrie non prise en charge ici.'); return; }
    post('login_options').then(function (j) {
      if (!j.ok) throw new Error(j.error || 'refus');
      var pk = j.args.publicKey;
      pk.challenge = fromB64u(pk.challenge);
      (pk.allowCredentials || []).forEach(function (c) { c.id = fromB64u(c.id); });
      return navigator.credentials.get({ publicKey: pk });
    }).then(function (c) {
      return post('login_verify', {
        id: toB64u(c.rawId), clientDataJSON: toB64u(c.response.clientDataJSON),
        authenticatorData: toB64u(c.response.authenticatorData), signature: toB64u(c.response.signature)
      });
    }).then(function (j) {
      if (j.ok) done(); else bioFail(j.error || 'Empreinte non reconnue.');
    }).catch(function () { bioFail('Empreinte non reconnue ou annulée.'); });
  });
  if (bioBtn && CFG.autoBio) setTimeout(function () { bioBtn.click(); }, 300);
})();
