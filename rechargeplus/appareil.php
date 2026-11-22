<?php
declare(strict_types=1);
/* Sécurité de l'appareil (vue mobile) : biométrie, changement de PIN. */
require __DIR__ . '/inc/bootstrap.php';
require_configured();
$ctx = require_user();
if ($ctx['role'] !== 'device') {
    header('Location: appareils.php');   // le master gère les appareils depuis le PC
    exit;
}
$db  = db();
$dev = $ctx['device'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (($_POST['action'] ?? '') === 'change_pin') {
        $new = trim((string) ($_POST['new'] ?? ''));
        $r = device_check_pin($db, $dev, trim((string) ($_POST['current'] ?? '')));
        if ($r['locked']) {
            device_session_clear();
            echo json_encode(['ok' => false, 'locked' => true, 'error' => 'Appareil bloqué.']);
        } elseif (!$r['ok']) {
            echo json_encode(['ok' => false, 'error' => 'Code actuel incorrect. Essais restants : ' . $r['remaining'] . '.']);
        } elseif ($new !== trim((string) ($_POST['new2'] ?? ''))) {
            echo json_encode(['ok' => false, 'error' => 'Les deux nouveaux codes ne sont pas identiques.']);
        } elseif (($e = pin_error($new)) !== null) {
            echo json_encode(['ok' => false, 'error' => $e]);
        } else {
            $db->prepare('UPDATE devices SET pin_hash = ?, pin_fails = 0 WHERE id = ?')->execute([pin_hash($new), $dev['id']]);
            auth_log($db, 'pin_change', (int) $dev['id'], '');
            echo json_encode(['ok' => true]);
        }
        exit;
    }
    echo json_encode(['ok' => false, 'error' => 'Action inconnue.']);
    exit;
}

$hasBio = $dev['bio_credential_id'] !== null;
page_head('Sécurité de l\'appareil — RechargePlus');
?>
<div class="app">
  <?php render_appbar('Sécurité'); ?>
  <main class="wrap">
    <section class="card">
      <h2>Cet appareil</h2>
      <p><strong><?= e((string) $dev['name']) ?></strong><?= sec_identity_mode() === 'distinct' ? ' · utilisateur : ' . e((string) $ctx['user_name']) : '' ?></p>
    </section>
    <section class="card">
      <h2>Biométrie</h2>
      <p>État : <span class="tag<?= $hasBio ? '' : ' bad' ?>" id="bio-state"><?= $hasBio ? 'activée' : 'désactivée' ?></span></p>
      <p class="muted">Nécessite une connexion HTTPS et un téléphone avec verrouillage biométrique configuré.</p>
      <button type="button" class="btn" id="bio-enable"<?= $hasBio ? ' hidden' : '' ?>>Activer l'empreinte / le visage</button>
      <button type="button" class="btn secondary" id="bio-disable"<?= $hasBio ? '' : ' hidden' ?>>Désactiver</button>
      <p class="msg" id="bio-msg" hidden></p>
    </section>
    <section class="card">
      <h2>Changer le code PIN</h2>
      <form id="pin-form" autocomplete="off">
        <label class="field"><span>Code actuel</span><input type="password" name="current" class="pin-input" inputmode="numeric" pattern="[0-9]*" maxlength="6" required></label>
        <label class="field"><span>Nouveau code (4 à 6 chiffres)</span><input type="password" name="new" class="pin-input" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="new-password" required></label>
        <label class="field"><span>Confirmer le nouveau code</span><input type="password" name="new2" class="pin-input" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="new-password" required></label>
        <p class="msg" id="pin-msg" hidden></p>
        <button type="submit" class="btn">Changer le code</button>
      </form>
    </section>
    <?php render_version(); ?>
  </main>
</div>
<?php
page_foot(['assets/menu.js', 'assets/appareil.js']);
