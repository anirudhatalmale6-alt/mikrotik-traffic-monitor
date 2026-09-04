<?php
require_once __DIR__ . '/../includes/tm.php';
require_once __DIR__ . '/../includes/layout.php';
tm_auth();

$db   = tm_db();
$user = trim((string)($_GET['u'] ?? ''));
$days = isset($_GET['d']) ? max(1, min(365, (int)$_GET['d'])) : 1;

// ---------------------------------------------------------------- one voucher
if ($user !== '') {
    $rows = tm_user_days($db, $user, 30);
    $pts  = [];
    $upT  = $downT = 0;
    foreach ($rows as $r) {
        $pts[] = [strtotime($r['day_at'] . ' 12:00:00'), (int)$r['down'], (int)$r['up']];
        $downT += (int)$r['down'];
        $upT   += (int)$r['up'];
    }
    $st = $db->prepare("SELECT u.*, r.name AS router_name FROM tm_user_live u
                          JOIN routers r ON r.id=u.router_id
                         WHERE u.username=? ORDER BY u.last_at DESC");
    $st->execute([$user]);
    $sessions = $st->fetchAll();

    tm_header('Usage - ' . $user, 'users');
    ?>
    <div class="stats">
      <div class="stat-card"><div class="label">Downloaded (30 days)</div>
        <div class="value green"><?= h(tm_fmt_bytes($downT)) ?></div></div>
      <div class="stat-card"><div class="label">Uploaded (30 days)</div>
        <div class="value blue"><?= h(tm_fmt_bytes($upT)) ?></div></div>
      <div class="stat-card"><div class="label">Total</div>
        <div class="value"><?= h(tm_fmt_bytes($downT + $upT)) ?></div>
        <div class="foot">over <?= count($rows) ?> active day<?= count($rows) === 1 ? '' : 's' ?></div></div>
    </div>

    <div class="card">
      <div class="card-head"><div><h3>Daily usage</h3>
        <div class="sub">Each point is one day's total for voucher <b><?= h($user) ?></b>.</div></div></div>
      <div id="u-chart"></div>
      <div class="legend" style="margin-top:10px">
        <span><i style="background:#34d399"></i>Download</span>
        <span><i style="background:#60a5fa"></i>Upload</span>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><div><h3>Devices</h3>
        <div class="sub">Every device seen using this voucher.</div></div></div>
      <?php if (!$sessions): ?>
        <div class="sub">No device recorded for this voucher yet.</div>
      <?php else: ?>
      <table>
        <thead><tr><th>Router</th><th>MAC</th><th>IP</th><th>Uptime</th>
          <th class="num">Down now</th><th class="num">Up now</th><th>Last seen</th></tr></thead>
        <tbody>
        <?php foreach ($sessions as $s): ?>
          <tr>
            <td><?= h($s['router_name']) ?></td>
            <td class="dim"><?= h($s['mac']) ?></td>
            <td><?= h($s['ip']) ?></td>
            <td><?= h($s['uptime']) ?></td>
            <td class="num"><?= h(tm_fmt_bps($s['out_bps'])) ?></td>
            <td class="num"><?= h(tm_fmt_bps($s['in_bps'])) ?></td>
            <td class="dim"><?= h(tm_ago($s['last_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <div style="margin-bottom:24px"><a class="btn btn-ghost btn-sm" href="/pages/users.php">&larr; All users</a></div>
    <?php ob_start(); ?>
    <script>
    TM.chart('u-chart', { points: <?= json_encode($pts) ?>, height: 250, format: 'bytes',
      labels: ['Download', 'Upload'], empty: 'No usage recorded for this voucher yet.' });
    </script>
    <?php tm_footer(ob_get_clean());
    exit;
}

// ----------------------------------------------------------------- all users
$live = tm_users_live($db);
$top  = tm_top_users($db, $days, 50);

tm_header('Users', 'users');
?>
<div class="card">
  <div class="card-head">
    <div><h3>Online now</h3><div class="sub"><?= count($live) ?> device(s) with a live hotspot session, busiest first.</div></div>
  </div>
  <?php if (!$live): ?>
    <div class="sub">Nobody is online, or the collector has not polled yet.</div>
  <?php else: ?>
  <table>
    <thead><tr><th>Voucher</th><th>Router</th><th>IP</th><th>MAC</th><th>Uptime</th>
      <th class="num">Download</th><th class="num">Upload</th><th class="num">Session total</th></tr></thead>
    <tbody>
    <?php foreach ($live as $u): ?>
      <tr>
        <td><a href="?u=<?= urlencode($u['username']) ?>"><b><?= h($u['username'] !== '' ? $u['username'] : $u['mac']) ?></b></a></td>
        <td><?= h($u['router_name']) ?></td>
        <td><?= h($u['ip']) ?></td>
        <td class="dim"><?= h($u['mac']) ?></td>
        <td><?= h($u['uptime']) ?></td>
        <td class="num" style="color:#6ee7b7"><?= h(tm_fmt_bps($u['out_bps'])) ?></td>
        <td class="num" style="color:#93c5fd"><?= h(tm_fmt_bps($u['in_bps'])) ?></td>
        <td class="num dim"><?= h(tm_fmt_bytes((int)$u['last_in'] + (int)$u['last_out'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-head">
    <div><h3>Heaviest users</h3>
      <div class="sub">Data actually moved in the window. A voucher that reconnects keeps its total, because the monitor adds up what it measures instead of trusting the router's session counter.</div></div>
    <div class="range">
      <?php foreach ([1 => 'Today', 7 => '7 days', 30 => '30 days', 90 => '90 days'] as $dv => $lbl): ?>
        <a class="<?= $days === $dv ? 'on' : '' ?>" href="?d=<?= $dv ?>"><?= h($lbl) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if (!$top): ?>
    <div class="sub">No usage recorded in this window yet.</div>
  <?php else: ?>
  <table>
    <thead><tr><th style="width:44px">#</th><th>Voucher</th><th>Router</th>
      <th class="num">Download</th><th class="num">Upload</th><th class="num">Total</th><th style="width:26%">Share</th></tr></thead>
    <tbody>
    <?php $max = max(1, (int)$top[0]['total']); $n = 0; foreach ($top as $t): $n++; ?>
      <tr>
        <td class="dim"><?= $n ?></td>
        <td><a href="?u=<?= urlencode($t['username']) ?>"><b><?= h($t['username']) ?></b></a></td>
        <td><?= h($t['router_name']) ?></td>
        <td class="num"><?= h(tm_fmt_bytes($t['down'])) ?></td>
        <td class="num"><?= h(tm_fmt_bytes($t['up'])) ?></td>
        <td class="num"><b><?= h(tm_fmt_bytes($t['total'])) ?></b></td>
        <td><div class="bar"><i style="width:<?= round((int)$t['total'] / $max * 100) ?>%"></i></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php tm_footer(); ?>
