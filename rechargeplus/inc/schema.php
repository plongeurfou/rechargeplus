<?php
declare(strict_types=1);

/** Crée les tables manquantes (sans toucher aux données existantes). */
function create_tables(PDO $db): void
{
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $db->exec("CREATE TABLE IF NOT EXISTS recharges (
        id          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
        charged_at  DATETIME          NOT NULL,
        kwh         DECIMAL(7,2)      NOT NULL,
        kwh_billed  SMALLINT UNSIGNED NOT NULL,
        type        ENUM('AC','DC')   NOT NULL,
        rate_htva   DECIMAL(6,4)      NOT NULL,
        vat_pct     DECIMAL(4,2)      NOT NULL,
        amount_htva DECIMAL(10,2)     NOT NULL,
        amount_ttc  DECIMAL(10,2)     NOT NULL,
        latitude    DECIMAL(9,6)      NULL,
        longitude   DECIMAL(9,6)      NULL,
        accuracy_m  SMALLINT UNSIGNED NULL,
        odometer_km INT UNSIGNED      NULL,
        client_uid  CHAR(36)          NULL,
        in_budget   TINYINT(1)        NOT NULL DEFAULT 1,
        user_id     INT UNSIGNED      NULL,
        created_at  TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_charged_at (charged_at),
        UNIQUE KEY uq_client_uid (client_uid)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        ip           VARCHAR(45)  NOT NULL,
        attempted_at DATETIME     NOT NULL,
        PRIMARY KEY (id),
        KEY idx_ip_time (ip, attempted_at)
    ) $opts");

    // V3.0 : utilisateurs, appareils enrôlés (code PIN + biométrie), jetons d'enrôlement et journal
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name       VARCHAR(60)  NOT NULL,
        created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS devices (
        id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id           INT UNSIGNED NOT NULL,
        name              VARCHAR(60)  NOT NULL,
        token_hash        CHAR(64)     NOT NULL,
        pin_hash          VARCHAR(255) NOT NULL DEFAULT '',
        pin_fails         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        locked            TINYINT(1)   NOT NULL DEFAULT 0,
        bio_credential_id VARCHAR(1400) NULL,
        bio_public_key    TEXT         NULL,
        bio_counter       INT UNSIGNED NOT NULL DEFAULT 0,
        bio_challenge     VARCHAR(128) NULL,
        bio_challenge_at  DATETIME     NULL,
        created_at        DATETIME     NOT NULL,
        last_seen_at      DATETIME     NULL,
        last_ip           VARCHAR(45)  NULL,
        revoked_at        DATETIME     NULL,
        PRIMARY KEY (id),
        KEY idx_user (user_id)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS enroll_tokens (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        token_hash  CHAR(64)     NOT NULL,
        user_id     INT UNSIGNED NOT NULL,
        device_name VARCHAR(60)  NOT NULL,
        created_at  DATETIME     NOT NULL,
        expires_at  DATETIME     NOT NULL,
        used_at     DATETIME     NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_token (token_hash)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS auth_log (
        id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        at        DATETIME        NOT NULL,
        ip        VARCHAR(45)     NOT NULL,
        device_id INT UNSIGNED    NULL,
        event     VARCHAR(32)     NOT NULL,
        detail    VARCHAR(255)    NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        KEY idx_at (at)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        name       VARCHAR(64) NOT NULL,
        value      TEXT        NOT NULL,
        updated_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (name)
    ) $opts");
}
