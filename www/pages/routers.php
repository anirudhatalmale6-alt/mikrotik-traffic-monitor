<?php
require_once __DIR__ . '/../includes/tm.php';
require_once __DIR__ . '/../includes/layout.php';
tm_auth();

$db  = tm_db();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'poll_now') {
    if (!tm_is_admin())      $err = 'Only an administrator can trigger a poll.';
    elseif (!tm_csrf_ok())   $err = 'Session expired - please try again.';
    else {
        // Detached: an unreachable router costs 8s of connect timeout each, and the
        // page must not sit there spinning while that plays out.
        @exec('nohup timeout 120 php /opt/mt/tm_collect.php --once > /dev/null 2>&1 &');
        $msg = 'Poll started. Refresh in a few seconds to see the result.';
    }
}

$routers = tm_routers($db);
$counts  = [];
foreach ($db->query("SELECT router_id, COUNT(*) c, SUM(watched) w FROM tm_iface GROUP BY router_id")->fetchAll() as $r) {
    $counts[(int)$r['router_id']] = $r;
}
$pollSec = max(5, (int)tm_setting('poll_seconds', 30));

tm_header('Routers', 'routers');
?>
<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="card-head">
    <div><h3>Polling status</h3>
      <div class="sub">Routers come from the billing panel's NAS list. This page never changes them - it only shows whether the monitor can reach each one.</div></div>
    <?php if (tm_is_admin()): ?>
    <form method="post" style="margin:0">
      <input type="hidden" name="csrf" value="<?= h(tm_csrf()) ?>">
      <input type="hidden" name="action" value="poll_now">
      <button class="btn btn-ghost btn-sm" type="submit">Poll now</button>
    </form>
    <?php endif; ?>
  </div>
  <table>
    <thead><tr><th>Router</th><th>Address</th><th>API port</th><th>Status</th>
      <th class="num">Response</th><th>Last success</th><th class="num">Interfaces</th></tr></thead>
    <tbody>
    <?php foreach ($routers as $r):
      $c = $counts[(int)$r['id']] ?? ['c' => 0, 'w' => 0];
      $fresh = $r['last_try'] && (time() - strtotime($r['last_try'])) < max(180, $pollSec * 4);
    ?>
      <tr>
        <td><b><?= h($r['name']) ?></b><?= $r['location'] ? ' <span class="dim">- ' . h($r['location']) . '</span>' : '' ?></td>
        <td class="dim"><?= h($r['ip_address']) ?></td>
        <td class="dim"><?= h($r['api_port']) ?></td>
        <td>
          <?php if (!(int)$r['is_active']): ?>
            <span class="badge grey">not active in panel</span>
          <?php elseif (!$r['last_try']): ?>
            <span class="badge grey">never polled</span>
          <?php elseif ((int)$r['ok'] && $fresh): ?>
            <span class="badge green">reachable</span>
          <?php elseif ((int)$r['ok']): ?>
            <span class="badge yellow" style="background:rgba(113,63,18,.5);color:#fde68a;border-color:rgba(234,179,8,.25)">stale</span>
          <?php else: ?>
            <span class="badge red">unreachable</span>
            <?php if ($r['error']): ?><div class="dim" style="font-size:12px;margin-top:4px"><?= h($r['error']) ?></div><?php endif; ?>
          <?php endif; ?>
        </td>
        <td class="num dim"><?= $r['ms'] !== null ? (int)$r['ms'] . ' ms' : '-' ?></td>
        <td class="dim"><?= h(tm_ago($r['last_ok'])) ?></td>
        <td class="num"><?= (int)$c['c'] ?> <span class="dim">(<?= (int)$c['w'] ?> recorded)</span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>If a router shows unreachable</h3>
  <div class="sub" style="margin-top:10px;line-height:1.7">
    The monitor uses the same API address, port, username and password as the billing panel,
    so a router that fails here fails there too. The usual causes are, in order:
    the API service is disabled on the router (<code>/ip service</code>),
    the port is not forwarded or the dynamic DNS name now points somewhere else,
    or the API user's password was changed on the router but not in the panel.
    Nothing on this page can fix those - they are settings on the router itself.
  </div>
</div>
<?php tm_footer(); ?>
