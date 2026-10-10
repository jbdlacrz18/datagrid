# DataGrid

A dynamic, Airtable-style CRUD workspace in **PHP** — multiple tables, user-defined columns, inline editing, pagination, search, sort, saved views, Kanban/Dashboard layouts, filters, color rules, CSV import/export, trash & restore, bulk actions, a record side-panel, roles, an audit log, attachments, and light/dark themes. Front-end reused from the original build; the backend is a PHP app with a **dual database driver**.

## Auth

Account-based: **email + password**, via dedicated **[login.php](login.php)** and **[register.php](register.php)** pages. Sign-in uses a **stateless signed cookie** (no server sessions), so it works on serverless hosts. **The first account to register becomes the owner/admin**; everyone else is a member (per-user data isolation). Sign out via the link in the header or **[logout.php](logout.php)**.

## Storage (dual driver)

- **Local dev** → SQLite file at `data/datagrid.sqlite` (zero setup).
- **Production** → PostgreSQL, used automatically when `DATABASE_URL` / `POSTGRES_URL` is set.

The app creates its own tables on first request. Attachments are stored in the database.

## Run locally

```bash
php -S localhost:8000
```

Open http://localhost:8000 → you'll be sent to the login page → **Create an account** (first one is admin). Needs PHP 7.4+ with `pdo_sqlite` (bundled). See [SETUP.md](SETUP.md) if you don't have PHP.

## Deploy (GitHub + Vercel + Postgres)

See **[DEPLOY.md](DEPLOY.md)** for the full walkthrough. In short: push to GitHub, create a free Postgres (Neon or Vercel Postgres), import the repo into Vercel, set `DATABASE_URL` and `AUTH_SECRET`, deploy.

## Project structure

```
├── index.php            # The app (auth-gated); inlines the CSS/JS + google.script.run→fetch shim
├── login.php            # Login page
├── register.php         # Register page (first user = admin)
├── logout.php           # Clears the auth cookie
├── api.php              # RPC endpoint (JSON) + attachment download (?dl=id)
├── auth.php             # Signed-cookie auth + users
├── functions.php        # Backend logic (tables, rows, views, roles, trash, audit…)
├── db.php               # Dual-driver connection (SQLite / Postgres) + schema
├── config.php           # Constants
├── view/
│   ├── Stylesheet.html  # Enterprise Slate theme (CSS)
│   └── JavaScript.html  # Client app
├── vercel.json          # PHP runtime + routing for Vercel
├── .gitignore
├── DEPLOY.md
└── SETUP.md
```

## Environment variables

| Var | Where | Purpose |
|-----|-------|---------|
| `DATABASE_URL` (or `POSTGRES_URL`) | production | Postgres connection string. If unset, the app uses local SQLite. |
| `AUTH_SECRET` | production | Long random string used to sign login cookies. **Set this in production.** |

## Reset local data

```bash
rm data/datagrid.sqlite
```
