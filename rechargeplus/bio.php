<?php
declare(strict_types=1);
/* Biométrie (WebAuthn) — JSON : reg_options, reg_verify, login_options, login_verify, disable. */
require __DIR__ . '/inc/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bio_reply(int $code, array $data): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (app_state() !== 'ready') {
    bio_reply(503, ['ok' => false, 'error' => 'Application non configurée.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bio_reply(405, ['ok' => false, 'error' => 'Méthode non autorisée.']);
}
check_origin();
if (!is_https() && empty(local_config()['debug'])) {
    bio_reply(400, ['ok' => false, 'error' => 'La biométrie exige une connexion HTTPS.']);
}

$db     = db();
$action = (string) ($_POST['action'] ?? '');
$in     = fn (string $k): string => wa_b64u_decode((string) ($_POST[$k] ?? ''));

try {
    /* ----- Déverrouillage (sans session) ----- */
    if ($action === 'login_options' || $action === 'login_verify') {
        $dev = device_from_cookie($db);
        if (!$dev || (int) $dev['locked'] === 1 || $dev['bio_credential_id'] === null) {
            bio_reply(403, ['ok' => false, 'error' => 'Biométrie indisponible sur cet appareil.']);
        }
        $wa = wa_server();
        if ($action === 'login_options') {
            $args = $wa->getGetArgs([wa_b64u_decode((string) $dev['bio_credential_id'])], 60, false, false, false, false, true, true);
            $db->prepare('UPDATE devices SET bio_challenge = ?, bio_challenge_at = ? WHERE id = ?')
               ->execute([wa_b64u_encode($wa->getChallenge()->getBinaryString()), date('Y-m-d H:i:s'), $dev['id']]);
            bio_reply(200, ['ok' => true, 'args' => $args]);
        }
        $ok = false;
        $chal = (string) $dev['bio_challenge'];
        $fresh = $dev['bio_challenge_at'] !== null && strtotime((string) $dev['bio_challenge_at']) >= time() - 120;
        // Le défi est à usage unique.
        $db->prepare('UPDATE devices SET bio_challenge = NULL WHERE id = ?')->execute([$dev['id']]);
        if ($chal !== '' && $fresh && wa_b64u_encode($in('id')) === (string) $dev['bio_credential_id']) {
            try {
                $wa->processGet($in('clientDataJSON'), $in('authenticatorData'), $in('signature'), (string) $dev['bio_public_key'],
                                wa_b64u_decode($chal), (int) $dev['bio_counter'], true, true);
                $cnt = $wa->getSignatureCounter();
                if ($cnt !== null && $cnt > 0) {
                    $db->prepare('UPDATE devices SET bio_counter = ? WHERE id = ?')->execute([$cnt, $dev['id']]);
                }
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        if (!$ok) {
            auth_log($db, 'bio_echec', (int) $dev['id'], 'empreinte refusée');
            bio_reply(401, ['ok' => false, 'error' => 'Empreinte non reconnue.']);
        }
        $db->prepare('UPDATE devices SET pin_fails = 0 WHERE id = ?')->execute([$dev['id']]);
        device_session_start($db, $dev);
        auth_log($db, 'bio_ok', (int) $dev['id'], '');
        bio_reply(200, ['ok' => true]);
    }

    /* ----- Gestion (session d'appareil requise) ----- */
    $ctx = auth_context();
    if (!$ctx || $ctx['role'] !== 'device') {
        bio_reply(401, ['ok' => false, 'locked' => true, 'error' => 'Déverrouillez l\'application.']);
    }
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        bio_reply(403, ['ok' => false, 'error' => 'Jeton périmé : rechargez la page.']);
    }
    $dev = $ctx['device'];

    if ($action === 'disable') {
        $db->prepare('UPDATE devices SET bio_credential_id = NULL, bio_public_key = NULL, bio_counter = 0, bio_challenge = NULL WHERE id = ?')->execute([$dev['id']]);
        auth_log($db, 'bio_desactivee', (int) $dev['id'], '');
        bio_reply(200, ['ok' => true]);
    }
    if (!is_https() && empty(local_config()['debug'])) {
        bio_reply(400, ['ok' => false, 'error' => 'HTTPS requis.']);
    }
    $wa = wa_server();
    if ($action === 'reg_options') {
        $args = $wa->getCreateArgs('d' . $dev['id'], 'appareil-' . $dev['id'], (string) $dev['name'], 60, false, true, false, []);
        $db->prepare('UPDATE devices SET bio_challenge = ?, bio_challenge_at = ? WHERE id = ?')
           ->execute([wa_b64u_encode($wa->getChallenge()->getBinaryString()), date('Y-m-d H:i:s'), $dev['id']]);
        bio_reply(200, ['ok' => true, 'args' => $args]);
    }
    if ($action === 'reg_verify') {
        $chal = (string) $dev['bio_challenge'];
        $fresh = $dev['bio_challenge_at'] !== null && strtotime((string) $dev['bio_challenge_at']) >= time() - 120;
        $db->prepare('UPDATE devices SET bio_challenge = NULL WHERE id = ?')->execute([$dev['id']]);
        if ($chal === '' || !$fresh) {
            bio_reply(400, ['ok' => false, 'error' => 'Demande expirée, recommencez.']);
        }
        $data = $wa->processCreate($in('clientDataJSON'), $in('attestationObject'), wa_b64u_decode($chal), true, true, false);
        $db->prepare('UPDATE devices SET bio_credential_id = ?, bio_public_key = ?, bio_counter = ? WHERE id = ?')
           ->execute([wa_b64u_encode($data->credentialId), $data->credentialPublicKey, (int) ($data->signatureCounter ?? 0), $dev['id']]);
        auth_log($db, 'bio_activee', (int) $dev['id'], '');
        bio_reply(200, ['ok' => true]);
    }
    bio_reply(400, ['ok' => false, 'error' => 'Action inconnue.']);
} catch (Throwable $e) {
    error_log('bio.php : ' . $e->getMessage());
    bio_reply(400, ['ok' => false, 'error' => 'Opération biométrique refusée (' . (!empty(local_config()['debug']) ? $e->getMessage() : 'réessayez') . ').']);
}
