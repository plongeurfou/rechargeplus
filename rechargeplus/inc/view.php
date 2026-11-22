<?php
declare(strict_types=1);

function asset(string $path): string
{
    $file = dirname(__DIR__) . '/' . $path;
    return $path . '?v=' . (is_file($file) ? filemtime($file) : '0');
}

function page_head(string $title, string $class = ''): void
{
    ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#0f766e">
<meta name="color-scheme" content="light dark">
<title><?= e($title) ?></title>
<script>try{var t=localStorage.getItem('rechargeplus_theme');if(t==='light'||t==='dark'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}</script>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<link rel="stylesheet" href="<?= e(asset('assets/style.css')) ?>">
</head>
<body class="<?= e($class) ?>">
<?php
}

/** Bandeau « version disponible » (HTML déjà échappé), vide s'il n'y a rien à annoncer. */
function update_banner_html(array $info): string
{
    if (!$info['available'] || $info['snoozed']) {
        return '';
    }
    return '<div class="banner update" role="status"><strong>Version ' . e($info['latest']['version']) . ' disponible</strong>'
         . ' (vous utilisez la ' . e(APP_VERSION) . '). <a href="maj.php">Voir les nouveautés</a></div>';
}

/** Bandeaux de mise à jour et de nouveautés ; actualise la vérification en arrière-plan si elle est ancienne. */
function render_banners(): void
{
    $c = auth_context();
    if ($c !== null && $c['role'] === 'device') {
        return;   // mises à jour et nouveautés : réservées au master (PC)
    }
    echo '<div id="update-slot">' . update_banner_html(update_info()) . "</div>\n";

    $news = news_pending();
    if ($news) {
        $back = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
        echo '<div class="banner news" role="status"><span><strong>Nouveautés de la version ' . e(APP_VERSION) . '</strong> · '
           . '<a href="maj.php#nouveautes">Voir</a></span>'
           . '<form method="post" action="maj.php" class="inline">'
           . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
           . '<input type="hidden" name="action" value="ack">'
           . '<input type="hidden" name="back" value="' . e($back) . '">'
           . '<button type="submit" class="linkbtn">Compris</button></form></div>' . "\n";
    }

    if (update_configured() && update_is_stale()) {
        echo '<script src="' . e(asset('assets/update.js')) . '" defer data-update-refresh data-csrf="' . e(csrf_token()) . '"></script>' . "\n";
    }
}

/** Numéro de version (lien vers la page Mises à jour). */
function render_version(): void
{
    $c = auth_context();
    if ($c !== null && $c['role'] === 'device') {
        echo '<p class="version">RechargePlus · version ' . e(APP_VERSION) . "</p>\n";
        return;
    }
    echo '<p class="version"><a href="maj.php">RechargePlus · version ' . e(APP_VERSION) . '</a></p>' . "\n";
}

/** Barre supérieure de la vue mobile : bouton burger (en haut à gauche) et menu latéral (apparence). */
function render_appbar(string $title): void
{
    $ctx = auth_context();
    $isDevice = $ctx !== null && $ctx['role'] === 'device';
    ?>
  <header class="appbar">
    <button type="button" class="burger" id="menu-btn" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="menu">
      <span></span><span></span><span></span>
    </button>
    <h1><?= e($title) ?></h1>
  </header>
  <div class="menu-overlay" id="menu-overlay" hidden></div>
  <nav class="menu" id="menu" aria-label="Menu" hidden>
    <div class="menu-head">
      <h2>Menu</h2>
      <button type="button" class="menu-close" id="menu-close" aria-label="Fermer le menu">×</button>
    </div>
    <fieldset id="theme-choice">
      <legend>Apparence</legend>
      <label class="theme-opt"><input type="radio" name="theme" value="auto"><span>Automatique<small>Suit le réglage du téléphone</small></span></label>
      <label class="theme-opt"><input type="radio" name="theme" value="light"><span>Clair</span></label>
      <label class="theme-opt"><input type="radio" name="theme" value="dark"><span>Sombre</span></label>
    </fieldset>
    <form method="post" action="prefs.php" id="odo-form" class="menu-pref">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="odometer">
      <input type="hidden" name="back" value="<?= e(basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'))) ?>">
      <fieldset>
        <legend>Odomètre</legend>
        <label class="theme-opt"><input type="checkbox" name="enabled" value="1" id="odo-toggle"<?= odometer_enabled() ? ' checked' : '' ?>>
          <span>Saisir le compteur km<small>Champ facultatif à chaque recharge</small></span></label>
        <noscript><button type="submit" class="btn small">Enregistrer</button></noscript>
      </fieldset>
    </form>
    <form method="post" action="prefs.php" id="geo-form" class="menu-pref">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="geoloc">
      <input type="hidden" name="back" value="<?= e(basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'))) ?>">
      <fieldset>
        <legend>Géolocalisation</legend>
        <label class="theme-opt"><input type="checkbox" name="enabled" value="1" id="geo-toggle"<?= geoloc_enabled() ? ' checked' : '' ?>>
          <span>Enregistrer la position<small>Position GPS de chaque recharge (lien « Voir sur la carte »)</small></span></label>
        <noscript><button type="submit" class="btn small">Enregistrer</button></noscript>
      </fieldset>
    </form>
    <div class="menu-links">
      <a href="index.php">Recharges</a>
      <a href="stats.php">Statistiques</a>
<?php if ($isDevice): ?>
      <a href="appareil.php">Sécurité de l'appareil</a>
      <button type="button" class="menu-link-btn" id="lock-now">Verrouiller</button>
<?php else: ?>
      <a href="admin.php">Administration (PC)</a>
      <a href="maj.php">Mises à jour</a>
      <a href="logout.php">Se déconnecter</a>
<?php endif; ?>
    </div>
    <p class="menu-version">Version <?= e(APP_VERSION) ?></p>
  </nav>
<?php
}

/** Page affichée quand la base configurée est injoignable. */
function render_db_error(): void
{
    if (!headers_sent()) {
        http_response_code(503);
    }
    page_head('Base de données injoignable — RechargePlus', 'narrow');
    ?>
<main class="wrap">
  <h1>RechargePlus</h1>
  <section class="card">
    <p class="msg bad" role="alert">Connexion à la base de données impossible.</p>
    <?php if (!empty(local_config()['debug']) && !empty($GLOBALS['db_error'])): ?>
      <p><code><?= e((string) $GLOBALS['db_error']) ?></code></p>
    <?php endif; ?>
    <p>Réessayez dans un instant. Si le problème persiste, vérifiez la base dans le panneau Alwaysdata, puis les
       paramètres de connexion dans <code>inc/local.php</code>. Pour relancer l'assistant de configuration, supprimez
       ce fichier (vos données restent dans la base).</p>
  </section>
</main>
<?php
    page_foot();
}

/** Configuration du verrouillage côté navigateur (assets/lock.js). */
function lock_config(array $extra = []): void
{
    $GLOBALS['lock_config_done'] = true;
    $cfg = array_merge(['minutes' => sec_lock_minutes(), 'maxFails' => sec_pin_max_fails(), 'bgSeconds' => 15], $extra);
    echo '<script>window.REV_LOCK=' . json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ";</script>\n";
}

function page_foot(string|array $scripts = []): void
{
    $c = app_state() === 'ready' ? auth_context() : null;
    if ($c && $c['role'] === 'device' && empty($GLOBALS['lock_config_done'])) {
        lock_config(['protect' => true]);
        echo '<script src="' . e(asset('assets/lock.js')) . '" defer></script>' . "\n";
    }
    foreach ((array) $scripts as $script) {
        if ($script !== '') {
            echo '<script src="' . e(asset($script)) . '" defer></script>' . "\n";
        }
    }
    echo "</body>\n</html>\n";
}
