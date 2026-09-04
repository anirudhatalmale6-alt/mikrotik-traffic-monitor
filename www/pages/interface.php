<?php
require_once __DIR__ . '/../includes/tm.php';
require_once __DIR__ . '/../includes/layout.php';
tm_auth();

$db = tm_db();
$id = (int)($_GET['id'] ?? 0);
$if = tm_iface($db, $id);
if (!$if) {
    tm_header('Interface', 'ifaces');
    echo '<div class="alert alert-error">That interface no longer exists.</div>';
    tm_footer();
    exit;
}

$hours  = isset($_GET['h']) ? max(1, min(8760, (int)$_GET['h'])) : 24;
$series = tm_iface_series($db, $id, $hours);
$vol    = tm_iface_volume($db, $id, $hours);

tm_header($if['router_name'] . ' - ' . $if['name'], 'ifaces');
?>
<div class="stats">
  <div class="stat-card">
    <div class="label">RX now</div>
    <div class="value green"><?= h(tm_fmt_bps($if['rx_bps'])) ?></div>
    <div class="foot">reading from <?= h(tm_ago($if['last_at'])) ?></div>
  </div>
  <div class="stat-card">
    <div class="label">TX now</div>
    <div class="value blue"><?= h(tm_fmt_bps($if['tx_bps'])) ?></div>
    <div class="foot"><?= (int)$if['running'] ? 'link up' : 'link down' ?></div>
  </div>
  <div class="stat-card">
    <div class="label">Volume in window</div>
    <div class="value"><?= h(tm_fmt_bytes($vol['rx'] + $vol['tx'])) ?></div>
    <div class="foot"><?= h(tm_fmt_bytes($vol['rx'])) ?> in / <?= h(tm_fmt_bytes($vol['tx'])) ?> out</div>
  </div>
  <div class="stat-card">
    <div class="label">Peak rate</div>
    <div class="value yellow"><?= h(tm_fmt_bps(max((int)$vol['prx'], (int)$vol['ptx']))) ?></div>
    <div class="foot">RX <?= h(tm_fmt_bps($vol['prx'])) ?> / TX <?= h(tm_fmt_bps($vol['ptx'])) ?></div>
  </div>
</div>

<div class="card">
  <div class="card-head">
    <div>
      <h3><?= h($if['name']) ?> on <?= h($if['router_name']) ?></h3>
      <div class="sub">
        <?php if (!(int)$if['watched']): ?>
          <span class="badge yellow" style="background:rgba(113,63,18,.5);color:#fde68a;border-color:rgba(234,179,8,.25)">not recorded</span>
          This interface is not ticked on the Interfaces page, so no history is being stored for it.
        <?php elseif ($series['source'] === 'hourly'): ?>
          Hourly averages - the 30-second detail is kept for <?= (int)tm_setting('raw_retain_days', 7) ?> days.
        <?php else: ?>
          Every <?= (int)tm_setting('poll_seconds', 30) ?> seconds.
        <?php endif; ?>
      </div>
    </div>
    <div class="range">
      <?php foreach ([1 => '1h', 6 => '6h', 24 => '24h', 168 => '7d', 720 => '30d', 2160 => '90d'] as $hv => $lbl): ?>
        <a class="<?= $hours === $hv ? 'on' : '' ?>" href="?id=<?= $id ?>&amp;h=<?= $hv ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div id="if-chart"></div>
  <div class="legend" style="margin-top:10px">
    <span><i style="background:#34d399"></i>RX (into the router)</span>
    <span><i style="background:#60a5fa"></i>TX (out of the router)</span>
  </div>
</div>

<div style="margin-bottom:24px">
  <a class="btn btn-ghost btn-sm" href="/pages/interfaces.php">&larr; All interfaces</a>
</div>

<?php ob_start(); ?>
<script>
TM.chart('if-chart', {
  points: <?= json_encode($series['points']) ?>,
  height: 280,
  labels: ['RX', 'TX'],
  empty: 'No samples stored for this period.'
});
</script>
<?php tm_footer(ob_get_clean()); ?>
