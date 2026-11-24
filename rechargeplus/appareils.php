<?php
declare(strict_types=1);
/* Administration des appareils mobiles (utilisateur master, PC) : enrôlement par QR code, blocage/déblocage, réglages, journal. */
require __DIR__ . '/inc/bootstrap.php';
require_configured();
require_login();

$db   = db();
$msg  = null;
$err  = null;
$qr   = null;   // ['url' => ..., 'expires' => ts, 'name' => ...]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'create') {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['device_name'] ?? '')) ?? '');
        $user = trim((string) ($_POST['user_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) {
            $err = 'Donnez un nom à l\'appareil (60 caractères maximum).';
        } elseif (sec_identity_mode() === 'distinct' && ($user === '' || mb_strlen($user) > 60)) {
            $err = 'Mode « utilisateurs distincts » : indiquez le nom de l\'utilisateur (60 caractères maximum).';
        } elseif (devices_active_count($db) >= sec_max_devices()) {
            $err = 'Nombre maximal d\'appareils atteint (' . sec_max_devices() . ') : révoquez-en un ou augmentez la limite.';
        } else {
            $uid = sec_identity_mode() === 'distinct' ? user_for_name($db, $user) : default_user_id($db);
            $t   = enroll_token_create($db, $uid, $name);
            $scheme = is_https() ? 'https' : 'http';
            $base   = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname((string) $_SERVER['SCRIPT_NAME']), '/\\');
            $qr = ['url' => $base . '/enroler.php?t=' . $t['token'], 'expires' => $t['expires'], 'name' => $name, 'code' => $t['code'], 'page' => $base . '/enroler.php'];
            auth_log($db, 'qr_genere', null, $name);
        }
    } elseif ($action === 'rename' && $id > 0) {
        $name = trim((string) ($_POST['device_name'] ?? ''));
        if ($name !== '' && mb_strlen($name) <= 60) {
            $db->prepare('UPDATE devices SET name = ? WHERE id = ?')->execute([$name, $id]);
            $msg = 'Appareil renommé.';
        } else {
            $err = 'Nom invalide.';
        }
    } elseif ($action === 'unlock' && $id > 0) {
        $db->prepare('UPDATE devices SET locked = 0, pin_fails = 0 WHERE id = ? AND revoked_at IS NULL')->execute([$id]);
        auth_log($db, 'debloque', $id, 'par le master');
        $msg = 'Appareil débloqué.';
    } elseif ($action === 'revoke' && $id > 0) {
        // Invalide immédiatement session, PIN et biométrie.
        $db->prepare('UPDATE devices SET revoked_at = ?, pin_hash = ?, token_hash = ?, bio_credential_id = NULL, bio_public_key = NULL, bio_challenge = NULL WHERE id = ? AND revoked_at IS NULL')
           ->execute([date('Y-m-d H:i:s'), '', bin2hex(random_bytes(32)), $id]);
        auth_log($db, 'revoque', $id, 'par le master');
        $msg = 'Appareil révoqué : session, PIN et biométrie sont invalidés.';
    } elseif ($action === 'delete' && $id > 0) {
        $db->prepare('DELETE FROM devices WHERE id = ? AND revoked_at IS NOT NULL')->execute([$id]);
        $msg = 'Appareil supprimé de la liste.';
    } elseif ($action === 'settings') {
        $rng = fn (string $k, int $min, int $max, int $def): string => (string) max($min, min($max, (int) ($_POST[$k] ?? $def)));
        save_settings($db, [
            'identity_mode' => ($_POST['identity_mode'] ?? '') === 'distinct' ? 'distinct' : 'unique',
            'max_devices'   => $rng('max_devices', 1, 20, 5),
            'lock_minutes'  => $rng('lock_minutes', 1, 240, 5),
            'device_days'   => $rng('device_days', 1, 365, 90),
            'pin_max_fails' => $rng('pin_max_fails', 3, 10, 5),
        ]);
        $msg = 'Réglages enregistrés.';
    }
}

