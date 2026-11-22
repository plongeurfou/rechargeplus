<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

logout_user();
header('Cache-Control: no-store');
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="2;url=login.php"><title>Déconnexion — RechargePlus</title></head>
<body style="font:16px system-ui;margin:40px 16px"><p>Déconnexion…</p>
<script>
/* Vide les copies de pages gardées pour l'usage hors ligne (elles contiennent budget et historique). */
(function () {
  var done = function () { location.replace('login.php'); };
  try {
    if (navigator.serviceWorker && navigator.serviceWorker.controller) navigator.serviceWorker.controller.postMessage('clear');
    if (window.caches) { caches.keys().then(function (k) { return Promise.all(k.map(function (n) { return caches.delete(n); })); }).then(done, done); } else { done(); }
  } catch (e) { done(); }
})();
</script></body></html>
