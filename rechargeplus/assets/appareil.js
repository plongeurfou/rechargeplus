/* Enrôlement, activation/désactivation de la biométrie et changement de PIN (vue mobile). */
(function () {
  'use strict';
  function say(el, t, cls) { if (!el) return; el.textContent = t || ''; el.className = 'msg ' + (cls || 'ok'); el.hidden = !t; }
  function b64(buf) { var s = ''; new Uint8Array(buf).forEach(function (c) { s += String.fromCharCode(c); }); return btoa(s); }
  function toB64u(buf) { return b64(buf).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
  function fromB64u(s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '='; var b = atob(s), a = new Uint8Array(b.length); for (var i = 0; i < b.length; i++) a[i] = b.charCodeAt(i); return a; }
  function csrf() { var m = document.querySelector('#menu input[name=csrf]'); return m ? m.value : ''; }
  function post(url, data) {
    var fd = new FormData(); Object.keys(data).forEach(function (k) { fd.set(k, data[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  /* ----- enrôlement ----- */
  var enroll = document.getElementById('enroll-form');
  if (enroll) enroll.addEventListener('submit', function (e) {
    e.preventDefault();
    var msg = document.getElementById('form-msg'), fd = new FormData(enroll), pin = fd.get('pin');
    fd.set('json', '1');
    fetch('enroler.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { say(msg, j.error || 'Erreur.', 'bad'); return; }
      var go = function () { location.replace(j.next); };
      (window.RevLock ? RevLock.makeVerifier(String(pin)).then(function () { return RevLock.markUnlocked(); }) : Promise.resolve()).then(go, go);
    }).catch(function () { say(msg, 'Connexion impossible.', 'bad'); });
  });

  /* ----- biométrie ----- */
  var bm = document.getElementById('bio-msg'), en = document.getElementById('bio-enable'), di = document.getElementById('bio-disable'), st = document.getElementById('bio-state');
  function state(on) {
    if (st) { st.textContent = on ? 'activée' : 'désactivée'; st.className = 'tag' + (on ? '' : ' bad'); }
    if (en) en.hidden = on; if (di) di.hidden = !on;
  }
  function enableBio() {
    if (!window.PublicKeyCredential || !navigator.credentials) { say(bm, 'Biométrie non prise en charge par ce navigateur.', 'bad'); return; }
    post('bio.php', { action: 'reg_options', csrf: csrf() }).then(function (j) {
      if (!j.ok) throw new Error(j.error || 'refus');
      var pk = j.args.publicKey;
      pk.challenge = fromB64u(pk.challenge);
      pk.user.id = fromB64u(pk.user.id);
      (pk.excludeCredentials || []).forEach(function (c) { c.id = fromB64u(c.id); });
      return navigator.credentials.create({ publicKey: pk });
    }).then(function (c) {
      return post('bio.php', { action: 'reg_verify', csrf: csrf(), clientDataJSON: toB64u(c.response.clientDataJSON), attestationObject: toB64u(c.response.attestationObject) });
    }).then(function (j) {
      if (j.ok) { state(true); say(bm, 'Biométrie activée.', 'ok'); } else say(bm, j.error || 'Activation refusée.', 'bad');
    }).catch(function (err) { say(bm, 'Activation annulée ou impossible' + (err && err.message ? ' (' + err.message + ')' : '') + '.', 'bad'); });
  }
  if (en) en.addEventListener('click', enableBio);
  if (di) di.addEventListener('click', function () {
    post('bio.php', { action: 'disable', csrf: csrf() }).then(function (j) { if (j.ok) { state(false); say(bm, 'Biométrie désactivée : le code PIN reste nécessaire.', 'ok'); } else say(bm, j.error || 'Erreur.', 'bad'); });
  });
  if (/[?&]bio=1/.test(location.search) && en && !en.hidden) { say(bm, 'Validez l\'empreinte ou le visage pour terminer.', 'ok'); setTimeout(enableBio, 400); }

  /* ----- changement de PIN ----- */
  var pf = document.getElementById('pin-form');
  if (pf) pf.addEventListener('submit', function (e) {
    e.preventDefault();
    var msg = document.getElementById('pin-msg'), d = { action: 'change_pin', csrf: csrf() };
    ['current', 'new', 'new2'].forEach(function (k) { d[k] = pf.elements[k].value; });
    post('appareil.php', d).then(function (j) {
      if (j.locked) { location.replace('deverrouiller.php'); return; }
      if (!j.ok) { say(msg, j.error || 'Erreur.', 'bad'); return; }
      var done = function () { pf.reset(); say(msg, 'Code PIN modifié.', 'ok'); };
      (window.RevLock ? RevLock.makeVerifier(d['new']) : Promise.resolve()).then(done, done);
    }).catch(function () { say(msg, 'Connexion impossible.', 'bad'); });
  });
})();
