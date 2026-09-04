<?php
/** Traffic Monitor - formatting helpers and every database read the pages use. */

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

/** Link speeds are quoted in bits per second, so 1 k = 1000 (not 1024). */
function tm_fmt_bps($bps) {
    $bps = (float)$bps;
    if ($bps >= 1e9) return round($bps / 1e9, 2) . ' Gbps';
    if ($bps >= 1e6) return round($bps / 1e6, 2) . ' Mbps';
    if ($bps >= 1e3) return round($bps / 1e3, 1) . ' kbps';
    return (int)$bps . ' bps';
}

/** Volumes are quoted the way an ISP invoice does, in binary units. */
function tm_fmt_bytes($b) {
    $b = (float)$b;
    if ($b >= 1099511627776) return round($b / 1099511627776, 2) . ' TB';
    if ($b >= 1073741824)    return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576)       return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024)          return round($b / 1024, 1) . ' KB';
    return (int)$b . ' B';
}

function tm_ago($ts) {
    if (!$ts) return 'never';
    $s = time() - strtotime($ts);
    if ($s < 0)    return 'just now';
    if ($s < 60)   return $s . 's ago';
    if ($s < 3600) return floor($s / 60) . 'm ago';
    if ($s < 86400) return floor($s / 3600) . 'h ago';
    return floor($s / 86400) . 'd ago';
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

/** Every router with the result of the last poll attached. */
function tm_routers($db) {
    return $db->query(
        "SELECT r.id, r.name, r.ip_address, r.api_port, r.location, r.is_active,
                p.ok, p.error, p.ms, p.last_try, p.last_ok
           FROM routers r
           LEFT JOIN tm_poll p ON p.router_id = r.id
          ORDER BY r.is_active DESC, r.name"
    )->fetchAll();
}

/**
 * Interfaces to chart. $all=false returns only the watched ones, which is what the
 * dashboard shows; the Interfaces page passes true so nothing is hidden there.
 */
function tm_ifaces($db, $all = false, $routerId = 0) {
    $sql = "SELECT i.*, r.name AS router_name, r.is_active AS router_active
              FROM tm_iface i
              JOIN routers r ON r.id = i.router_id
             WHERE 1=1";
    $args = [];
    if (!$all)      { $sql .= " AND i.watched=1 AND r.is_active=1"; }
    if ($routerId)  { $sql .= " AND i.router_id=?"; $args[] = $routerId; }
    $sql .= " ORDER BY r.name, i.name";
    $st = $db->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function tm_iface($db, $id) {
    $st = $db->prepare(
        "SELECT i.*, r.name AS router_name FROM tm_iface i
           JOIN routers r ON r.id = i.router_id WHERE i.id=?"
    );
    $st->execute([(int)$id]);
    return $st->fetch();
}

/**
 * Points for one interface's chart.
 *
 * Up to 48 hours we read the raw 30-second samples. Past that the raw table has
 * already been purged, so the series comes from the hourly roll-up and each point
 * is the average rate over that hour (bytes in the bucket / 3600).
 * Returns ['points'=>[[unix_ts, rx_bps, tx_bps], ...], 'source'=>'raw'|'hourly'].
 */
function tm_iface_series($db, $ifaceId, $hours) {
    $hours = max(1, (int)$hours);
    if ($hours <= 48) {
        // $hours is already an int cast above; MariaDB will not take a bound
        // parameter inside INTERVAL, so it is interpolated rather than bound.
        $st = $db->prepare(
            "SELECT UNIX_TIMESTAMP(sampled_at) t, rx_bps, tx_bps
               FROM tm_iface_sample
              WHERE iface_id=? AND sampled_at >= NOW() - INTERVAL $hours HOUR
              ORDER BY sampled_at"
        );
        $st->execute([(int)$ifaceId]);
        $pts = [];
        foreach ($st->fetchAll() as $r) $pts[] = [(int)$r['t'], (int)$r['rx_bps'], (int)$r['tx_bps']];
        if ($pts) return ['points' => $pts, 'source' => 'raw'];
    }
    $st = $db->prepare(
        "SELECT UNIX_TIMESTAMP(hour_at) t, rx_bytes, tx_bytes, peak_rx_bps, peak_tx_bps
           FROM tm_iface_hourly
          WHERE iface_id=? AND hour_at >= NOW() - INTERVAL $hours HOUR
          ORDER BY hour_at"
    );
    $st->execute([(int)$ifaceId]);
    $pts = [];
    foreach ($st->fetchAll() as $r) {
        $pts[] = [(int)$r['t'], (int)round($r['rx_bytes'] * 8 / 3600), (int)round($r['tx_bytes'] * 8 / 3600)];
    }
    return ['points' => $pts, 'source' => 'hourly'];
}

/**
 * Which interfaces the headline totals are allowed to add up.
 *
 * A MikroTik counts the same packet on the bridge, on the member port and on the
 * WAN port, so summing every watched interface reports several times the real
 * throughput. When at least one interface is flagged as facing the internet the
 * totals use only those; otherwise there is nothing better to go on and every
 * watched interface is counted, which the dashboard says out loud.
 */
function tm_total_scope($db) {
    static $wan = null;
    if ($wan === null) {
        $wan = (int)$db->query(
            "SELECT COUNT(*) FROM tm_iface i JOIN routers r ON r.id=i.router_id
              WHERE i.watched=1 AND i.is_wan=1 AND r.is_active=1"
        )->fetchColumn() > 0;
    }
    return $wan ? 'i.watched=1 AND i.is_wan=1' : 'i.watched=1';
}

function tm_totals_are_wan_only($db) {
    return strpos(tm_total_scope($db), 'is_wan') !== false;
}

/**
 * The interfaces behind the headline totals, added together, for the overview chart.
 * Samples taken in the same poll share a timestamp, so grouping by sampled_at
 * lines the routers up without any interpolation.
 */
function tm_total_series($db, $hours) {
    $hours = max(1, (int)$hours);
    $scope = tm_total_scope($db);
    if ($hours <= 48) {
        $rows = $db->query(
            "SELECT UNIX_TIMESTAMP(s.sampled_at) t, SUM(s.rx_bps) rx, SUM(s.tx_bps) tx
               FROM tm_iface_sample s JOIN tm_iface i ON i.id = s.iface_id
              WHERE $scope AND s.sampled_at >= NOW() - INTERVAL $hours HOUR
              GROUP BY s.sampled_at ORDER BY s.sampled_at"
        )->fetchAll();
        if ($rows) {
            $pts = [];
            foreach ($rows as $r) $pts[] = [(int)$r['t'], (int)$r['rx'], (int)$r['tx']];
            return ['points' => $pts, 'source' => 'raw'];
        }
    }
    $rows = $db->query(
        "SELECT UNIX_TIMESTAMP(h.hour_at) t, SUM(h.rx_bytes) rx, SUM(h.tx_bytes) tx
           FROM tm_iface_hourly h JOIN tm_iface i ON i.id = h.iface_id
          WHERE $scope AND h.hour_at >= NOW() - INTERVAL $hours HOUR
          GROUP BY h.hour_at ORDER BY h.hour_at"
    )->fetchAll();
    $pts = [];
    foreach ($rows as $r) {
        $pts[] = [(int)$r['t'], (int)round($r['rx'] * 8 / 3600), (int)round($r['tx'] * 8 / 3600)];
    }
    return ['points' => $pts, 'source' => 'hourly'];
}

/** Total bytes an interface moved over the last N hours, from the roll-up. */
function tm_iface_volume($db, $ifaceId, $hours) {
    $hours = max(1, (int)$hours);
    $st = $db->prepare(
        "SELECT COALESCE(SUM(rx_bytes),0) rx, COALESCE(SUM(tx_bytes),0) tx,
                COALESCE(MAX(peak_rx_bps),0) prx, COALESCE(MAX(peak_tx_bps),0) ptx
           FROM tm_iface_hourly WHERE iface_id=? AND hour_at >= NOW() - INTERVAL $hours HOUR"
    );
    $st->execute([(int)$ifaceId]);
    return $st->fetch();
}

/** Devices currently online, newest reading first. */
function tm_users_live($db, $limit = 300) {
    $st = $db->prepare(
        "SELECT u.*, r.name AS router_name
           FROM tm_user_live u
           JOIN routers r ON r.id = u.router_id
          WHERE u.last_at >= NOW() - INTERVAL 5 MINUTE
          ORDER BY (u.in_bps + u.out_bps) DESC, u.username
          LIMIT " . (int)$limit
    );
    $st->execute();
    return $st->fetchAll();
}

/** Heaviest users over a window, from the daily roll-up (so it survives purges). */
function tm_top_users($db, $days = 1, $limit = 50) {
    $back = max(0, (int)$days - 1);   // days=1 means today only
    return $db->query(
        "SELECT d.username, r.name AS router_name,
                SUM(d.in_bytes) up, SUM(d.out_bytes) down,
                SUM(d.in_bytes + d.out_bytes) total
           FROM tm_user_daily d
           JOIN routers r ON r.id = d.router_id
          WHERE d.day_at >= CURDATE() - INTERVAL $back DAY AND d.username <> ''
          GROUP BY d.username, r.name
          ORDER BY total DESC
          LIMIT " . (int)$limit
    )->fetchAll();
}

/** Daily totals for one voucher, for the per-user chart. */
function tm_user_days($db, $username, $days = 30) {
    $days = max(1, (int)$days);
    $st = $db->prepare(
        "SELECT day_at, SUM(in_bytes) up, SUM(out_bytes) down
           FROM tm_user_daily
          WHERE username=? AND day_at >= CURDATE() - INTERVAL $days DAY
          GROUP BY day_at ORDER BY day_at"
    );
    $st->execute([$username]);
    return $st->fetchAll();
}

/** Numbers for the dashboard tiles. */
function tm_summary($db) {
    $s = [];
    $s['routers_total']  = (int)$db->query("SELECT COUNT(*) FROM routers WHERE is_active=1")->fetchColumn();
    $s['routers_up']     = (int)$db->query("SELECT COUNT(*) FROM tm_poll p JOIN routers r ON r.id=p.router_id
                                             WHERE r.is_active=1 AND p.ok=1 AND p.last_try >= NOW() - INTERVAL 5 MINUTE")->fetchColumn();
    $s['online']         = (int)$db->query("SELECT COUNT(*) FROM tm_user_live WHERE last_at >= NOW() - INTERVAL 5 MINUTE")->fetchColumn();
    $scope = tm_total_scope($db);
    $row = $db->query("SELECT COALESCE(SUM(i.rx_bps),0) rx, COALESCE(SUM(i.tx_bps),0) tx
                         FROM tm_iface i JOIN routers r ON r.id=i.router_id
                        WHERE $scope AND r.is_active=1 AND i.seen_at >= NOW() - INTERVAL 5 MINUTE")->fetch();
    $s['rx_bps'] = (int)$row['rx'];
    $s['tx_bps'] = (int)$row['tx'];
    $s['counted'] = (int)$db->query("SELECT COUNT(*) FROM tm_iface i JOIN routers r ON r.id=i.router_id
                                      WHERE $scope AND r.is_active=1")->fetchColumn();
    $row = $db->query("SELECT COALESCE(SUM(h.rx_bytes),0) rx, COALESCE(SUM(h.tx_bytes),0) tx
                         FROM tm_iface_hourly h JOIN tm_iface i ON i.id=h.iface_id
                        WHERE $scope AND h.hour_at >= CURDATE()")->fetch();
    $s['today_rx'] = (int)$row['rx'];
    $s['today_tx'] = (int)$row['tx'];
    $s['last_poll'] = $db->query("SELECT MAX(last_try) FROM tm_poll")->fetchColumn();
    return $s;
}
