<?php
declare(strict_types=1);

/*
 * V3.0 — Authentification de la vue mobile par appareil enrôlé : code PIN et/ou biométrie (WebAuthn).
 *
 *  - Chaque téléphone est enrôlé depuis l'administration PC (QR code à usage unique, 5 minutes).
 *  - Un cookie d'appareil (long, révocable) identifie le téléphone ; le PIN ou l'empreinte ouvre une session courte.
 *  - Le mot de passe « master » ne sert plus que pour le PC (administration) et ne s'utilise pas sur mobile.
 */

const DEV_COOKIE   = 'rechargeplus_dev';
const SESS_COOKIE  = 'rechargeplus_sess';
const ENROLL_TTL   = 300;   // secondes de validité d'un QR code d'enrôlement

/* ---------- Réglages de sécurité ---------- */

function sec_int(string $key, int $min, int $max): int
{
    return max($min, min($max, (int) (settings()[$key] ?? 0)));
}

function sec_identity_mode(): string  { return (settings()['identity_mode'] ?? 'unique') === 'distinct' ? 'distinct' : 'unique'; }
function sec_lock_minutes(): int      { return sec_int('lock_minutes', 1, 240); }
function sec_device_days(): int       { return sec_int('device_days', 1, 365); }
function sec_pin_max_fails(): int     { return sec_int('pin_max_fails', 3, 10); }
function sec_max_devices(): int       { return sec_int('max_devices', 1, 20); }

/* ---------- Règles du code PIN ---------- */

/** Message d'erreur si le PIN est refusé, null s'il est valide. */
function pin_error(string $pin): ?string
{
    if (!preg_match('/^\d{4,6}$/', $pin)) {
        return 'Le code PIN doit comporter de 4 à 6 chiffres.';
    }
    $d    = array_map('intval', str_split($pin));
    $step = $d[1] - $d[0];
    $same = true;
    for ($i = 2; $i < count($d); $i++) {
        if ($d[$i] - $d[$i - 1] !== $step) {
            $same = false;
            break;
        }
    }
    if ($same) {
        return $step === 0
            ? 'Code trop simple : évitez les chiffres identiques (ex. 1111).'
            : 'Code trop simple : évitez les suites de chiffres (ex. 1234, 9876, 2468).';
    }
    return null;
}

function pin_hash(string $pin): string
{
    if (defined('PASSWORD_ARGON2ID')) {
        return password_hash($pin, PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]);
    }
    return password_hash($pin, PASSWORD_BCRYPT, ['cost' => 12]);
}

/* ---------- Journal ---------- */

function auth_log(PDO $db, string $event, ?int $deviceId = null, string $detail = ''): void
{
    try {
        $db->prepare('INSERT INTO auth_log (at, ip, device_id, event, detail) VALUES (?, ?, ?, ?, ?)')
           ->execute([date('Y-m-d H:i:s'), client_ip(), $deviceId, substr($event, 0, 32), substr($detail, 0, 255)]);
        if (random_int(1, 50) === 1) {
            $db->prepare('DELETE FROM auth_log WHERE at < ?')->execute([date('Y-m-d H:i:s', time() - 365 * 86400)]);
        }
    } catch (Throwable $e) {
        error_log('auth_log : ' . $e->getMessage());
    }
}

/* ---------- Utilisateurs ---------- */

function default_user_id(PDO $db): int
{
    $id = $db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $db->prepare('INSERT INTO users (name) VALUES (?)')->execute(['Principal']);
    return (int) $db->lastInsertId();
}

function user_for_name(PDO $db, string $name): int
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $st = $db->prepare('SELECT id FROM users WHERE name = ? LIMIT 1');
    $st->execute([$name]);
    $id = $st->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $db->prepare('INSERT INTO users (name) VALUES (?)')->execute([$name]);
    return (int) $db->lastInsertId();
}

function users_all(PDO $db): array
{
    return $db->query('SELECT id, name FROM users ORDER BY name')->fetchAll();
}

