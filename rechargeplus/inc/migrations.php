<?php
declare(strict_types=1);

/*
 * Migrations de la base de données.
 *
 * Chaque migration porte un numéro, ne sert qu'une fois et doit pouvoir être rejouée sans dégât
 * (CREATE ... IF NOT EXISTS, ajout de colonne seulement si elle manque). Elles ne font qu'AJOUTER :
 * l'ancienne version du code reste ainsi compatible avec une base déjà migrée (retour arrière possible).
 * Le numéro atteint est mémorisé dans le réglage « schema_version ».
 */

const SCHEMA_VERSION = 5;

function migrations(): array
{
    $list = [
        // 1 : base de la V2 (tables recharges, login_attempts, settings)
        1 => function (PDO $db): void {
            create_tables($db);
        },
        // 5 : authentification par appareil (V3.0) : tables + colonne user_id des recharges
        5 => function (PDO $db): void {
            create_tables($db);   // crée users, devices, enroll_tokens, auth_log (IF NOT EXISTS)
            if (!db_column_exists($db, 'recharges', 'user_id')) {
                $db->exec('ALTER TABLE recharges ADD COLUMN user_id INT UNSIGNED NULL AFTER in_budget');
            }
        },
        // 4 : recharge hors budget (V2.5) — par défaut toutes les recharges comptent dans le budget
        4 => function (PDO $db): void {
            if (!db_column_exists($db, 'recharges', 'in_budget')) {
                $db->exec('ALTER TABLE recharges ADD COLUMN in_budget TINYINT(1) NOT NULL DEFAULT 1 AFTER client_uid');
            }
        },
        // 3 : identifiant client des recharges (saisie hors ligne : envoi idempotent) (V2.4)
        3 => function (PDO $db): void {
            if (!db_column_exists($db, 'recharges', 'client_uid')) {
                $db->exec('ALTER TABLE recharges ADD COLUMN client_uid CHAR(36) NULL AFTER odometer_km');
            }
            if (!$db->query("SHOW INDEX FROM recharges WHERE Key_name = 'uq_client_uid'")->fetch()) {
                $db->exec('ALTER TABLE recharges ADD UNIQUE KEY uq_client_uid (client_uid)');
            }
        },
        // 2 : compteur kilométrique facultatif (V2.3)
        2 => function (PDO $db): void {
            if (!db_column_exists($db, 'recharges', 'odometer_km')) {
                $db->exec('ALTER TABLE recharges ADD COLUMN odometer_km INT UNSIGNED NULL AFTER accuracy_m');
            }
        },
    ];
    ksort($list);   // toujours dans l'ordre 1, 2, 3, 4… quelle que soit la place dans le tableau
    return $list;
}

function db_column_exists(PDO $db, string $table, string $column): bool
{
    $st = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (int) $st->fetchColumn() > 0;
}

/**
 * Copie de sécurité de la table des recharges, dans la base elle-même, avant une migration.
 * Une seule copie est conservée (la plus récente) ; aucune donnée n'est écrite dans un fichier.
 */
function backup_recharges(PDO $db, int $number): void
{
    $name = 'recharges_avant_m' . $number;
    foreach ($db->query("SHOW TABLES LIKE 'recharges\\_avant\\_m%'")->fetchAll(PDO::FETCH_COLUMN) as $old) {
        if ($old !== $name) {
            $db->exec('DROP TABLE `' . str_replace('`', '', (string) $old) . '`');
        }
    }
    if (!$db->query("SHOW TABLES LIKE 'recharges'")->fetchColumn()) {
        return;
    }
    $db->exec("DROP TABLE IF EXISTS `$name`");
    $db->exec("CREATE TABLE `$name` LIKE recharges");
    $db->exec("INSERT INTO `$name` SELECT * FROM recharges");
}

/** Joue les migrations manquantes (un verrou évite deux exécutions simultanées). */
function run_pending_migrations(): void
{
    $target = max(SCHEMA_VERSION, (int) max(array_keys(migrations())));
    if ((int) settings()['schema_version'] >= $target) {
        return;
    }
    $db = db();
    if ((int) $db->query("SELECT GET_LOCK('rechargeplus_migrate', 20)")->fetchColumn() !== 1) {
        throw new RuntimeException('Mise à jour de la base de données déjà en cours.');
    }
    try {
        $current = (int) settings(true)['schema_version'];   // relu une fois le verrou obtenu
        foreach (migrations() as $number => $migrate) {
            if ($number > $current) {
                if ($number > 1) {
                    backup_recharges($db, $number);
                }
                $migrate($db);
                save_settings($db, ['schema_version' => (string) $number]);
            }
        }
    } finally {
        $db->query("SELECT RELEASE_LOCK('rechargeplus_migrate')");
    }
}
