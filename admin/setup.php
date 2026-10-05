<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';

$database = muskanDatabase();
$hasAdmin = (int) $database->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
$setupFile = muskanPrivateDirectory() . '/admin-setup.php';
$setupConfig = is_file($setupFile) ? require $setupFile : [];
$expectedToken = is_array($setupConfig) ? (string) ($setupConfig['token'] ?? '') : '';
$providedToken = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$authorised = !$hasAdmin && $expectedToken !== '' && hash_equals($expectedToken, $providedToken);
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $authorised) {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['confirmation'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 12) {
        $error = 'Use at least 12 characters for the password.';
    } elseif ($password !== $confirmation) {
        $error = 'The passwords do not match.';
    } else {
        $now = muskanNow();
        $statement = $database->prepare('INSERT INTO admins (email, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?)');
        $statement->execute([$email, password_hash($password, PASSWORD_DEFAULT), $now, $now]);
        @unlink($setupFile);
        header('Location: ./?setup=complete');
        exit;
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Set up Muskan Admin</title>
<style>:root{--navy:#092a3d;--teal:#0a847b;--paper:#f7f4ed;--line:#dbe3e4}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:var(--paper);color:#0d2232;font-family:system-ui,sans-serif}.card{width:min(460px,100%);padding:34px;border:1px solid var(--line);border-radius:22px;background:#fff;box-shadow:0 20px 60px rgba(1,28,42,.12)}h1{margin:0 0 8px;font-size:28px}p{color:#637481;line-height:1.55}.field{margin-top:17px}label{display:block;margin-bottom:6px;font-size:13px;font-weight:700}input{width:100%;padding:13px;border:1px solid var(--line);border-radius:11px;font:inherit}button{width:100%;margin-top:22px;padding:14px;border:0;border-radius:11px;background:var(--teal);color:#fff;font-weight:800}.error{padding:11px;border-radius:10px;background:#fff1ef;color:#a43124;font-size:13px}</style></head><body><main class="card">
<?php if ($hasAdmin): ?><h1>Setup is complete</h1><p>The Muskan admin account already exists.</p><a href="./">Go to admin login</a>
<?php elseif (!$authorised): ?><h1>Setup link unavailable</h1><p>This private setup link is invalid or has expired.</p>
<?php else: ?><h1>Create the Muskan admin</h1><p>Choose the email and password staff will use to manage flight requests.</p><?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="token" value="<?= htmlspecialchars($providedToken) ?>"><div class="field"><label for="email">Admin email</label><input id="email" name="email" type="email" value="Travelmuskan@gmail.com" autocomplete="username" required></div><div class="field"><label for="password">Password</label><input id="password" name="password" type="password" minlength="12" autocomplete="new-password" required></div><div class="field"><label for="confirmation">Confirm password</label><input id="confirmation" name="confirmation" type="password" minlength="12" autocomplete="new-password" required></div><button type="submit">Create secure admin</button></form>
<?php endif; ?></main></body></html>

