<?php
require_once __DIR__ . '/includes/config.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    // Same accounts as the billing panel. This page only READS admin_users.
    $st = tm_db()->prepare('SELECT id, password, role FROM admin_users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();

    if ($u && password_verify($password, $u['password'])) {
        session_regenerate_id(true);
        $_SESSION['tm_admin_id']   = $u['id'];
        $_SESSION['tm_admin_user'] = $username;
        $_SESSION['tm_role']       = $u['role'] ?? 'admin';
        header('Location: /index.php');
        exit;
    }
    // Slow down a password guesser without making a real typo annoying.
    usleep(400000);
    $error = 'Wrong username or password.';
}

$site = tm_setting('site_name', 'Traffic Monitor');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($site) ?> - Login</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
html,body{height:100%}
body{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;
 background:radial-gradient(1100px 600px at 50% -10%,#17244a 0%,transparent 55%),#070c1a;
 display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;color:#e6ecf7}
.box{background:linear-gradient(180deg,rgba(19,28,49,.9) 0%,rgba(23,33,54,.9) 100%);
 border:1px solid rgba(99,102,241,.22);padding:38px 34px 32px;border-radius:20px;width:390px;max-width:100%;
 box-shadow:0 30px 80px -25px rgba(0,0,0,.85);position:relative;overflow:hidden}
.box::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;
 background:linear-gradient(90deg,transparent,#6366f1,#22d3ee,#3b82f6,transparent)}
.mark{width:62px;height:62px;border-radius:18px;margin:0 auto 16px;
 background:linear-gradient(135deg,#6366f1 0%,#3b82f6 55%,#22d3ee 120%);
 display:flex;align-items:center;justify-content:center;box-shadow:0 14px 30px -10px rgba(99,102,241,.6)}
.mark svg{width:30px;height:30px;stroke:#fff}
h1{text-align:center;font-size:19px;font-weight:700;margin-bottom:4px}
p.sub{text-align:center;color:#6b7994;font-size:12.5px;margin-bottom:24px}
label{display:block;color:#8b98b0;font-size:13px;margin-bottom:6px}
input{width:100%;padding:11px 14px;background:#0c1526;border:1px solid #24314d;border-radius:9px;
 color:#f1f5f9;font-size:14px;outline:none;margin-bottom:15px}
input:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.15)}
button{width:100%;padding:12px;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;
 background:linear-gradient(135deg,#6366f1,#3b82f6);color:#fff;margin-top:4px}
.err{background:rgba(127,29,29,.4);color:#fca5a5;border:1px solid rgba(239,68,68,.3);
 padding:10px 13px;border-radius:9px;font-size:13px;margin-bottom:16px;text-align:center}
.hint{text-align:center;color:#4d5a75;font-size:11.5px;margin-top:18px}
</style>
</head>
<body>
<form class="box" method="post" autocomplete="off">
  <div class="mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg></div>
  <h1><?= h($site) ?></h1>
  <p class="sub">MikroTik live traffic &amp; usage</p>
  <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
  <label>Username</label>
  <input type="text" name="username" required autofocus value="<?= h($_POST['username'] ?? '') ?>">
  <label>Password</label>
  <input type="password" name="password" required>
  <button type="submit">Sign in</button>
  <div class="hint">Use the same login as your billing panel.</div>
</form>
</body>
</html>
