<?php
declare(strict_types=1);
/* Reprise des données de « Recharge » (version 2.7 ou plus) : fichier « Export complet » (JSON). Réservé au master (PC). */
require __DIR__ . '/inc/bootstrap.php';
require_configured();
require_login();

const IMPORT_MAX_BYTES = 20 * 1024 * 1024;
const IMPORT_SETTINGS  = ['budget_mensuel', 'mois_depart', 'report_initial', 'tva_pct', 'tarif_ac', 'tarif_dc', 'odometer_enabled', 'geoloc_enabled'];

$db  = db();
$err = null;
$ok  = null;

/** Valide une ligne d'export ; renvoie les valeurs prêtes pour l'insertion ou lève une exception. */
function import_row(array $r, int $n): array
{
    $bad = fn (string $m) => throw new RuntimeException("Recharge n° $n : $m");
    $dt  = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($r['charged_at'] ?? ''));
    if (!$dt || $dt->format('Y-m-d H:i:s') !== $r['charged_at']) {
        $bad('date invalide.');
    }
    if (!in_array($r['type'] ?? '', ['AC', 'DC'], true)) {
        $bad('type inconnu.');
    }
    foreach (['kwh', 'rate_htva', 'vat_pct', 'amount_htva', 'amount_ttc'] as $k) {
        if (!isset($r[$k]) || !is_numeric($r[$k]) || (float) $r[$k] < 0 || (float) $r[$k] > 99999999) {
            $bad("champ « $k » invalide.");
        }
    }
    if (!isset($r['kwh_billed']) || !is_numeric($r['kwh_billed']) || (int) $r['kwh_billed'] < 0 || (int) $r['kwh_billed'] > 65535) {
        $bad('kWh facturés invalides.');
    }
    $num = fn ($v) => ($v === null || $v === '') ? null : (is_numeric($v) ? (string) $v : $bad('valeur numérique invalide.'));
    $lat = $num($r['latitude'] ?? null);
    $lng = $num($r['longitude'] ?? null);
    if (($lat !== null && abs((float) $lat) > 90) || ($lng !== null && abs((float) $lng) > 180)) {
        $bad('position invalide.');
    }
    $uid = $r['client_uid'] ?? null;
    if ($uid !== null && !preg_match('/^[0-9a-fA-F-]{36}$/', (string) $uid)) {
        $uid = null;
    }
    return [
        'id' => (int) ($r['id'] ?? 0) > 0 ? (int) $r['id'] : null, 'charged_at' => $r['charged_at'], 'kwh' => $r['kwh'], 'kwh_billed' => (int) $r['kwh_billed'],
        'type' => $r['type'], 'rate_htva' => $r['rate_htva'], 'vat_pct' => $r['vat_pct'], 'amount_htva' => $r['amount_htva'], 'amount_ttc' => $r['amount_ttc'],
        'latitude' => $lat, 'longitude' => $lng, 'accuracy_m' => $num($r['accuracy_m'] ?? null) === null ? null : max(0, min(65535, (int) $r['accuracy_m'])),
        'odometer_km' => $num($r['odometer_km'] ?? null) === null ? null : max(0, (int) $r['odometer_km']),
        'client_uid' => $uid, 'in_budget' => (int) ($r['in_budget'] ?? 1) === 0 ? 0 : 1,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $existing = (int) $db->query('SELECT COUNT(*) FROM recharges')->fetchColumn();
    $f = $_FILES['fichier'] ?? null;
    try {
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            throw new RuntimeException('Choisissez le fichier d\'export de Recharge (.json).');
        }
        if ($f['size'] > IMPORT_MAX_BYTES) {
            throw new RuntimeException('Fichier trop volumineux.');
        }
        $data = json_decode((string) file_get_contents($f['tmp_name']), true);
        if (!is_array($data) || ($data['format'] ?? '') !== 'recharge-export' || (int) ($data['format_version'] ?? 0) !== 1 || !is_array($data['recharges'] ?? null)) {
            throw new RuntimeException('Ce fichier n\'est pas un export de Recharge (utilisez « Export complet » dans Recharge 2.7 ou plus).');
        }
        if ($existing > 0 && empty($_POST['replace'])) {
            throw new RuntimeException('RechargePlus contient déjà ' . $existing . ' recharge(s) : cochez la case de remplacement pour continuer.');
        }
        $rows = [];
        foreach ($data['recharges'] as $i => $r) {
            $rows[] = import_row(is_array($r) ? $r : [], $i + 1);
        }
        $uids = array_filter(array_column($rows, 'client_uid'));
        if (count($uids) !== count(array_unique($uids))) {
            throw new RuntimeException('Le fichier contient des identifiants en double.');
        }

        if ($existing > 0) {   // copie de sécurité avant remplacement
            $bk = 'recharges_avant_import_' . date('Ymd_His');
            $db->exec("CREATE TABLE `$bk` AS SELECT * FROM recharges");
        }
        $db->beginTransaction();
        $db->exec('DELETE FROM recharges');
        $ins = $db->prepare('INSERT INTO recharges (id, charged_at, kwh, kwh_billed, type, rate_htva, vat_pct, amount_htva, amount_ttc, latitude, longitude, accuracy_m, odometer_km, client_uid, in_budget, user_id)
                             VALUES (:id, :charged_at, :kwh, :kwh_billed, :type, :rate_htva, :vat_pct, :amount_htva, :amount_ttc, :latitude, :longitude, :accuracy_m, :odometer_km, :client_uid, :in_budget, NULL)');
        foreach ($rows as $row) {
            $ins->execute($row);
        }
        $db->commit();

        $applied = false;
        if (!empty($_POST['settings']) && is_array($data['settings'] ?? null)) {
            $kv = [];
            foreach (IMPORT_SETTINGS as $k) {
                if (isset($data['settings'][$k]) && is_scalar($data['settings'][$k])) {
                    $kv[$k] = (string) $data['settings'][$k];
                }
            }
            if (isset($kv['mois_depart']) && !preg_match('/^\d{4}-\d{2}$/', $kv['mois_depart'])) {
                unset($kv['mois_depart']);
            }
            foreach (['budget_mensuel', 'report_initial', 'tva_pct', 'tarif_ac', 'tarif_dc'] as $k) {
                if (isset($kv[$k]) && !is_numeric($kv[$k])) {
                    unset($kv[$k]);
                }
            }
            if ($kv) {
                save_settings($db, $kv);
                $applied = true;
            }
        }
        auth_log($db, 'import', null, count($rows) . ' recharge(s) depuis Recharge ' . substr((string) ($data['app_version'] ?? '?'), 0, 12));
        $ok = count($rows) . ' recharge(s) importée(s)' . ($applied ? ', réglages repris (budget, tarifs, TVA, mois de départ).' : '.')
            . (isset($bk) ? ' Les anciennes recharges sont conservées dans la table « ' . $bk . ' ».' : '');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $err = $e instanceof RuntimeException ? $e->getMessage() : 'Import impossible : ' . (!empty(local_config()['debug']) ? $e->getMessage() : 'fichier ou base en erreur.');
    }
}

