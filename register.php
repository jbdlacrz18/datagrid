<?php
require_once __DIR__ . '/functions.php';
init_db();
if (current_user_email() !== '') { header('Location: index.php'); exit; }

$first = users_count() === 0;   // the very first account becomes the owner/admin
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $name     = trim((string) ($_POST['name'] ?? ''));
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm'] ?? '');
    try {
        if ($password !== $confirm) throw new Exception('Passwords don’t match.');
        $u = create_user($email, $name, $password);
        auth_set_cookie($u['email']);
        header('Location: index.php');
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create account · DataGrid</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400..700&display=swap">
  <style>
    :root{--canvas:#f7f9fc;--surface:#fff;--border:#e2e8f0;--border-strong:#cbd5e1;--text:#0f172a;--muted:#64748b;--accent:#2563eb;--accent-hover:#1d4ed8;--radius:8px;}
    *{box-sizing:border-box;}
    body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:radial-gradient(1000px 600px at 80% -10%, #eef2fb 0%, var(--canvas) 60%);font-family:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:var(--text);}
    .card{width:100%;max-width:400px;background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:0 16px 40px rgba(15,23,42,.12);padding:30px 28px 26px;}
    .brand{display:flex;align-items:center;gap:9px;margin-bottom:22px;}
    .brand-mark{color:var(--accent);font-size:22px;line-height:1;}
    .brand-name{font-weight:700;font-size:20px;letter-spacing:-.02em;}
    h1{font-size:18px;margin:0 0 4px;letter-spacing:-.01em;}
    .sub{color:var(--muted);font-size:13.5px;margin:0 0 20px;}
    label{display:block;font-size:12.5px;font-weight:600;color:var(--muted);margin:0 0 6px;}
    .field{margin-bottom:14px;}
    input{width:100%;height:40px;border:1px solid var(--border-strong);border-radius:var(--radius);padding:0 12px;font-size:14px;font-family:inherit;color:var(--text);background:#fff;transition:border-color .12s,box-shadow .12s;}
    input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px #eff6ff;}
    .btn{width:100%;height:42px;border:none;border-radius:var(--radius);background:var(--accent);color:#fff;font-size:14.5px;font-weight:600;font-family:inherit;cursor:pointer;transition:background .12s;margin-top:4px;}
    .btn:hover{background:var(--accent-hover);}
    .err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;font-size:13px;border-radius:var(--radius);padding:9px 12px;margin-bottom:16px;}
    .note{background:#eff6ff;border:1px solid #dbeafe;color:#1d4ed8;font-size:12.5px;border-radius:var(--radius);padding:8px 11px;margin-bottom:16px;}
    .alt{text-align:center;color:var(--muted);font-size:13.5px;margin-top:18px;}
    .alt a{color:var(--accent);font-weight:600;text-decoration:none;}
    .alt a:hover{text-decoration:underline;}
  </style>
</head>
<body>
  <form class="card" method="post" action="register.php" novalidate>
    <div class="brand"><span class="brand-mark" aria-hidden="true">◧</span><span class="brand-name">DataGrid</span></div>
    <h1>Create your account</h1>
    <p class="sub">Set up access to your workspace.</p>
    <?php if ($first): ?><div class="note">You’re the first account — you’ll be the workspace <strong>administrator</strong>.</div><?php endif; ?>
    <?php if ($error): ?><div class="err"><?= $h($error) ?></div><?php endif; ?>
    <div class="field">
      <label for="name">Name <span style="font-weight:400">(optional)</span></label>
      <input id="name" name="name" type="text" autocomplete="name" value="<?= $h($_POST['name'] ?? '') ?>" autofocus>
    </div>
    <div class="field">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" autocomplete="email" required value="<?= $h($_POST['email'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="password">Password <span style="font-weight:400">(min 6 characters)</span></label>
      <input id="password" name="password" type="password" autocomplete="new-password" required>
    </div>
    <div class="field">
      <label for="confirm">Confirm password</label>
      <input id="confirm" name="confirm" type="password" autocomplete="new-password" required>
    </div>
    <button class="btn" type="submit">Create account</button>
    <p class="alt">Already have an account? <a href="login.php">Sign in</a></p>
  </form>
</body>
</html>
