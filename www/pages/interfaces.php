<?php
require_once __DIR__ . '/../includes/tm.php';
require_once __DIR__ . '/../includes/layout.php';
tm_auth();

$db  = tm_db();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tm_is_admin()) {
        $err = 'Only an administrator can change which interfaces are recorded.';
    } elseif (!tm_csrf_ok()) {
        $err = 'Session expired - please try again.';
    } else {
        // Everything on the page is submitted, so an unticked box is a real "off"
        // rather than a missing key we would otherwise ignore.
        $shown = array_map('intval', (array)($_POST['shown'] ?? []));
        $want  = array_map('intval', (array)($_POST['watched'] ?? []));
        $wan   = array_map('intval', (array)($_POST['is_wan'] ?? []));
        $on = $off = $wanOn = 0;
        $st = $db->prepare("UPDATE tm_iface SET watched=?, is_wan=? WHERE id=?");
        foreach ($shown as $id) {
            $v = in_array($id, $want, true) ? 1 : 0;
            // An interface that is not recorded cannot be an internet link either -
            // it would be counted in the headline total with no history behind it.
            $w = ($v && in_array($id, $wan, true)) ? 1 : 0;
            $st->execute([$v, $w, $id]);
            $v ? $on++ : $off++;
            $wanOn += $w;
        }
        $msg = 'Saved. ' . $on . ' interface(s) recorded, ' . $off . ' ignored, '
             . $wanOn . ' marked as internet link' . ($wanOn === 1 ? '' : 's') . '.';
    }
}

$all = tm_ifaces($db, true);
$byRouter = [];
foreach ($all as $i) $byRouter[$i['router_name']][] = $i;

tm_header('Interfaces', 'ifaces');
?>
<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

<?php if (!$all): ?>
  <div class="card">
    <h3>No interfaces discovered yet</h3>
    <div class="sub" style="margin-top:8px">
      The collector fills this list on its first successful poll. If it stays empty,
      check that the routers are reachable on the <a href="/pages/routers.php">Routers</a> page.
    </div>
  </div>
<?php else: ?>
<form method="post">
  <input type="hidden" name="csrf" value="<?= h(tm_csrf()) ?>">
  <?php foreach ($byRouter as $router => $list): ?>
  <div class="card">
    <div class="card-head">
      <div>
        <h3><?= h($router) ?></h3>
        <div class="sub">
          <b>Record</b> keeps the history for that link. <b>Internet</b> marks the port that
          faces your provider - only those are added into the dashboard total, so a bridge and
          the ports inside it are never counted twice. It was guessed from the router's own
          default route; correct it here if the guess is wrong.
        </div>
      </div>
    </div>
    <table>
      <thead><tr>
        <th style="width:66px">Record</th><th style="width:74px">Internet</th><th>Interface</th><th>Type</th><th>Status</th>
        <th class="num">RX now</th><th class="num">TX now</th><th>Last seen</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($list as $i): ?>
        <tr>
          <td>
            <input type="hidden" name="shown[]" value="<?= (int)$i['id'] ?>">
            <input type="checkbox" name="watched[]" value="<?= (int)$i['id'] ?>"
                   <?= (int)$i['watched'] ? 'checked' : '' ?> <?= tm_is_admin() ? '' : 'disabled' ?>
                   style="width:17px;height:17px;accent-color:#6366f1">
          </td>
          <td>
            <input type="checkbox" name="is_wan[]" value="<?= (int)$i['id'] ?>"
                   <?= (int)$i['is_wan'] ? 'checked' : '' ?> <?= tm_is_admin() ? '' : 'disabled' ?>
                   style="width:17px;height:17px;accent-color:#34d399">
          </td>
          <td><b><?= h($i['name']) ?></b><?= $i['comment'] ? ' <span class="dim">- ' . h($i['comment']) . '</span>' : '' ?></td>
          <td class="dim"><?= h($i['type']) ?></td>
          <td>
            <?php if ((int)$i['disabled']): ?><span class="badge grey">disabled</span>
            <?php elseif ((int)$i['running']): ?><span class="badge green">up</span>
            <?php else: ?><span class="badge red">down</span><?php endif; ?>
          </td>
          <td class="num"><?= h(tm_fmt_bps($i['rx_bps'])) ?></td>
          <td class="num"><?= h(tm_fmt_bps($i['tx_bps'])) ?></td>
          <td class="dim"><?= h(tm_ago($i['seen_at'])) ?></td>
          <td class="num"><a class="btn btn-ghost btn-sm" href="/pages/interface.php?id=<?= (int)$i['id'] ?>">History</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endforeach; ?>
  <?php if (tm_is_admin()): ?>
    <button class="btn btn-primary" type="submit">Save selection</button>
  <?php endif; ?>
</form>
<?php endif; ?>
<?php tm_footer(); ?>
