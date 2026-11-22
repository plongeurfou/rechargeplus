<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_configured();
require_user();

/* Préférences de la vue mobile (depuis le menu) : activer/désactiver la saisie du compteur kilométrique et la géolocalisation. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['action'] ?? '') === 'odometer') {
        save_settings(db(), ['odometer_enabled' => !empty($_POST['enabled']) ? '1' : '0']);
    } elseif (($_POST['action'] ?? '') === 'geoloc') {
        save_settings(db(), ['geoloc_enabled' => !empty($_POST['enabled']) ? '1' : '0']);
    }
}
$back = (string) ($_POST['back'] ?? '');
header('Location: ' . (in_array($back, ['index.php', 'stats.php'], true) ? $back : 'index.php'));
exit;
