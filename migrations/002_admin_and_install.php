<?php
/**
 * Adds an administrator role and the settings table the installer uses.
 *
 * Until now every account was equal, which left no way to upgrade a user
 * after they pay, suspend an abusive code, or see system-wide numbers
 * without opening a database client.
 */
return [
    'ALTER TABLE users ADD COLUMN is_admin {{SMALLINT}} NOT NULL DEFAULT 0',

    'CREATE INDEX IF NOT EXISTS idx_users_admin ON users (is_admin)',

    // Small key/value store for things set once at install time and read
    // rarely, such as the install marker and the site name.
    'CREATE TABLE IF NOT EXISTS settings (
        name       {{STR}}(64) NOT NULL PRIMARY KEY,
        value      {{TEXT}}    NULL,
        updated_at {{INT}}     NOT NULL
    ) {{TABLE_OPTS}}',
];
