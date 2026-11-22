<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_configured();
$ctx = require_user();

$db    = db();
$error = null;
$old   = ['kwh' => '', 'type' => 'AC', 'odo' => '', 'odo_confirm' => false, 'hb' => false];
$odoOn = odometer_enabled();
$lastOdo = $odoOn ? last_odometer($db) : null;
$correctable = (new DateTimeImmutable('now'))->modify('-7 days')->format('Y-m-d H:i:s');   // recharges corrigeables sur mobile
const ODO_JUMP_CONFIRM = 2000;   // écart (km) au-delà duquel une confirmation est demandée

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $old['kwh']  = trim((string) ($_POST['kwh'] ?? ''));
    $old['type'] = ($_POST['type'] ?? '') === 'DC' ? 'DC' : 'AC';
    $old['hb'] = !empty($_POST['hb']);
    $h = parse_kwh($old['kwh']);
    $odo = null;
    if ($odoOn) {
        $old['odo'] = trim((string) ($_POST['odo'] ?? ''));
        $old['odo_confirm'] = !empty($_POST['odo_confirm']);
        $odo = parse_odometer($old['odo']);
    }
    if ($h === null) {
        $error = 'Quantité invalide : saisissez un nombre de kWh supérieur à 0, avec 2 décimales maximum (ex. 12,35).';
    } elseif ($odo === false) {
        $error = 'Compteur invalide : saisissez un nombre entier de kilomètres (ex. 12345) ou laissez le champ vide.';
    } elseif ($odo !== null && $lastOdo !== null && $odo < $lastOdo) {
        $error = 'Le compteur (' . number_format($odo, 0, ',', "\u{00A0}") . ' km) est inférieur au dernier relevé ('
            . number_format($lastOdo, 0, ',', "\u{00A0}") . ' km). Corrigez-le, ou modifiez le relevé précédent dans l\'administration.';
    } elseif ($odo !== null && $lastOdo !== null && $odo - $lastOdo > ODO_JUMP_CONFIRM && !$old['odo_confirm']) {
        $error = 'Écart de ' . number_format($odo - $lastOdo, 0, ',', "\u{00A0}") . ' km depuis le dernier relevé : vérifiez la saisie, puis cochez « Je confirme ce compteur ».';
        $old['need_confirm'] = true;
    } else {
        [$lat, $lng, $acc] = geoloc_enabled() ? read_position($_POST) : [null, null, null];
        $id = record_recharge($db, $h, $old['type'], $lat, $lng, $acc, $odo, parse_uid($_POST['uid'] ?? null), null, !$old['hb'], attribution_user_id());
        header('Location: index.php?ok=' . $id);
        exit;
    }
}

/* Confirmation après enregistrement */
$flash = isset($_GET['reinit'])
    ? 'Mot de passe modifié.' . (is_file(__DIR__ . '/inc/reinitialisation.txt') ? ' Supprimez le fichier inc/reinitialisation.txt.' : '')
    : null;
$flash ??= isset($_GET['bienvenue'])
    ? 'Configuration terminée. Vous pouvez enregistrer votre première recharge.'
        . (is_file(INSTALL_CODE_FILE) ? ' Pensez à supprimer le fichier inc/code_installation.txt.' : '')
    : null;
if (($_GET['ok'] ?? '') === 'corrected') {
    $flash = 'Correction enregistrée.';
} elseif (($_GET['ok'] ?? '') === 'deleted') {
    $flash = 'Recharge supprimée.';
} elseif (isset($_GET['ok']) && ctype_digit((string) $_GET['ok'])) {
    $st = $db->prepare('SELECT * FROM recharges WHERE id = ?');
    $st->execute([(int) $_GET['ok']]);
    if ($r = $st->fetch()) {
        $flash = 'Recharge enregistrée : ' . kwh_fmt($r['kwh']) . ' kWh ' . $r['type']
            . ' (' . $r['kwh_billed'] . ' kWh facturés) = ' . eur(dec_cents($r['amount_ttc'])) . ' TVAC'
            . ($r['latitude'] === null && geoloc_enabled() ? ' — sans position.' : '.');
    }
}

$b      = budget_status($db);
$totals = monthly_totals($db);

/* Historique, du plus récent au plus ancien */
$limit = min(1000, max(50, (int) ($_GET['n'] ?? 50)));
$rows  = $db->query('SELECT r.*, u.name AS user_name FROM recharges r LEFT JOIN users u ON u.id = r.user_id ORDER BY r.charged_at DESC, r.id DESC LIMIT ' . ($limit + 1))->fetchAll();
$more  = count($rows) > $limit;
$rows  = array_slice($rows, 0, $limit);
$byMonth = [];
foreach ($rows as $r) {
    $byMonth[substr($r['charged_at'], 0, 7)][] = $r;
}