$count = (int) $db->query('SELECT COUNT(*) FROM recharges')->fetchColumn();
header('Cache-Control: no-store');
page_head('Importer depuis Recharge — RechargePlus', 'wide');
?>
<main class="wrap">
  <header class="pagehead">
    <h1>Importer depuis Recharge</h1>
    <nav><a href="admin.php">← Administration</a></nav>
  </header>
  <?php if ($ok): ?><p class="msg ok" role="status"><?= e($ok) ?></p><?php endif; ?>
  <?php if ($err): ?><p class="msg bad" role="alert"><?= e($err) ?></p><?php endif; ?>
  <section class="card">
    <h2>Reprendre vos données</h2>
    <ol>
      <li>Dans <strong>Recharge</strong> (version 2.7 ou plus) : Administration, bouton <strong>Export complet</strong> → fichier <code>recharge-export-….json</code>.</li>
      <li>Ici, choisissez ce fichier et lancez l'import. Il ne contient ni mot de passe ni clé secrète.</li>
    </ol>
    <form method="post" action="importer.php" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label class="field"><span>Fichier d'export (.json)</span><input type="file" name="fichier" accept=".json,application/json" required></label>
      <label class="theme-opt"><input type="checkbox" name="settings" value="1" checked><span>Reprendre aussi les réglages<small>Budget mensuel, mois de départ, report, tarifs, TVA, odomètre, géolocalisation</small></span></label>
<?php if ($count > 0): ?>
      <label class="theme-opt"><input type="checkbox" name="replace" value="1"><span>Remplacer les <?= $count ?> recharge(s) actuelle(s)<small>Une copie de sécurité de la table est créée avant le remplacement</small></span></label>
<?php endif; ?>
      <button type="submit" class="btn">Importer</button>
    </form>
    <p class="muted">Les recharges importées ne sont rattachées à aucun utilisateur. Les appareils mobiles se réenrôlent depuis « Appareils » (le mot de passe et les sessions de Recharge ne sont pas repris).</p>
  </section>
</main>
<?php page_foot(); ?>