/* ---------- Appareils ---------- */

function devices_active_count(PDO $db): int
{
    return (int) $db->query('SELECT COUNT(*) FROM devices WHERE revoked_at IS NULL')->fetchColumn();
}

function device_find(PDO $db, int $id): ?array
{
    $st = $db->prepare('SELECT d.*, u.name AS user_name FROM devices d JOIN users u ON u.id = d.user_id WHERE d.id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

/** Crée l'appareil et renvoie [id, secret du cookie]. */
function device_create(PDO $db, int $userId, string $name, string $pin): array
{
    $secret = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO devices (user_id, name, token_hash, pin_hash, created_at, last_seen_at, last_ip) VALUES (?, ?, ?, ?, ?, ?, ?)')
       ->execute([$userId, $name, hash('sha256', $secret), pin_hash($pin), date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), client_ip()]);
    return [(int) $db->lastInsertId(), $secret];
}

function cookie_opts(int $expires): array
{
    return ['expires' => $expires, 'path' => cookie_path(), 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict'];
}

function device_cookie_set(int $id, string $secret): void
{
    setcookie(DEV_COOKIE, $id . '.' . $secret, cookie_opts(time() + 86400 * sec_device_days()));
}

function device_cookie_clear(): void
{
    setcookie(DEV_COOKIE, '', cookie_opts(time() - 3600));
}

/** Appareil identifié par son cookie (non révoqué, non expiré), sinon null. La session n'est pas contrôlée ici. */
function device_from_cookie(PDO $db): ?array
{
    $c = $_COOKIE[DEV_COOKIE] ?? '';
    if (!is_string($c) || !preg_match('/^(\d{1,9})\.([a-f0-9]{64})$/', $c, $m)) {
        return null;
    }
    $dev = device_find($db, (int) $m[1]);
    if (!$dev || $dev['revoked_at'] !== null || !hash_equals((string) $dev['token_hash'], hash('sha256', $m[2]))) {
        return null;
    }
    $last = strtotime((string) ($dev['last_seen_at'] ?? $dev['created_at']));
    if ($last !== false && $last + 86400 * sec_device_days() < time()) {
        return null;   // inutilisé trop longtemps : ré-enrôlement nécessaire
    }
    return $dev;
}

/* ---------- Session courte de l'appareil ---------- */

function sess_hmac(array $dev, int $exp): string
{
    return hash_hmac('sha256', 'sess|' . $dev['id'] . '|' . $exp, (string) config()['secret'] . '|' . $dev['token_hash']);
}

function device_session_set(array $dev): int
{
    $exp = time() + 60 * sec_lock_minutes();
    if (!headers_sent()) {
        setcookie(SESS_COOKIE, $dev['id'] . '.' . $exp . '.' . sess_hmac($dev, $exp), cookie_opts(0));
    }
    return $exp;
}

function device_session_clear(): void
{
    if (!headers_sent()) {
        setcookie(SESS_COOKIE, '', cookie_opts(time() - 3600));
    }
}

/** Ouvre une session après un PIN ou une empreinte valides. */
function device_session_start(PDO $db, array $dev): void
{
    device_session_set($dev);
    $db->prepare('UPDATE devices SET last_seen_at = ?, last_ip = ? WHERE id = ?')
       ->execute([date('Y-m-d H:i:s'), client_ip(), $dev['id']]);
    $_COOKIE[SESS_COOKIE] = $dev['id'] . '.' . (time() + 60 * sec_lock_minutes()) . '.' . sess_hmac($dev, time() + 60 * sec_lock_minutes());
    auth_context(true);
}

/**
 * Contexte d'authentification de la requête :
 *  ['role' => 'master'|'device', 'device' => ?array, 'user_id' => ?int, 'user_name' => ?string] ou null.
 */
function auth_context(bool $reset = false): ?array
{
    static $ctx = false;
    if ($reset) {
        $ctx = false;
    }
    if ($ctx !== false) {
        return $ctx;
    }
    $ctx = null;
    if (!auth_configured() || app_state() !== 'ready') {
        return $ctx;
    }
    if (is_logged_in()) {
        return $ctx = ['role' => 'master', 'device' => null, 'user_id' => null, 'user_name' => null];
    }
    $s = $_COOKIE[SESS_COOKIE] ?? '';
    if (!is_string($s) || !preg_match('/^(\d{1,9})\.(\d{10})\.([a-f0-9]{64})$/', $s, $m) || (int) $m[2] < time()) {
        return $ctx;
    }
    $db  = db();
    $dev = device_from_cookie($db);
    if (!$dev || (int) $dev['id'] !== (int) $m[1] || (int) $dev['locked'] === 1 || !hash_equals(sess_hmac($dev, (int) $m[2]), $m[3])) {
        return $ctx;
    }
    // Session glissante : prolongée par l'activité.
    if ((int) $m[2] - time() < 60 * sec_lock_minutes() - 30) {
        device_session_set($dev);
    }
    return $ctx = ['role' => 'device', 'device' => $dev, 'user_id' => (int) $dev['user_id'], 'user_name' => (string) $dev['user_name']];
}

/** Graine du jeton CSRF d'une session d'appareil (stable pendant la vie de l'appareil). */
function auth_device_seed(): ?string
{
    $c = auth_context();
    return ($c && $c['role'] === 'device') ? $c['device']['id'] . '|' . substr((string) $c['device']['token_hash'], 0, 16) : null;
}

/** Utilisateur à associer à une recharge saisie par l'appareil courant (null hors mode « utilisateurs distincts »). */
function attribution_user_id(): ?int
{
    $c = auth_context();
    if ($c && $c['role'] === 'device' && sec_identity_mode() === 'distinct') {
        return (int) $c['user_id'];
    }
    return null;
}

/** Pages de la vue mobile : session d'appareil (PIN/empreinte) ou session master. */
function require_user(): array
{
    $c = auth_context();
    if ($c !== null) {
        return $c;
    }
    $next = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    header('Location: deverrouiller.php?next=' . rawurlencode(in_array($next, ['index.php', 'stats.php'], true) ? $next : 'index.php'));
    exit;
}

/** Le PC seulement : refuse le mot de passe master sur un navigateur mobile (contrôle d'après le User-Agent). */
function is_mobile_browser(): bool
{
    return (bool) preg_match('/Android|iPhone|iPad|iPod|Mobile/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

/* ---------- Vérification du PIN ---------- */

/**
 * Contrôle un PIN pour un appareil. Renvoie ['ok'=>bool, 'locked'=>bool, 'remaining'=>int].
 * Les échecs sont comptés par appareil et journalisés ; au-delà du seuil, l'appareil est bloqué (déblocage depuis le PC).
 */
function device_check_pin(PDO $db, array $dev, string $pin): array
{
    $max = sec_pin_max_fails();
    if ((int) $dev['locked'] === 1) {
        return ['ok' => false, 'locked' => true, 'remaining' => 0];
    }
    if ($pin !== '' && preg_match('/^\d{4,6}$/', $pin) && password_verify($pin, (string) $dev['pin_hash'])) {
        $db->prepare('UPDATE devices SET pin_fails = 0 WHERE id = ?')->execute([$dev['id']]);
        return ['ok' => true, 'locked' => false, 'remaining' => $max];
    }
    usleep(400000);
    $db->prepare('UPDATE devices SET pin_fails = pin_fails + 1 WHERE id = ? AND locked = 0')->execute([$dev['id']]);
    $fails = (int) $db->query('SELECT pin_fails FROM devices WHERE id = ' . (int) $dev['id'])->fetchColumn();
    auth_log($db, 'pin_echec', (int) $dev['id'], 'échec ' . $fails . '/' . $max);
    if ($fails >= $max) {
        $db->prepare('UPDATE devices SET locked = 1 WHERE id = ?')->execute([$dev['id']]);
        auth_log($db, 'appareil_bloque', (int) $dev['id'], 'trop d\'échecs de PIN');
        return ['ok' => false, 'locked' => true, 'remaining' => 0];
    }
    return ['ok' => false, 'locked' => false, 'remaining' => $max - $fails];
}

/* ---------- Jetons d'enrôlement (QR code) ---------- */

function enroll_code_hash(string $code): string
{
    return hash_hmac('sha256', 'enroll-code|' . $code, (string) config()['secret']);
}

/**
 * Crée un jeton à usage unique ; renvoie ['token' => clair, 'code' => 8 chiffres, 'expires' => timestamp].
 * Le code sert à enrôler à la main (application installée sur l'écran d'accueil, où le QR code ne peut pas s'ouvrir).
 */
function enroll_token_create(PDO $db, int $userId, string $deviceName): array
{
    $token = bin2hex(random_bytes(24));
    $exp   = time() + ENROLL_TTL;
    do {   // un code est unique parmi les jetons encore valides
        $code = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $st = $db->prepare('SELECT COUNT(*) FROM enroll_tokens WHERE code_hash = ? AND used_at IS NULL AND expires_at >= ?');
        $st->execute([enroll_code_hash($code), date('Y-m-d H:i:s')]);
    } while ((int) $st->fetchColumn() > 0);
    $db->prepare('INSERT INTO enroll_tokens (token_hash, code_hash, user_id, device_name, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([hash('sha256', $token), enroll_code_hash($code), $userId, $deviceName, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', $exp)]);
    return ['token' => $token, 'code' => $code, 'expires' => $exp];
}

/** Trop d'essais de code depuis cette adresse (10 en 15 minutes) ? */
function enroll_code_blocked(PDO $db): bool
{
    $st = $db->prepare("SELECT COUNT(*) FROM auth_log WHERE event = 'code_echec' AND ip = ? AND at >= ?");
    $st->execute([client_ip(), date('Y-m-d H:i:s', time() - 900)]);
    return (int) $st->fetchColumn() >= 10;
}

/** Jeton valide désigné par son code de 8 chiffres, ou null (l'échec est compté). */
function enroll_token_find_by_code(PDO $db, string $code): ?array
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (enroll_code_blocked($db)) {
        return null;
    }
    if (strlen($code) === 8) {
        $st = $db->prepare('SELECT * FROM enroll_tokens WHERE code_hash = ? AND used_at IS NULL AND expires_at >= ?');
        $st->execute([enroll_code_hash($code), date('Y-m-d H:i:s')]);
        $r = $st->fetch();
        if ($r) {
            return $r;
        }
    }
    auth_log($db, 'code_echec', null, 'code d\'enrôlement refusé');
    usleep(300000);
    return null;
}

/** Jeton valide (non utilisé, non expiré) ou null. */
function enroll_token_find(PDO $db, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $st = $db->prepare('SELECT * FROM enroll_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at >= ?');
    $st->execute([hash('sha256', $token), date('Y-m-d H:i:s')]);
    $r = $st->fetch();
    return $r ?: null;
}

/** Consomme le jeton de façon atomique ; false s'il a déjà servi ou a expiré. */
function enroll_token_consume(PDO $db, int $id): bool
{
    $st = $db->prepare('UPDATE enroll_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL AND expires_at >= ?');
    $st->execute([date('Y-m-d H:i:s'), $id, date('Y-m-d H:i:s')]);
    return $st->rowCount() === 1;
}

/* ---------- Biométrie (WebAuthn) ---------- */

function wa_b64u_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function wa_b64u_decode(string $s): string
{
    $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    return $r === false ? '' : $r;
}

function wa_rp_id(): string
{
    return strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
}

function wa_server(): \lbuchs\WebAuthn\WebAuthn
{
    require_once __DIR__ . '/lib/webauthn/WebAuthn.php';
    return new \lbuchs\WebAuthn\WebAuthn('RechargePlus', wa_rp_id(), ['none'], true);
}
