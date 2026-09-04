<?php
require_once __DIR__ . '/includes/tm.php';
require_once __DIR__ . '/includes/layout.php';
tm_auth();

$db      = tm_db();
$sum     = tm_summary($db);
$ifaces  = tm_ifaces($db);
$hours   = isset($_GET['h']) ? max(1, min(720, (int)$_GET['h'])) : 3;
$series  = tm_total_series($db, $hours);
$pollSec = max(5, (int)tm_setting('poll_seconds', 30));
$wanOnly = tm_totals_are_wan_only($db);

// If the collector has stopped, every number on this page is frozen history.
// Say so loudly rather than letting a stale chart look like real live traffic.
$stale = !$sum['last_poll'] || (time() - strtotime($sum['last_poll'])) > max(180, $pollSec * 4);

tm_header('Live Traffic', 'live');
?>
<?php if ($stale): ?>
  <div class="alert alert-warn">
    The collector has not polled since <?= $sum['last_poll'] ? h($sum['last_poll']) . ' (' . h(tm_ago($sum['last_poll'])) . ')' : 'never' ?>.
    The figures below are the last readings, not live. On the server:
    <code>systemctl status tm-collect</code>
  </div>
<?php endif; ?>

<div class="stats">
  <div class="stat-card">
    <div class="label">Download now (RX)</div>
    <div class="value green" id="s-rx"><?= h(tm_fmt_bps($sum['rx_bps'])) ?></div>
    <div class="foot"><?= (int)$sum['counted'] ?> <?= $wanOnly ? 'internet link' : 'watched interface' ?><?= (int)$sum['counted'] === 1 ? '' : 's' ?></div>
  </div>
  <div class="stat-card">
    <div class="label">Upload now (TX)</div>
    <div class="value blue" id="s-tx"><?= h(tm_fmt_bps($sum['tx_bps'])) ?></div>
    <div class="foot">updated every <?= (int)$pollSec ?>s</div>
  </div>
  <div class="stat-card">
    <div class="label">Users online</div>
    <div class="value" id="s-online"><?= (int)$sum['online'] ?></div>
    <div class="foot">hotspot sessions</div>
  </div>
  <div class="stat-card">
    <div class="label">Routers reachable</div>
    <div class="value <?= $sum['routers_up'] < $sum['routers_total'] ? 'yellow' : 'green' ?>" id="s-routers">
      <?= (int)$sum['routers_up'] ?>/<?= (int)$sum['routers_total'] ?>
    </div>
    <div class="foot">last poll <?= h(tm_ago($sum['last_poll'])) ?></div>
  </div>
  <div class="stat-card">
    <div class="label">Traffic today</div>
    <div class="value"><?= h(tm_fmt_bytes($sum['today_rx'] + $sum['today_tx'])) ?></div>
    <div class="foot"><?= h(tm_fmt_bytes($sum['today_rx'])) ?> in / <?= h(tm_fmt_bytes($sum['today_tx'])) ?> out</div>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <div>
      <h3>Total traffic</h3>
      <div class="sub">
        <?php if ($wanOnly): ?>
          Internet links only<?= $series['source'] === 'hourly' ? ', hourly averages' : '' ?> -
          the ports marked Internet on the Interfaces page. LAN ports are excluded so the
          same traffic is not counted twice.
        <?php else: ?>
          Every watched interface added together<?= $series['source'] === 'hourly' ? ' - hourly averages' : '' ?>.
          No interface is marked as the internet link yet, so a bridge and the ports inside it
          are both being counted and this total reads high. Mark the internet port on the
          <a href="/pages/interfaces.php">Interfaces</a> page to fix it.
        <?php endif; ?>
      </div>
    </div>
    <div class="range">
      <?php foreach ([1 => '1h', 3 => '3h', 12 => '12h', 24 => '24h', 168 => '7d', 720 => '30d'] as $hv => $lbl): ?>
        <a class="<?= $hours === $hv ? 'on' : '' ?>" href="?h=<?= $hv ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div id="total-chart"></div>
  <div class="legend" style="margin-top:10px">
    <span><i style="background:#34d399"></i>RX (into the router)</span>
    <span><i style="background:#60a5fa"></i>TX (out of the router)</span>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <div><h3>Interfaces</h3><div class="sub">Live rate per link. Click one for its full history.</div></div>
    <a class="btn btn-ghost btn-sm" href="/pages/interfaces.php">Manage</a>
  </div>
  <?php if (!$ifaces): ?>
    <div class="sub">No interfaces are being watched yet. Open <a href="/pages/interfaces.php">Interfaces</a> and tick the links you want to chart.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th>Router</th><th>Interface</th><th>Status</th>
      <th class="num">RX</th><th class="num">TX</th><th>Load</th><th></th>
    </tr></thead>
    <tbody id="iface-rows">
    <?php
    $peak = 1;
    foreach ($ifaces as $i) { $peak = max($peak, (int)$i['rx_bps'], (int)$i['tx_bps']); }
    foreach ($ifaces as $i):
        $tot = (int)$i['rx_bps'] + (int)$i['tx_bps'];
        $pct = min(100, round($tot / max(1, $peak * 2) * 100));
    ?>
      <tr data-iface="<?= (int)$i['id'] ?>">
        <td><?= h($i['router_name']) ?></td>
        <td><b><?= h($i['name']) ?></b><?= $i['comment'] ? ' <span class="dim">- ' . h($i['comment']) . '</span>' : '' ?></td>
        <td>
          <?php if ((int)$i['disabled']): ?><span class="badge grey">disabled</span>
          <?php elseif ((int)$i['running']): ?><span class="badge green">up</span>
          <?php else: ?><span class="badge red">down</span><?php endif; ?>
        </td>
        <td class="num c-rx"><?= h(tm_fmt_bps($i['rx_bps'])) ?></td>
        <td class="num c-tx"><?= h(tm_fmt_bps($i['tx_bps'])) ?></td>
        <td style="min-width:120px"><div class="bar"><i style="width:<?= $pct ?>%"></i></div></td>
        <td class="num"><a class="btn btn-ghost btn-sm" href="/pages/interface.php?id=<?= (int)$i['id'] ?>">History</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php
// Buffered so it can be emitted after tm.js is loaded by the footer.
ob_start(); ?>
<script>
TM.chart('total-chart', {
  points: <?= json_encode($series['points']) ?>,
  height: 230,
  labels: ['RX', 'TX'],
  empty: 'Nothing collected yet - the first chart appears one poll after the collector starts.'
});

// Refresh the live numbers in place so the page does not jump while you read it.
TM.poll('/api/live.php', <?= (int)$pollSec * 1000 ?>, function (d) {
  if (!d || !d.ok) return;
  document.getElementById('s-rx').textContent = TM.fmtBps(d.rx_bps);
  document.getElementById('s-tx').textContent = TM.fmtBps(d.tx_bps);
  document.getElementById('s-online').textContent = d.online;
  document.getElementById('s-routers').textContent = d.routers_up + '/' + d.routers_total;
  var rows = document.querySelectorAll('#iface-rows tr');
  for (var i = 0; i < rows.length; i++) {
    var id = rows[i].getAttribute('data-iface');
    var v = d.ifaces[id];
    if (!v) continue;
    rows[i].querySelector('.c-rx').textContent = TM.fmtBps(v[0]);
    rows[i].querySelector('.c-tx').textContent = TM.fmtBps(v[1]);
  }
});
</script>
<?php tm_footer(ob_get_clean()); ?>
