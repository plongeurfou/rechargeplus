<?php
declare(strict_types=1);
/* Déverrouillage de la vue mobile : PIN ou biométrie. Cette page ne contient aucune donnée (elle peut être mise en cache). */
require __DIR__ . '/inc/bootstrap.php';
require_configured();

$next = in_array($_GET['next'] ?? '', ['index.php', 'stats.php'], true) ? (string) $_GET['next'] : 'index.php';
$db   = db();
$dev  = device_from_cookie($db);

/* Verrouillage demandé (inactivité, arrière-plan, menu) : on ferme la session de l'appareil. */
if (($_GET['lock'] ?? '') === '1' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    device_session_clear();
    unset($_COOKIE[SESS_COOKIE]);
    auth_context(true);
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && auth_context() !== null) {
    header('Location: ' . $next);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_origin();
    $wantsJson = !empty($_POST['json']);
    $out = ['ok' => false];
    if (!$dev) {
        $out['error'] = 'Appareil non enregistré.';
    } else {
        $r = device_check_pin($db, $dev, trim((string) ($_POST['pin'] ?? '')));
        if ($r['ok']) {
            device_session_start($db, $dev);
            auth_log($db, 'pin_ok', (int) $dev['id'], '');
            $out = ['ok' => true, 'next' => $next];
        } else {
            $out = ['ok' => false, 'locked' => $r['locked'], 'remaining' => $r['remaining'], 'error' => 'Code incorrect.'];
        }
    }
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($out);
        exit;
    }
    if ($out['ok']) {
        header('Location: ' . $next);
        exit;
    }
    $error = $out['locked'] ?? false ? 'Appareil bloqué. Demandez le déblocage à l\'administrateur.' : (string) ($out['error'] ?? 'Erreur.')
        . (isset($out['remaining']) ? ' Essais restants : ' . $out['remaining'] . '.' : '');
}
$error ??= null;

header('Cache-Control: no-cache');
header('X-Unlock-Cache: 1');
page_head('Déverrouiller — RechargePlus', 'narrow');
?>
<main class="wrap">
  <h1>RechargePlus</h1>
  <section class="card">
<?php if (!$dev): ?>
    <p class="msg bad" role="alert">Cet appareil n'est pas enregistré (ou son accès a été révoqué).</p>
    <p>Demandez à l'administrateur de créer un accès depuis le PC, puis scannez le QR code affiché, ou saisissez le code à 8 chiffres.</p>
    <p class="center"><a class="btn" href="enroler.php">Saisir un code d'enrôlement</a></p>
<?php elseif ((int) $dev['locked'] === 1): ?>
    <p class="msg bad" role="alert">Appareil bloqué après trop d'échecs de code PIN.</p>
    <p>L'administrateur doit le débloquer depuis le PC.</p>
<?php else: ?>
    <p class="center muted">Appareil : <strong><?= e((string) $dev['name']) ?></strong></p>
    <form method="post" action="deverrouiller.php?next=<?= e($next) ?>" id="unlock-form" autocomplete="off">
      <label class="field">
        <span>Code PIN</span>
        <input type="password" name="pin" id="pin" class="pin-input" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off" required autofocus>
      </label>
      <p class="msg bad" id="unlock-msg" role="alert"<?= $error ? '' : ' hidden' ?>><?= e((string) $error) ?></p>
      <button type="submit" class="btn">Déverrouiller</button>
    </form>
<?php if ($dev['bio_credential_id'] !== null): ?>
    <p class="center"><button type="button" class="btn secondary" id="bio-btn">Utiliser l'empreinte / le visage</button></p>
<?php endif; ?>
<?php endif; ?>
  </section>
</main>
<?php
lock_config(['next' => $next]);
page_foot(['assets/lock.js']);