$devices = $db->query('SELECT d.*, u.name AS user_name FROM devices d JOIN users u ON u.id = d.user_id ORDER BY d.revoked_at IS NOT NULL, d.id')->fetchAll();
$log     = $db->query('SELECT l.*, d.name AS device_name FROM auth_log l LEFT JOIN devices d ON d.id = l.device_id ORDER BY l.id DESC LIMIT 100')->fetchAll();
$mode    = sec_identity_mode();
$csrf    = csrf_token();
$labels  = ['pin_echec' => 'Échec de PIN', 'bio_echec' => 'Échec biométrique', 'appareil_bloque' => 'Appareil bloqué', 'pin_ok' => 'PIN accepté',
            'bio_ok' => 'Biométrie acceptée', 'appareil_enrole' => 'Appareil enrôlé', 'qr_genere' => 'QR généré', 'debloque' => 'Débloqué',
            'revoque' => 'Révoqué', 'pin_change' => 'PIN modifié', 'bio_activee' => 'Biométrie activée', 'bio_desactivee' => 'Biométrie désactivée', 'import' => 'Import depuis Recharge', 'code_echec' => 'Code d\'enrôlement refusé'];

header('Cache-Control: no-store');
page_head('Appareils — RechargePlus', 'wide');
?>
<main class="wrap">
  <header class="pagehead">
    <h1>Appareils mobiles</h1>
    <nav><a href="admin.php">← Administration</a></nav>
  </header>
  <?php if ($msg): ?><p class="msg ok" role="status"><?= e($msg) ?></p><?php endif; ?>
  <?php if ($err): ?><p class="msg bad" role="alert"><?= e($err) ?></p><?php endif; ?>

<?php if ($qr): ?>
  <section class="card" id="qr-card">
    <h2>Enrôler « <?= e($qr['name']) ?> »</h2>
    <p>Scannez ce QR code avec le téléphone. Il est à usage unique et expire dans <strong id="qr-left">5:00</strong>.</p>
    <div class="qr-box" id="qr-box" data-url="<?= e($qr['url']) ?>" data-expires="<?= (int) $qr['expires'] ?>"></div>
    <?php if (!is_https()): ?><p class="msg bad">Attention : connexion non sécurisée (HTTP). Le cookie sécurisé et la biométrie exigent HTTPS.</p><?php endif; ?>
    <p class="center">Pas de scan possible (application installée sur l'écran d'accueil de l'iPhone, par exemple) ?<br>
      Ouvrez <code><?= e($qr['page']) ?></code> sur le téléphone et saisissez le code :</p>
    <p class="center"><strong style="font-size:1.8rem;letter-spacing:.15em"><?= e(substr($qr['code'], 0, 4) . ' ' . substr($qr['code'], 4)) ?></strong></p>
    <noscript><p>Lien : <code><?= e($qr['url']) ?></code></p></noscript>
  </section>
<?php endif; ?>

  <section class="card">
    <h2>Ajouter un appareil</h2>
    <form method="post" action="appareils.php">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="create">
      <label class="field"><span>Nom de l'appareil (ex. « Téléphone d'Alain »)</span><input type="text" name="device_name" maxlength="60" required></label>
<?php if ($mode === 'distinct'): ?>
      <label class="field"><span>Utilisateur (créé s'il n'existe pas)</span><input type="text" name="user_name" maxlength="60" list="users" required></label>
      <datalist id="users"><?php foreach (users_all($db) as $u): ?><option value="<?= e($u['name']) ?>"><?php endforeach; ?></datalist>
<?php endif; ?>
      <button type="submit" class="btn">Générer le QR code (5 min)</button>
    </form>
  </section>

  <section class="card">
    <h2>Appareils enregistrés (<?= devices_active_count($db) ?>/<?= sec_max_devices() ?>)</h2>
<?php if (!$devices): ?>
    <p class="muted">Aucun appareil. Les téléphones ne peuvent plus se connecter par mot de passe : enrôlez-les ici.</p>
<?php else: ?>
    <div class="tablewrap"><table class="tbl">
      <thead><tr><th>Appareil</th><th>Utilisateur</th><th>État</th><th>Dernière activité</th><th>Actions</th></tr></thead>
      <tbody>
<?php foreach ($devices as $d): ?>
      <tr>
        <td><?= e((string) $d['name']) ?></td>
        <td><?= e((string) $d['user_name']) ?></td>
        <td>
<?php if ($d['revoked_at'] !== null): ?><span class="tag bad">révoqué</span>
<?php elseif ((int) $d['locked'] === 1): ?><span class="tag bad">bloqué</span>
<?php else: ?><span class="tag">actif</span><?php endif; ?>
          <?= $d['bio_credential_id'] !== null ? '<span class="tag">biométrie</span>' : '' ?>
        </td>
        <td><?= e((string) ($d['last_seen_at'] ?? '')) ?><br><small class="muted"><?= e((string) ($d['last_ip'] ?? '')) ?></small></td>
        <td class="inline-forms">