$over     = $b['remaining'] < 0;
$pct      = $b['available'] > 0 ? min(100, (int) round($b['spent'] * 100 / $b['available'])) : 100;
$nextDate = $b['next_month']->format('d/m/Y');

page_head('RechargePlus');
?>
<main class="wrap">
  <?php render_appbar('RechargePlus'); ?>
  <?php render_banners(); ?>

  <section class="card hero<?= $over ? ' over' : '' ?>" aria-labelledby="hero-label">
    <p class="label" id="hero-label"><?= $over ? 'Dépassement' : 'Budget restant' ?></p>
    <p class="big"><?= e(eur($b['remaining'])) ?></p>
    <p class="sub">
      <?php if ($over): ?>
        Budget épuisé : 0 kWh restant. Ce dépassement sera déduit du mois suivant.
      <?php else: ?>
        ≈ <strong><?= number_format($b['ac_kwh'], 0, ',', "\u{00A0}") ?> kWh</strong> encore rechargeables en AC
      <?php endif; ?>
    </p>
    <div class="bar" role="img" aria-label="<?= $pct ?> % du budget disponible utilisé"><i style="width:<?= $pct ?>%"></i></div>
    <dl class="kv">
      <dt>Report des mois précédents</dt>
      <dd class="<?= $b['carry'] < 0 ? 'neg' : '' ?>"><?= e(eur($b['carry'])) ?></dd>
      <dt>Budget de <?= e(mois_label($b['month'])) ?></dt>
      <dd><?= e(eur($b['monthly'])) ?></dd>
      <dt>Dépensé ce mois-ci</dt>
      <dd><?= e(eur($b['spent'])) ?></dd>
    </dl>
    <p class="hint">Prochaine remise à jour le <?= e($nextDate) ?> (+<?= e(eur($b['monthly'])) ?>).</p>
  </section>

  <p class="banner" id="net-status" role="status" hidden>Hors ligne — vous pouvez saisir vos recharges, elles seront envoyées au retour du réseau.</p>
  <p class="msg ok" id="offline-msg" role="status" aria-live="polite" hidden></p>
  <section class="card" id="pending" aria-labelledby="pending-title" hidden>
    <h2 id="pending-title">À envoyer</h2>
    <p class="help">Recharges saisies sans réseau, pas encore comptées dans le budget ci-dessus.</p>
    <ul class="history" id="pending-list"></ul>
    <button type="button" class="btn small secondary" id="pending-send">Envoyer maintenant</button>
  </section>

  <section class="card" aria-labelledby="form-title">
    <h2 id="form-title">Nouvelle recharge</h2>
    <?php if ($flash): ?><p class="msg ok" role="status"><?= e($flash) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="msg bad" role="alert"><?= e($error) ?></p><?php endif; ?>

    <form method="post" action="index.php" id="recharge-form"
          data-rate-ac="<?= rate_10k('AC') ?>" data-rate-dc="<?= rate_10k('DC') ?>" data-vat="<?= vat_pct() ?>" data-geo="<?= geoloc_enabled() ? '1' : '0' ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="lat" value="">
      <input type="hidden" name="lng" value="">
      <input type="hidden" name="acc" value="">

      <label class="field" for="kwh">
        <span>kWh récupérés</span>
        <input type="text" id="kwh" name="kwh" inputmode="decimal" autocomplete="off" required
               maxlength="8" placeholder="ex. 12,35" title="Nombre de kWh, 2 décimales maximum (ex. 12,35)" pattern="\s*\d{0,4}([.,]\d{1,2})?\s*"
               value="<?= e($old['kwh']) ?>">
      </label>

      <div class="field">
        <span id="type-label">Type de recharge</span>
        <div class="switch" role="radiogroup" aria-labelledby="type-label">
          <input type="radio" id="type-ac" name="type" value="AC" <?= $old['type'] === 'AC' ? 'checked' : '' ?>>
          <label for="type-ac">AC<small>jusqu'à 22 kW</small></label>
          <input type="radio" id="type-dc" name="type" value="DC" <?= $old['type'] === 'DC' ? 'checked' : '' ?>>
          <label for="type-dc">DC<small>borne rapide</small></label>
        </div>
      </div>

      <label class="check" for="hb"><input type="checkbox" id="hb" name="hb" value="1"<?= $old['hb'] ? ' checked' : '' ?>>
        Hors budget <small class="muted">— gardée pour la consommation, non déduite du budget</small></label>

      <?php if ($odoOn): ?>
      <label class="field" for="odo">
        <span>Compteur (km) <small class="muted">— facultatif</small></span>
        <input type="text" id="odo" name="odo" inputmode="numeric" autocomplete="off" maxlength="9"
               placeholder="<?= $lastOdo !== null ? 'dernier : ' . e(number_format($lastOdo, 0, ',', "\u{00A0}")) . ' km' : 'ex. 12345' ?>"
               pattern="[\s0-9\u00A0\u202F]{0,9}" title="Nombre entier de kilomètres (ex. 12345)" value="<?= e($old['odo']) ?>">
      </label>
      <?php if (!empty($old['need_confirm'])): ?>
        <label class="check"><input type="checkbox" name="odo_confirm" value="1"> Je confirme ce compteur</label>
      <?php endif; ?>
      <?php endif; ?>

      <p class="preview" id="preview" aria-live="polite">&nbsp;</p>
      <p class="geo" id="geo-status"><?= geoloc_enabled() ? 'Position : recherche en cours…' : 'Position : désactivée (menu ☰)' ?></p>

      <button type="submit" class="btn" id="submit-btn">Enregistrer la recharge</button>
    </form>
  </section>

  <section aria-labelledby="hist-title">
    <h2 class="section-title" id="hist-title">Historique</h2>
    <?php if (!$byMonth): ?>
      <p class="card muted">Aucune recharge enregistrée pour l'instant.</p>
    <?php endif; ?>

    <?php foreach ($byMonth as $ym => $list): $t = $totals[$ym] ?? null; ?>
      <div class="card month">
        <header>
          <h3><?= e(ucfirst(mois_label($ym))) ?></h3>
          <?php if ($t): ?>
            <p class="muted"><?= $t['n'] ?> recharge<?= $t['n'] > 1 ? 's' : '' ?> · <?= e(kwh_fmt($t['kwh'])) ?> kWh · <strong><?= e(eur($t['cents'])) ?></strong><?php if ($t['n_out'] > 0): ?> <span class="muted">· + <?= $t['n_out'] ?> hors budget (<?= e(kwh_fmt($t['kwh_out'])) ?> kWh)</span><?php endif; ?></p>
          <?php endif; ?>
        </header>
        <ul class="history">
          <?php foreach ($list as $r): $map = map_url($r['latitude'], $r['longitude']); ?>
            <li>
              <div class="line1">
                <span class="badge <?= $r['type'] === 'DC' ? 'dc' : 'ac' ?>"><?= e($r['type']) ?></span><?= (int) $r['in_budget'] === 0 ? ' <span class="badge out">hors budget</span>' : '' ?>
                <span class="kwh"><?= e(kwh_fmt($r['kwh'])) ?> kWh</span>
                <span class="billed">(<?= (int) $r['kwh_billed'] ?> facturés)</span>
                <span class="amt"><?= e(eur(dec_cents($r['amount_ttc']))) ?></span>
              </div>
              <div class="line2">
                <span><?= e(fmt_datetime($r['charged_at'])) ?><?= $r['odometer_km'] !== null ? ' · ' . e(number_format((int) $r['odometer_km'], 0, ',', "\u{00A0}")) . "\u{00A0}km" : '' ?><?= $r['user_name'] !== null ? ' · ' . e((string) $r['user_name']) : '' ?></span>
                <?php if ($map): ?>
                  <a href="<?= e($map) ?>" target="_blank" rel="noopener noreferrer">Voir sur la carte</a>
                <?php elseif (geoloc_enabled()): ?>
                  <span class="muted">position inconnue</span>
                <?php endif; ?>
                <?php if ($r['charged_at'] >= $correctable): ?><a href="corriger.php?id=<?= (int) $r['id'] ?>">Corriger</a><?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>

    <?php if ($more): ?>
      <p class="center"><a class="btn secondary" href="index.php?n=<?= $limit + 50 ?>">Voir plus</a></p>
    <?php endif; ?>
  </section>

  <footer class="foot">
    <?php if (($ctx['role'] ?? '') === 'device'): ?><a href="appareil.php">Sécurité de l'appareil</a><?php else: ?><a href="admin.php">Administration (PC)</a> · <a href="logout.php">Se déconnecter</a><?php endif; ?>
  </footer>
  <?php render_version(); ?>
</main>
<?php page_foot(['assets/menu.js', 'assets/offline.js', 'assets/app.js']); ?>
