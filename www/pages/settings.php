<?php
require_once __DIR__ . '/../includes/tm.php';
require_once __DIR__ . '/../includes/layout.php';
tm_require_admin();

$db  = tm_db();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tm_csrf_ok()) {
        $err = 'Session expired - please try again.';
    } else {
        // Clamped, because a 1-second poll would hammer the routers and a 1-day
        // retention would silently throw away the history the charts read.
        $vals = [
            'site_name'          => substr(trim((string)$_POST['site_name']), 0, 60),
            'poll_seconds'       => (string)max(10, min(600, (int)$_POST['poll_seconds'])),
            'raw_retain_days'      => (string)max(1, min(60, (int)$_POST['raw_retain_days'])),
            'user_raw_retain_days' => (string)max(1, min(30, (int)$_POST['user_raw_retain_days'])),
            'hourly_retain_days' => (string)max(30, min(1095, (int)$_POST['hourly_retain_days'])),
        ];
        if ($vals['site_name'] === '') $vals['site_name'] = 'Traffic Monitor';
        $st = $db->prepare("INSERT INTO tm_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
        foreach ($vals as $k => $v) $st->execute([$k, $v]);
        $msg = 'Settings saved. The collector picks up a new poll interval on its next round.';
    }
}

// Read straight from the table: tm_setting() cached the old values before the save.
$cur = [];
foreach ($db->query("SELECT k, v FROM tm_settings")->fetchAll() as $r) $cur[$r['k']] = $r['v'];
$g = function ($k, $d) use ($cur) { return isset($cur[$k]) && $cur[$k] !== '' ? $cur[$k] : $d; };

$sizes = $db->query(
    "SELECT table_name, table_rows, ROUND((data_length + index_length)/1048576, 1) mb
       FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name LIKE 'tm\\_%'
      ORDER BY (data_length + index_length) DESC"
)->fetchAll();

$svc = trim((string)@shell_exec('systemctl is-active tm-collect 2>/dev/null'));

tm_header('Settings', 'settings');
?>
<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="card-head"><div><h3>Collector</h3>
    <div class="sub">The background service that reads the routers.</div></div></div>
  <?php if ($svc === 'active'): ?>
    <span class="badge green">running</span>
  <?php elseif ($svc === ''): ?>
    <span class="badge grey">status unavailable</span>
    <span class="dim" style="margin-left:10px">systemctl could not be queried from PHP.</span>
  <?php else: ?>
    <span class="badge red"><?= h($svc) ?></span>
    <span class="dim" style="margin-left:10px">Start it with <code>systemctl start tm-collect</code>.</span>
  <?php endif; ?>
</div>

<div class="card" style="max-width:620px">
  <div class="card-head"><div><h3>Options</h3></div></div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(tm_csrf()) ?>">
    <div class="form-group">
      <label>Panel name</label>
      <input type="text" name="site_name" value="<?= h($g('site_name', 'Traffic Monitor')) ?>" maxlength="60">
    </div>
    <div class="form-group">
      <label>Poll interval (seconds) - how often each router is read</label>
      <input type="number" name="poll_seconds" min="10" max="600" value="<?= h($g('poll_seconds', '30')) ?>">
    </div>
    <div class="form-group">
      <label>Keep detailed interface samples for (days)</label>
      <input type="number" name="raw_retain_days" min="1" max="60" value="<?= h($g('raw_retain_days', '7')) ?>">
    </div>
    <div class="form-group">
      <label>Keep detailed per-user samples for (days)</label>
      <input type="number" name="user_raw_retain_days" min="1" max="30" value="<?= h($g('user_raw_retain_days', '2')) ?>">
      <div class="sub" style="margin-top:6px">One row per online device per poll, so this grows far
        faster than the interface samples. The usage reports read the daily totals, which are kept
        for the full period below - raising this only adds intraday detail.</div>
    </div>
    <div class="form-group">
      <label>Keep hourly and daily history for (days)</label>
      <input type="number" name="hourly_retain_days" min="30" max="1095" value="<?= h($g('hourly_retain_days', '400')) ?>">
    </div>
    <button class="btn btn-primary" type="submit">Save settings</button>
  </form>
</div>

<div class="card">
  <div class="card-head"><div><h3>Storage</h3>
    <div class="sub">Only these tables belong to the monitor. Detailed samples are deleted automatically once they pass the retention above; the hourly and daily totals stay, so long-range charts keep working.</div></div></div>
  <table>
    <thead><tr><th>Table</th><th class="num">Rows (approx)</th><th class="num">Size</th></tr></thead>
    <tbody>
    <?php $tot = 0; foreach ($sizes as $s): $tot += (float)$s['mb']; ?>
      <tr><td class="dim"><?= h($s['table_name']) ?></td>
        <td class="num"><?= number_format((int)$s['table_rows']) ?></td>
        <td class="num"><?= h($s['mb']) ?> MB</td></tr>
    <?php endforeach; ?>
      <tr><td><b>Total</b></td><td></td><td class="num"><b><?= number_format($tot, 1) ?> MB</b></td></tr>
    </tbody>
  </table>
</div>
<?php tm_footer(); ?>