<?php if ($d['revoked_at'] === null): ?>
          <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
            <input type="text" name="device_name" value="<?= e((string) $d['name']) ?>" maxlength="60" size="14" aria-label="Nom">
            <button class="btn small secondary" name="action" value="rename">Renommer</button></form>
<?php if ((int) $d['locked'] === 1): ?>
          <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
            <button class="btn small" name="action" value="unlock">Débloquer</button></form>
<?php endif; ?>
          <form method="post" onsubmit="return confirm('Révoquer cet appareil ? Il ne pourra plus accéder à l\'application.');"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
            <button class="btn small danger" name="action" value="revoke">Révoquer</button></form>
<?php else: ?>
          <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
            <button class="btn small secondary" name="action" value="delete">Retirer de la liste</button></form>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
      </tbody>
    </table></div>
<?php endif; ?>
  </section>

  <section class="card">
    <h2>Réglages de sécurité</h2>
    <form method="post" action="appareils.php">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="settings">
      <fieldset>
        <legend>Mode d'identité</legend>
        <label class="theme-opt"><input type="radio" name="identity_mode" value="unique"<?= $mode === 'unique' ? ' checked' : '' ?>><span>Identifiant unique<small>Un compte commun ; chaque appareil a son PIN / sa biométrie</small></span></label>
        <label class="theme-opt"><input type="radio" name="identity_mode" value="distinct"<?= $mode === 'distinct' ? ' checked' : '' ?>><span>Utilisateurs distincts<small>Chaque appareil est rattaché à un utilisateur ; les recharges lui sont attribuées</small></span></label>
      </fieldset>
      <label class="field"><span>Nombre d'appareils / codes PIN autorisés (1 à 20)</span><input type="number" name="max_devices" min="1" max="20" value="<?= sec_max_devices() ?>"></label>
      <label class="field"><span>Verrouillage après inactivité (minutes, 1 à 240)</span><input type="number" name="lock_minutes" min="1" max="240" value="<?= sec_lock_minutes() ?>"></label>
      <label class="field"><span>Durée de rafraîchissement : appareil reconnu (jours, 1 à 365)</span><input type="number" name="device_days" min="1" max="365" value="<?= sec_device_days() ?>"></label>
      <label class="field"><span>Échecs de PIN avant blocage (3 à 10)</span><input type="number" name="pin_max_fails" min="3" max="10" value="<?= sec_pin_max_fails() ?>"></label>
      <button type="submit" class="btn">Enregistrer</button>
    </form>
  </section>

  <section class="card">
    <h2>Journal d'authentification (100 derniers événements)</h2>
    <div class="tablewrap"><table class="tbl">
      <thead><tr><th>Date</th><th>Événement</th><th>Appareil</th><th>IP</th><th>Détail</th></tr></thead>
      <tbody>
<?php foreach ($log as $l): ?>
      <tr><td><?= e((string) $l['at']) ?></td><td><?= e($labels[$l['event']] ?? (string) $l['event']) ?></td><td><?= e((string) ($l['device_name'] ?? '')) ?></td><td><?= e((string) $l['ip']) ?></td><td><?= e((string) $l['detail']) ?></td></tr>
<?php endforeach; ?>
<?php if (!$log): ?><tr><td colspan="5" class="muted">Aucun événement.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>
</main>
<?php if ($qr): ?>
<script src="<?= e(asset('assets/qrcode.js')) ?>"></script>
<script>
(function () {
  var box = document.getElementById('qr-box'), q = qrcode(0, 'M');
  q.addData(box.dataset.url); q.make();
  box.innerHTML = q.createSvgTag({ scalable: true, margin: 2 });
  var left = document.getElementById('qr-left'), end = parseInt(box.dataset.expires, 10) * 1000;
  (function tick() {
    var s = Math.max(0, Math.round((end - Date.now()) / 1000));
    left.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
    if (s === 0) { box.innerHTML = '<p class="msg bad">QR code expiré. Générez-en un nouveau.</p>'; return; }
    setTimeout(tick, 1000);
  })();
})();
</script>
<?php endif; ?>
<?php page_foot(); ?>
