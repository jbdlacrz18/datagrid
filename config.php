<?php
/**
 * DataGrid — configuration & constants.
 *
 * Storage is dual-driver (see db.php):
 *   - Local dev: SQLite file under data/ (zero setup).
 *   - Production (Vercel): PostgreSQL, selected automatically when DATABASE_URL
 *     (or POSTGRES_URL) is present in the environment.
 *
 * Auth is a stateless signed cookie (see auth.php) — no server sessions — so it
 * works on serverless hosts where the filesystem isn't persistent.
 */

date_default_timezone_set('UTC');

define('APP_TITLE', 'DataGrid');

// Local SQLite location (ignored when a Postgres DATABASE_URL is set).
define('DATA_DIR', __DIR__ . '/data');
define('DB_FILE',  DATA_DIR . '/datagrid.sqlite');

// Auth cookie.
define('AUTH_COOKIE', 'dg_auth');
define('AUTH_TTL', 60 * 60 * 24 * 30);   // 30 days

// Column types supported.
const ALLOWED_TYPES = ['text', 'number', 'price', 'date', 'time', 'dropdown', 'email', 'phone', 'link', 'file'];

// Read-only system columns, always first in every table's schema.
const CREATED_ID   = '_created';
const CREATED_NAME = 'Created At';
const OWNER_ID     = '_owner';
const OWNER_NAME   = 'Added By';

// Access modes.
const ACCESS_PER_USER      = 'perUser';
const ACCESS_ADMIN_MANAGED = 'adminManaged';

// Roles.
const ROLE_ADMIN  = 'admin';
const ROLE_MEMBER = 'member';

// Saved-view scopes.
const VIEW_SCOPE_SHARED   = 'shared';
const VIEW_SCOPE_PERSONAL = 'personal';

// Settings keys.
const CURRENCY_KEY       = 'currency';
const DEFAULT_CURRENCY   = '₱';
const OWNER_KEY          = 'owner_email';
const SHIFT_SEED_KEY     = 'shift_schedule_seeded';
const SHIFT_TABLE_ID_KEY = 'shift_schedule_table_id';
const SCHED_NOTES_PREFIX = 'schednotes_';

// Limits.
const MAX_TABLE_NAME_LEN = 60;
const MAX_VIEW_NAME_LEN  = 60;
const MAX_CURRENCY_LEN   = 3;
const MAX_FILES_PER_CELL = 20;
const MAX_UPLOAD_BYTES   = 4 * 1024 * 1024;   // 4 MB per file (stored in the DB)
const DEFAULT_TABLE_NAME = 'Main';

// Built-in Shift Schedule.
const SHIFT_TABLE_NAME     = 'Shift Schedule';
const SHIFT_STATUS_NAME    = 'Status';
const SHIFT_LOCATION_NAME  = 'Location';
const SHIFT_HIGHLIGHT_NAME = 'Highlight';
const SHIFT_STATUS_OPTS    = ['Working', 'Rest Day', 'Leave'];
const SHIFT_LOCATION_OPTS  = ['On-site', 'WFH'];
const SHIFT_HIGHLIGHT_OPTS = ['Yellow', 'Green', 'Blue', 'Pink'];
