# Setting up PHP (Windows)

This app needs **PHP** (it uses SQLite, which is built into PHP — no separate database server).

## 1. Do you already have PHP?

Open **Command Prompt** or **PowerShell**:

```bash
php -v
```

- Shows a version → you're ready, skip to step 3.
- "not recognized" → install PHP (step 2).

## 2. Install PHP

**Easiest — XAMPP** (bundles PHP with SQLite enabled): download from https://www.apachefriends.org and install. PHP ends up at `C:\xampp\php\php.exe`.

**Standalone PHP:** download the **Thread Safe** zip from https://windows.php.net/download, extract to `C:\php`, then copy `php.ini-development` to `php.ini` and ensure these lines are uncommented:

```
extension_dir = "ext"
extension=pdo_sqlite
extension=sqlite3
```

Add `C:\php` to your PATH so `php` works anywhere.

## 3. Run the app

From this folder:

```bash
php -S localhost:8000
```

If `php` isn't on your PATH, use the full path, e.g. with XAMPP:

```bash
C:/xampp/php/php.exe -S localhost:8000
```

Then open **http://localhost:8000** and sign in with any email (the first one becomes the admin).

To stop the server: **Ctrl+C**.
