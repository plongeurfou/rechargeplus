<?php
declare(strict_types=1);
/* Service worker (saisie hors ligne). Aucune donnée ni accès à la base : seulement le numéro de version. */
require __DIR__ . '/inc/version.php';
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
?>
'use strict';
var CACHE = 'rechargeplus-<?= APP_VERSION ?>';
var SCOPE = self.registration.scope;

self.addEventListener('install', function () { self.skipWaiting(); });

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('message', function (e) {
  if (e.data === 'clear') e.waitUntil(caches.keys().then(function (k) { return Promise.all(k.map(function (n) { return caches.delete(n); })); }));
});

/* Verrou local : l'écran principal n'est servi hors ligne que si l'application n'est pas verrouillée (IndexedDB « rechargeplus_lock »). */
function unlocked() {
  return new Promise(function (resolve) {
    try {
      var rq = indexedDB.open('rechargeplus_lock', 1);
      rq.onupgradeneeded = function () { rq.result.createObjectStore('kv'); };
      rq.onerror = function () { resolve(false); };
      rq.onsuccess = function () {
        var db = rq.result, out = {};
        var t = db.transaction('kv', 'readonly'), s = t.objectStore('kv');
        s.get('locked').onsuccess = function (e) { out.locked = e.target.result; };
        s.get('offline_until').onsuccess = function (e) { out.until = e.target.result; };
        t.oncomplete = function () { db.close(); resolve(out.locked !== true && typeof out.until === 'number' && out.until > Date.now()); };
        t.onerror = t.onabort = function () { db.close(); resolve(false); };
      };
    } catch (err) { resolve(false); }
  });
}

function isUnlockPage(url) {
  return url.origin === location.origin && url.href.indexOf(SCOPE) === 0 && url.pathname.slice(-18) === '/deverrouiller.php';
}

function isIndex(url) {
  var p = url.pathname;
  return url.origin === location.origin && url.href.indexOf(SCOPE) === 0 && (p.slice(-1) === '/' || p.slice(-10) === '/index.php');
}

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== location.origin || url.href.indexOf(SCOPE) !== 0) return;

  /* Écran principal : réseau d'abord, dernière version connue si hors ligne. */
  if (req.mode === 'navigate' && isIndex(url)) {
    e.respondWith(fetch(req).then(function (res) {
      if (res.ok && !res.redirected && url.search === '') {
        var copy = res.clone();
        caches.open(CACHE).then(function (c) { c.put(SCOPE + 'index.php', copy); });
      }
      return res;
    }).catch(function () {
      return unlocked().then(function (ok) {
        return caches.open(CACHE).then(function (c) { return c.match(SCOPE + (ok ? 'index.php' : 'deverrouiller.php')); });
      }).then(function (r) {
        return r || new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
          + '<title>Hors ligne</title><body style="font:16px system-ui;max-width:480px;margin:40px auto;padding:0 16px">'
          + '<h1>Hors ligne</h1><p>Ouvrez l\'application une première fois avec du réseau pour pouvoir saisir hors ligne.</p></body>',
          { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
      });
    }));
    return;
  }

  /* Page de déverrouillage (sans donnée) : copie gardée pour déverrouiller hors ligne par PIN. */
  if (req.mode === 'navigate' && isUnlockPage(url)) {
    e.respondWith(fetch(req).then(function (res) {
      if (res.ok && res.headers.get('X-Unlock-Cache') === '1') {
        var copy = res.clone();
        caches.open(CACHE).then(function (c) { c.put(SCOPE + 'deverrouiller.php', copy); });
      }
      return res;
    }).catch(function () {
      return caches.open(CACHE).then(function (c) { return c.match(SCOPE + 'deverrouiller.php'); }).then(function (r) {
        return r || new Response('<!doctype html><meta charset="utf-8"><title>Hors ligne</title><body style="font:16px system-ui;margin:40px 16px"><p>Hors ligne : ouvrez l\'application avec du réseau pour la déverrouiller.</p></body>',
          { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
      });
    }));
    return;
  }

  /* Ressources statiques : cache d'abord, mise à jour en arrière-plan. */
  if (url.pathname.indexOf('/assets/') !== -1 || url.pathname.slice(-16) === '.webmanifest') {
    e.respondWith(caches.open(CACHE).then(function (c) {
      return c.match(req).then(function (hit) {
        var net = fetch(req).then(function (res) { if (res.ok) c.put(req, res.clone()); return res; }).catch(function () { return hit; });
        return hit || net;
      });
    }));
  }
});
