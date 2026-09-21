<?php
/**
 * Initial schema.
 *
 * Design notes:
 *  - `links.slug` is the public identifier baked into a printed QR code. It is
 *    immutable in the UI for that reason; the destination is what changes.
 *  - `scans` is the append-only raw event log. `scan_daily` is a pre-aggregated
 *    rollup so dashboards never scan the raw table.
 *  - IPs are never stored in the clear: we keep a keyed hash, used only for
 *    same-visitor de-duplication and abuse control.
 */
return [

    // ---------------------------------------------------------------- users
    'CREATE TABLE IF NOT EXISTS users (
        id               {{PK}},
        email            {{STR}}(191) NOT NULL,
        password_hash    {{STR}}(255) NOT NULL,
        display_name     {{STR}}(80)  NOT NULL DEFAULT \'\',
        plan             {{STR}}(20)  NOT NULL DEFAULT \'free\',
        plan_expires_at  {{INT}}      NULL,
        status           {{STR}}(20)  NOT NULL DEFAULT \'active\',
        totp_secret      {{STR}}(64)  NULL,
        failed_logins    {{SMALLINT}} NOT NULL DEFAULT 0,
        locked_until     {{INT}}      NULL,
        created_at       {{INT}}      NOT NULL,
        updated_at       {{INT}}      NOT NULL
    ) {{TABLE_OPTS}}',
    'CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email ON users (email)',

    // ------------------------------------------------------------- sessions
    'CREATE TABLE IF NOT EXISTS sessions (
        id          {{STR}}(64) NOT NULL PRIMARY KEY,
        user_id     {{FK}}      NOT NULL,
        ip_hash     {{STR}}(64) NOT NULL DEFAULT \'\',
        ua_hash     {{STR}}(64) NOT NULL DEFAULT \'\',
        created_at  {{INT}}     NOT NULL,
        last_seen_at {{INT}}    NOT NULL,
        expires_at  {{INT}}     NOT NULL
    ) {{TABLE_OPTS}}',
    'CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions (user_id)',
    'CREATE INDEX IF NOT EXISTS idx_sessions_exp ON sessions (expires_at)',

    // ---------------------------------------------------------------- links
    'CREATE TABLE IF NOT EXISTS links (
        id            {{PK}},
        user_id       {{FK}}       NOT NULL,
        slug          {{STR}}(32)  NOT NULL,
        title         {{STR}}(120) NOT NULL DEFAULT \'\',
        default_url   {{STR}}(2048) NOT NULL,
        is_active     {{SMALLINT}} NOT NULL DEFAULT 1,
        style_json    {{TEXT}}     NULL,
        scan_count    {{INT}}      NOT NULL DEFAULT 0,
        last_scan_at  {{INT}}      NULL,
        password_hash {{STR}}(255) NULL,
        expires_at    {{INT}}      NULL,
        expired_url   {{STR}}(2048) NULL,
        archived_at   {{INT}}      NULL,
        created_at    {{INT}}      NOT NULL,
        updated_at    {{INT}}      NOT NULL
    ) {{TABLE_OPTS}}',
    'CREATE UNIQUE INDEX IF NOT EXISTS idx_links_slug ON links (slug)',
    'CREATE INDEX IF NOT EXISTS idx_links_user ON links (user_id, archived_at)',

    // ---------------------------------------------------------------- rules
    'CREATE TABLE IF NOT EXISTS rules (
        id           {{PK}},
        link_id      {{FK}}       NOT NULL,
        label        {{STR}}(120) NOT NULL DEFAULT \'\',
        priority     {{SMALLINT}} NOT NULL DEFAULT 0,
        conditions   {{TEXT}}     NOT NULL,
        target_url   {{STR}}(2048) NOT NULL,
        is_active    {{SMALLINT}} NOT NULL DEFAULT 1,
        hits         {{INT}}      NOT NULL DEFAULT 0,
        created_at   {{INT}}      NOT NULL,
        updated_at   {{INT}}      NOT NULL
    ) {{TABLE_OPTS}}',
    'CREATE INDEX IF NOT EXISTS idx_rules_link ON rules (link_id, priority)',

    // ---------------------------------------------------------------- scans
    'CREATE TABLE IF NOT EXISTS scans (
        id           {{PK}},
        link_id      {{FK}}       NOT NULL,
        rule_id      {{FK}}       NULL,
        user_id      {{FK}}       NOT NULL,
        scanned_at   {{INT}}      NOT NULL,
        day          {{STR}}(10)  NOT NULL,
        device       {{STR}}(16)  NOT NULL DEFAULT \'unknown\',
        os           {{STR}}(24)  NOT NULL DEFAULT \'unknown\',
        browser      {{STR}}(24)  NOT NULL DEFAULT \'unknown\',
        country      {{STR}}(2)   NOT NULL DEFAULT \'\',
        lang         {{STR}}(8)   NOT NULL DEFAULT \'\',
        referer_host {{STR}}(191) NOT NULL DEFAULT \'\',
        is_bot       {{SMALLINT}} NOT NULL DEFAULT 0,
        is_unique    {{SMALLINT}} NOT NULL DEFAULT 1,
        visitor_hash {{STR}}(64)  NOT NULL DEFAULT \'\'
    ) {{TABLE_OPTS}}',
    'CREATE INDEX IF NOT EXISTS idx_scans_link_time ON scans (link_id, scanned_at)',
    'CREATE INDEX IF NOT EXISTS idx_scans_user_day ON scans (user_id, day)',
    'CREATE INDEX IF NOT EXISTS idx_scans_visitor ON scans (link_id, visitor_hash, scanned_at)',

    // ----------------------------------------------------------- scan_daily
    'CREATE TABLE IF NOT EXISTS scan_daily (
        id        {{PK}},
        link_id   {{FK}}      NOT NULL,
        day       {{STR}}(10) NOT NULL,
        dimension {{STR}}(12) NOT NULL,
        value     {{STR}}(64) NOT NULL,
        total     {{INT}}     NOT NULL DEFAULT 0,
        uniques   {{INT}}     NOT NULL DEFAULT 0
    ) {{TABLE_OPTS}}',
    'CREATE UNIQUE INDEX IF NOT EXISTS idx_daily_unique ON scan_daily (link_id, day, dimension, value)',

    // ------------------------------------------------------------ api_keys
    'CREATE TABLE IF NOT EXISTS api_keys (
        id           {{PK}},
        user_id      {{FK}}       NOT NULL,
        name         {{STR}}(80)  NOT NULL DEFAULT \'\',
        prefix       {{STR}}(12)  NOT NULL,
        key_hash     {{STR}}(64)  NOT NULL,
        scopes       {{STR}}(191) NOT NULL DEFAULT \'read,write\',
        last_used_at {{INT}}      NULL,
        revoked_at   {{INT}}      NULL,
        created_at   {{INT}}      NOT NULL
    ) {{TABLE_OPTS}}',
    'CREATE UNIQUE INDEX IF NOT EXISTS idx_apikeys_hash ON api_keys (key_hash)',
    'CREATE INDEX IF NOT EXISTS idx_apikeys_user ON api_keys (user_id)',

    // -------------------------------------------------------- rate_limits
    'CREATE TABLE IF NOT EXISTS rate_limits (
        id           {{PK}},
        bucket       {{STR}}(191) NOT NULL,
        window_start {{INT}}      NOT NULL,
        hits         {{INT}}      NOT NULL DEFAULT 0
    ) {{TABLE_OPTS}}',
    'CREATE UNIQUE INDEX IF NOT EXISTS idx_rl_bucket ON rate_limits (bucket, window_start)',

    // --------------------------------------------------------- audit_log
    'CREATE TABLE IF NOT EXISTS audit_log (
        id         {{PK}},
        user_id    {{FK}}       NULL,
        action     {{STR}}(64)  NOT NULL,
        subject    {{STR}}(191) NOT NULL DEFAULT \'\',
        meta       {{TEXT}}     NULL,
        ip_hash    {{STR}}(64)  NOT NULL DEFAULT \'\',
        created_at {{INT}}      NOT NULL
    ) {{TABLE_OPTS}}',
    'CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_log (user_id, created_at)',
];
