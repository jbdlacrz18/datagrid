# Deploying DataGrid to GitHub + Vercel

This app runs on **PHP** with a **dual database driver**:

- **Local dev** → SQLite (zero setup).
- **Production (Vercel)** → PostgreSQL, used automatically when a `DATABASE_URL` (or `POSTGRES_URL`) env var is present.

Auth is a **stateless signed cookie** (no server sessions), so it works on Vercel's serverless functions. Attachments are stored **in the database** (no disk needed).

---

## 1. Push to GitHub

From this folder:

```bash
git init
git add .
git commit -m "DataGrid: PHP + Postgres, cookie auth"
git branch -M main
git remote add origin https://github.com/<you>/<repo>.git
git push -u origin main
```

`.gitignore` already excludes the local SQLite DB, uploads, and `.env`.

## 2. Create a free PostgreSQL database

Any Postgres works. Easiest options:

- **Neon** (neon.tech) → create a project → copy the connection string (looks like `postgresql://user:pass@ep-xxx.region.aws.neon.tech/dbname?sslmode=require`).
- or **Vercel Postgres** (Vercel dashboard → Storage → Create → Postgres) → it auto-adds `POSTGRES_URL` to your project.

The app creates its own tables on first request — you don't need to run any SQL.

## 3. Import the repo into Vercel

1. vercel.com → **Add New → Project** → import your GitHub repo.
2. Framework preset: **Other** (the included `vercel.json` configures the PHP runtime).
3. **Environment Variables** — add:
   - `DATABASE_URL` = your Postgres connection string (skip if you used Vercel Postgres, which sets `POSTGRES_URL` for you).
   - `AUTH_SECRET` = a long random string (used to sign login cookies). Generate one:
     ```bash
     openssl rand -hex 32
     ```
4. **Deploy.**

## 4. First run

Open your Vercel URL → you'll land on **/login.php** → click **Create an account**. **The first account becomes the workspace administrator.** Everyone who registers after is a regular member (per-user data isolation; admins manage columns, tables, roles, and settings).

---

## How `vercel.json` works

```json
{
  "version": 2,
  "builds": [
    { "src": "*.php", "use": "vercel-php@0.7.0", "config": { "includeFiles": "**" } }
  ],
  "routes": [
    { "src": "/", "dest": "/index.php" },
    { "handle": "filesystem" }
  ]
}
```

- Uses **`builds`** (not `functions`): the `functions` property only picks up files inside an `/api` directory, but this app's PHP lives at the project root. `builds` compiles every root `*.php` into a serverless function with the community `vercel-php` runtime.
- `includeFiles: "**"` bundles the shared PHP (`config/db/auth/functions.php`) and the `view/` HTML into each function so `require` and `readfile` work.
- `routes`: `/` serves `index.php`; `handle: filesystem` serves everything else at its own path (`/login.php`, `/register.php`, `/api.php`, …).
- If a deploy fails on the runtime, bump the version (latest `vercel-php@x.y.z` at github.com/vercel-community/php).


### Requirement: `pdo_pgsql`
The PHP runtime must have the **`pdo_pgsql`** extension (it's included in recent `vercel-php` builds). If you see a "could not find driver" error in the Vercel function logs, pin a `vercel-php` version known to bundle `pdo_pgsql`, or switch the DB to one your runtime supports.

---

## Local development (SQLite, no Postgres needed)

Just don't set `DATABASE_URL`:

```bash
php -S localhost:8000
```

Open http://localhost:8000 → register the first account. Data goes to `data/datagrid.sqlite`.

To test the Postgres path locally, set the env var first:

```bash
DATABASE_URL="postgresql://user:pass@host/db?sslmode=require" php -S localhost:8000
```

---

## Notes & limits on Vercel

- **Attachments** are capped at 4 MB each and stored in Postgres (base64). Fine for documents/images; not for large media.
- **No background jobs / websockets** — the app already polls for live updates, which works on serverless.
- Set a strong `AUTH_SECRET`. If it changes, existing login cookies are invalidated (everyone just signs in again).
