<?php
require __DIR__ . '/auth.php';
$error = '';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/'; // works with or without trailing slash
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (password_verify($_POST['password'] ?? '', PAGE_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['smarthome_auth'] = true;
        remember_set(true);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit;
    }
    $error = 'Wrong password';
}
if (isset($_GET['logout'])) { session_destroy(); remember_set(false); header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex">
<base href="<?= htmlspecialchars($base) ?>">
<title>Smarthome</title>
<link rel="stylesheet" href="style.css">
<?php if ($loggedIn): ?><script src="app.js" defer></script><?php endif; ?>
</head>
<body>
<main>
<?php if (!$loggedIn): ?>
  <form class="login" method="post">
    <h1>Smarthome</h1>
    <label for="pw">Password</label>
    <input id="pw" type="password" name="password" autocomplete="current-password" required autofocus>
    <?php if ($error): ?><p class="err" role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <button type="submit">Sign in</button>
  </form>
<?php else: ?>
  <header>
    <div>
      <h1>Smarthome</h1>
      <p class="sub">Devices · <span id="updated">loading …</span></p>
    </div>
    <a class="logout" href="?logout=1">Sign out</a>
  </header>
  <nav class="tabs">
    <a href="#smarthome" aria-current="page">Smarthome</a>
    <a href="#snmp">SNMP</a>
  </nav>
  <section id="snmp" class="grid" aria-live="polite" hidden></section>
  <div id="home">
  <section class="total">
    <span class="label">Total power</span>
    <span class="big"><span id="total">–</span> <small>W</small></span>
  </section>
  <section id="plugs" class="grid" aria-live="polite"></section>
  <section class="usage">
    <h2>Energy usage</h2>
    <p id="empty" class="sub">No readings yet. The counter runs once a minute via cron.</p>
    <div class="scroll"><table id="months" hidden><thead></thead><tbody></tbody></table></div>
  </section>
  </div>
  <dialog id="confirm">
    <form method="dialog">
      <h2 id="confirm-title"></h2>
      <p id="confirm-text" class="sub"></p>
      <div class="actions">
        <button value="cancel" autofocus>Cancel</button>
        <button value="ok" id="confirm-ok" class="primary"></button>
      </div>
    </form>
  </dialog>
<?php endif; ?>
</main>
</body>
</html>
