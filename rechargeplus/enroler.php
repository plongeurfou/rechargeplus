<?php
declare(strict_types=1);
/* Enrôlement d'un téléphone : lien reçu par QR code (usage unique, 5 minutes). */
require __DIR__ . '/inc/bootstrap.php';
require_configured();

$db    = db();
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$tok   = enroll_token_find($db, $token);
$error = null;

if ($tok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_origin();
    $json = !empty($_POST['json']);
    $pin  = trim((string) ($_POST['pin'] ?? ''));
    if ($pin !== trim((string) ($_POST['pin2'] ?? ''))) {
        $error = 'Les deux codes ne sont pas identiques.';
    } elseif (($e = pin_error($pin)) !== null) {
        $error = $e;
    } elseif (devices_active_count($db) >= sec_max_devices()) {
        $error = 'Nombre maximal d\'appareils atteint : demandez à l\'administrateur d\'en révoquer un.';
    } elseif (!enroll_token_consume($db, (int) $tok['id'])) {
        $error = 'Ce QR code a déjà servi ou a expiré.';
        $tok = null;
    } else {
        [$id, $secret] = device_create($db, (int) $tok['user_id'], (string) $tok['device_name'], $pin);
        device_cookie_set($id, $secret);
        $_COOKIE[DEV_COOKIE] = $id . '.' . $secret;
        device_session_start($db, device_find($db, $id));
        auth_log($db, 'appareil_enrole', $id, (string) $tok['device_name']);
        $dest = !empty($_POST['bio']) ? 'appareil.php?bio=1' : 'index.php';
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'next' => $dest]);
            exit;
        }
        header('Location: ' . $dest);
        exit;
    }
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
}

header('Cache-Control: no-store');
page_head('Enrôler cet appareil — RechargePlus', 'narrow');
?>
<main class="wrap">
  <h1>Enrôler cet appareil</h1>
  <section class="card">
<?php if (!$tok): ?>
    <p class="msg bad" role="alert"><?= e($error ?? 'Ce QR code est invalide, expiré ou déjà utilisé.') ?></p>
    <p>Demandez à l'administrateur d'en générer un nouveau depuis le PC (valable 5 minutes).</p>
<?php else: ?>
    <p>Appareil : <strong><?= e((string) $tok['device_name']) ?></strong></p>
    <form method="post" action="enroler.php" id="enroll-form" autocomplete="off">
      <input type="hidden" name="t" value="<?= e($token) ?>">
      <label class="field"><span>Choisissez un code PIN (4 à 6 chiffres)</span>
        <input type="password" name="pin" id="pin" class="pin-input" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="new-password" required autofocus></label>
      <label class="field"><span>Confirmez le code PIN</span>
        <input type="password" name="pin2" id="pin2" class="pin-input" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="new-password" required></label>
      <label class="theme-opt"><input type="checkbox" name="bio" value="1" id="bio" checked>
        <span>Activer l'empreinte / le visage<small>Facultatif, modifiable plus tard dans le menu</small></span></label>
      <p class="msg bad" id="form-msg" role="alert"<?= $error ? '' : ' hidden' ?>><?= e((string) $error) ?></p>
      <button type="submit" class="btn">Enregistrer cet appareil</button>
    </form>
<?php endif; ?>
  </section>
</main>
<?php
lock_config();
page_foot(['assets/lock.js', 'assets/appareil.js']);
