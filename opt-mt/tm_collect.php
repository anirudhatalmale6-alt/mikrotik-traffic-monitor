<?php
/**
 * MikroTik Traffic Monitor - collector.
 *
 * Runs mt_traffic.py against every active router, turns the routers' cumulative
 * byte counters into per-second rates, and stores both the raw sample and the
 * roll-up buckets.
 *
 * Usage:
 *   php /opt/mt/tm_collect.php --once      one poll, then exit (cron / manual test)
 *   php /opt/mt/tm_collect.php --loop      poll forever (systemd service)
 *   php /opt/mt/tm_collect.php --once -v   print what it found
 *   php /opt/mt/tm_collect.php --seed-wan -v
 *                                          re-detect which interface is each router's
 *                                          internet link (overwrites the UI ticks)
 *
 * It only ever writes to tm_* tables. The billing panel's tables are read-only here.
 */

require_once __DIR__ . '/tm_config.php';

$opts    = $argv ?? [];
$verbose = in_array('-v', $opts, true) || in_array('--verbose', $opts, true);
$loop    = in_array('--loop', $opts, true);

function tm_log($msg) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' ' . $msg . "\n");
}

function tm_say($msg) {
    global $verbose;
    if ($verbose) echo $msg . "\n";
}

/** Ask every active router for its interface counters and hotspot sessions. */
function tm_probe($db) {
    $routers = $db->query(
        "SELECT id, name, ip_address AS host, api_port AS port, api_user AS user, api_pass AS pass
           FROM routers WHERE is_active=1 ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    if (!$routers) return [];

    $payload = json_encode(['routers' => $routers, 'hotspot' => true]);
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    // The timeout has to cover every router in series: 8s connect each, plus reads.
    $proc = @proc_open('timeout 90 python3 ' . TM_PY, $desc, $pipes);
    if (!is_resource($proc)) { tm_log('cannot start mt_traffic.py'); return []; }
    fwrite($pipes[0], $payload); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    proc_close($proc);

    $res = json_decode($out, true);
    if (!is_array($res) || !isset($res['routers'])) {
        tm_log('mt_traffic.py gave no usable output' . ($err ? ': ' . trim($err) : ''));
        return [];
    }
    if (!empty($res['fatal'])) tm_log('collector: ' . $res['fatal']);
    return $res['routers'];
}

/**
 * Turn two counter readings into a rate.
 * Returns [bytes_moved, bits_per_second] or null when the pair cannot be trusted:
 *  - no previous reading yet (first time we see this interface)
 *  - too long since the last reading (collector was stopped) - a 6 hour gap
 *    charted as one interval would draw a fake flat line across the outage.
 *
 * A counter that went backwards means it restarted from zero. For an interface that
 * is a router reboot and the sample is dropped. For a hotspot user it is a brand new
 * session, and the bytes on the new counter are real traffic the customer just used,
 * so with $resetIsFresh they are counted instead of thrown away - otherwise every
 * reconnect would quietly vanish from the daily usage total.
 */
function tm_rate($prev, $now, $elapsed, $maxGap, $resetIsFresh = false) {
    if ($prev === null || $elapsed <= 0 || $elapsed > $maxGap) return null;
    if ($now < $prev) {
        if (!$resetIsFresh) return null;
        $bytes = $now;
    } else {
        $bytes = $now - $prev;
    }
    return [$bytes, (int)round($bytes * 8 / $elapsed)];
}

/**
 * Choose the ONE interface per router that represents its internet feed.
 *
 * The router's default route can point at several interfaces at once and they
 * overlap: on a PPPoE site the session (pppoe-out) rides on top of ether1, so
 * both carry a default route and both see the same bytes. A VPN back to head
 * office also installs one and is not an internet feed at all.
 *
 * Preference: the PPPoE/LTE session, then a physical port, and a tunnel only if
 * there is nothing else. Picking one and only one is what keeps the dashboard
 * total from reading double.
 */
function tm_pick_wan($ifaces) {
    $tunnels = ['l2tp-out', 'l2tp-in', 'pptp-out', 'pptp-in', 'sstp-out', 'sstp-in',
                'ovpn-out', 'ovpn-in', 'gre-tunnel', 'ipip-tunnel', 'eoip', 'wg'];
    $rank = function ($type) use ($tunnels) {
        $t = strtolower((string)$type);
        foreach ($tunnels as $x) if (strpos($t, $x) !== false) return 0;
        if (strpos($t, 'pppoe') !== false || $t === 'lte' || $t === 'wwan') return 3;
        if ($t === 'ether' || strpos($t, 'sfp') !== false) return 2;
        return 1;
    };
    $best = null; $bestRank = -1;
    foreach ($ifaces as $i) {
        if (empty($i['wan'])) continue;
        $r = $rank($i['type'] ?? '');
        if ($r > $bestRank) { $bestRank = $r; $best = $i['name']; }
    }
    return $best;
}

/** Re-run internet-link detection over interfaces that already exist. The normal
 *  poll only seeds is_wan when it first inserts a row, so this is the deliberate
 *  way to redo the guess - it overwrites whatever is ticked in the UI. */
function tm_seed_wan($db) {
    $routers = tm_probe($db);
    foreach ($routers as $r) {
        if (empty($r['ok'])) { tm_say($r['name'] . ': unreachable, skipped'); continue; }
        $pick = tm_pick_wan($r['ifaces']);
        $st = $db->prepare("UPDATE tm_iface SET is_wan=0 WHERE router_id=?");
        $st->execute([(int)$r['id']]);
        if ($pick === null) { tm_say($r['name'] . ': no default route found'); continue; }
        $st = $db->prepare("UPDATE tm_iface SET is_wan=1, watched=1 WHERE router_id=? AND name=?");
        $st->execute([(int)$r['id'], $pick]);
        tm_say($r['name'] . ': internet link = ' . $pick);
    }
}

/**
 * Only one poll may run at a time.
 *
 * The service polls on its own clock while the "Poll now" button can fire another
 * one at any moment. Two collectors reading the same counters would each compute a
 * delta from a baseline the other has already moved, and the charts would show
 * spikes that never happened on the wire. The lock is taken per poll rather than
 * for the life of the service, so a manual poll still works between rounds.
 */
function tm_lock($waitSeconds = 0) {
    // The lock file lives in /opt/mt, NOT in /var/lock or /tmp, for two reasons:
    //   - the service runs with PrivateTmp, so a /tmp lock would be a different file
    //     for the service and for a poll started from the shell, protecting nothing;
    //   - /run/lock is world-writable and sticky, and the kernel's fs.protected_regular
    //     then refuses to open a file owned by another user for writing - even for
    //     root. That failure is silent from PHP's side and the lock would quietly
    //     stop working, which is worse than having no lock at all.
    // It is created at install time owned by www-data so both users can open it.
    $fh = @fopen(TM_LOCK, 'c');
    if (!$fh) {
        // Never fail silently: a lock that cannot be taken has to be visible.
        tm_log('WARNING: cannot open ' . TM_LOCK . ' - running without a lock');
        return null;
    }
    $deadline = time() + $waitSeconds;
    $waited = false;
    do {
        if (flock($fh, LOCK_EX | LOCK_NB)) {
            if ($waited) tm_log('collector lock acquired after waiting');
            return $fh;
        }
        if (!$waited) { tm_log('collector lock is held by another poll - waiting'); $waited = true; }
        if (time() >= $deadline) break;
        usleep(300000);
    } while (true);
    fclose($fh);
    return false;                             // someone else is polling
}

function tm_unlock($fh) {
    if (is_resource($fh)) { flock($fh, LOCK_UN); fclose($fh); }
}

function tm_poll_once($db) {
    // Read fresh every poll, not from the cache: in --loop mode the process lives for
    // weeks and must pick up a poll interval changed from the Settings page.
    $pollSec = max(5, (int)tm_setting_raw($db, 'poll_seconds', 30));
    $maxGap  = $pollSec * 10;
    $now     = date('Y-m-d H:i:s');
    $hour    = date('Y-m-d H:00:00');
    $day     = date('Y-m-d');

    $routers = tm_probe($db);
    if (!$routers) { tm_say('no active routers'); return; }

    // watched and is_wan are set on INSERT only. Once the interface exists they
    // belong to whoever ticked the boxes on the Interfaces page, and a poll must
    // never quietly undo that choice.
    $upIface  = $db->prepare(
        "INSERT INTO tm_iface (router_id, name, type, comment, running, disabled, watched, is_wan, last_rx, last_tx, last_at, rx_bps, tx_bps, seen_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,0,0,?)
         ON DUPLICATE KEY UPDATE type=VALUES(type), comment=VALUES(comment),
             running=VALUES(running), disabled=VALUES(disabled), seen_at=VALUES(seen_at)");
    $getIface = $db->prepare("SELECT id, last_rx, last_tx, last_at, watched FROM tm_iface WHERE router_id=? AND name=?");
    $setIface = $db->prepare("UPDATE tm_iface SET last_rx=?, last_tx=?, last_at=?, rx_bps=?, tx_bps=? WHERE id=?");
    $insSample= $db->prepare("INSERT INTO tm_iface_sample (iface_id, sampled_at, rx_bps, tx_bps, rx_bytes, tx_bytes) VALUES (?,?,?,?,?,?)");
    $upHourly = $db->prepare(
        "INSERT INTO tm_iface_hourly (iface_id, hour_at, rx_bytes, tx_bytes, peak_rx_bps, peak_tx_bps, samples)
         VALUES (?,?,?,?,?,?,1)
         ON DUPLICATE KEY UPDATE rx_bytes=rx_bytes+VALUES(rx_bytes), tx_bytes=tx_bytes+VALUES(tx_bytes),
             peak_rx_bps=GREATEST(peak_rx_bps, VALUES(peak_rx_bps)),
             peak_tx_bps=GREATEST(peak_tx_bps, VALUES(peak_tx_bps)), samples=samples+1");

    $getUser  = $db->prepare("SELECT last_in, last_out, last_at FROM tm_user_live WHERE router_id=? AND ukey=?");
    $upUser   = $db->prepare(
        "INSERT INTO tm_user_live (router_id, ukey, username, mac, ip, uptime, last_in, last_out, in_bps, out_bps, last_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE username=VALUES(username), mac=VALUES(mac), ip=VALUES(ip), uptime=VALUES(uptime),
             last_in=VALUES(last_in), last_out=VALUES(last_out), in_bps=VALUES(in_bps),
             out_bps=VALUES(out_bps), last_at=VALUES(last_at)");
    $insUserS = $db->prepare("INSERT INTO tm_user_sample (router_id, username, mac, sampled_at, in_bytes, out_bytes) VALUES (?,?,?,?,?,?)");
    $upUserD  = $db->prepare(
        "INSERT INTO tm_user_daily (router_id, username, day_at, in_bytes, out_bytes) VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE in_bytes=in_bytes+VALUES(in_bytes), out_bytes=out_bytes+VALUES(out_bytes)");
    $upPoll   = $db->prepare(
        "INSERT INTO tm_poll (router_id, ok, error, ms, last_try, last_ok) VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE ok=VALUES(ok), error=VALUES(error), ms=VALUES(ms),
             last_try=VALUES(last_try), last_ok=COALESCE(VALUES(last_ok), last_ok)");

    foreach ($routers as $r) {
        $rid = (int)$r['id'];
        $ok  = !empty($r['ok']);
        $upPoll->execute([$rid, $ok ? 1 : 0, (string)($r['error'] ?? ''), (int)($r['ms'] ?? 0), $now, $ok ? $now : null]);
        if (!$ok) {
            tm_say(sprintf('%-14s UNREACHABLE  %s', $r['name'], $r['error']));
            continue;
        }
        tm_say(sprintf('%-14s ok  %d interfaces, %d online  (%dms)',
            $r['name'], count($r['ifaces']), count($r['users']), (int)$r['ms']));

        $wanPick = tm_pick_wan($r['ifaces']);
        foreach ($r['ifaces'] as $i) {
            // A brand new interface starts watched if it is actually up, so the
            // dashboard is useful the moment the monitor is installed.
            $autoWatch = (!empty($i['running']) && empty($i['disabled'])) ? 1 : 0;
            $upIface->execute([$rid, $i['name'], $i['type'], $i['comment'],
                !empty($i['running']) ? 1 : 0, !empty($i['disabled']) ? 1 : 0,
                $autoWatch, ($wanPick !== null && $i['name'] === $wanPick) ? 1 : 0,
                null, null, null, $now]);
            $getIface->execute([$rid, $i['name']]);
            $row = $getIface->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;

            $elapsed = $row['last_at'] ? (strtotime($now) - strtotime($row['last_at'])) : 0;
            $rx = tm_rate(isset($row['last_rx']) ? (int)$row['last_rx'] : null, (int)$i['rx'], $elapsed, $maxGap);
            $tx = tm_rate(isset($row['last_tx']) ? (int)$row['last_tx'] : null, (int)$i['tx'], $elapsed, $maxGap);

            // Some builds report bits/sec directly and no counters at all.
            if ($rx === null && isset($i['rx_bps_direct'])) $rx = [0, (int)$i['rx_bps_direct']];
            if ($tx === null && isset($i['tx_bps_direct'])) $tx = [0, (int)$i['tx_bps_direct']];

            $rxBps = $rx ? $rx[1] : 0;
            $txBps = $tx ? $tx[1] : 0;
            $setIface->execute([(int)$i['rx'], (int)$i['tx'], $now, $rxBps, $txBps, (int)$row['id']]);

            if (($rx || $tx) && (int)$row['watched'] === 1) {
                $rxB = $rx ? $rx[0] : 0; $txB = $tx ? $tx[0] : 0;
                $insSample->execute([(int)$row['id'], $now, $rxBps, $txBps, $rxB, $txB]);
                $upHourly->execute([(int)$row['id'], $hour, $rxB, $txB, $rxBps, $txBps]);
            }
        }

        $seen = [];
        foreach ($r['users'] as $u) {
            $name = (string)$u['user'];
            $mac  = strtoupper((string)$u['mac']);
            $ukey = substr($name . '|' . $mac, 0, 120);
            if (isset($seen[$ukey])) continue;   // same device listed twice
            $seen[$ukey] = true;

            $getUser->execute([$rid, $ukey]);
            $prev = $getUser->fetch(PDO::FETCH_ASSOC);
            $elapsed = $prev ? (strtotime($now) - strtotime($prev['last_at'])) : 0;
            $in  = tm_rate($prev ? (int)$prev['last_in']  : null, (int)$u['in'],  $elapsed, $maxGap, true);
            $out = tm_rate($prev ? (int)$prev['last_out'] : null, (int)$u['out'], $elapsed, $maxGap, true);

            $upUser->execute([$rid, $ukey, $name, $mac, (string)$u['address'], (string)$u['uptime'],
                (int)$u['in'], (int)$u['out'], $in ? $in[1] : 0, $out ? $out[1] : 0, $now]);

            if ($in || $out) {
                $inB = $in ? $in[0] : 0; $outB = $out ? $out[0] : 0;
                if ($inB || $outB) {
                    $insUserS->execute([$rid, $name, $mac, $now, $inB, $outB]);
                    $upUserD->execute([$rid, $name, $day, $inB, $outB]);
                }
            }
        }
    }

    tm_purge($db);
}

/** Drop raw samples once they are older than the retention window. Hourly and daily
 *  buckets keep the history, so nothing visible on the long-range charts is lost. */
function tm_purge($db) {
    $last = (int)tm_setting_raw($db, 'last_purge', 0);
    if (time() - $last < 3600) return;
    $rawDays  = max(1, (int)tm_setting_raw($db, 'raw_retain_days', 7));
    $keepDays = max(30, (int)tm_setting_raw($db, 'hourly_retain_days', 400));
    $db->exec("DELETE FROM tm_iface_sample WHERE sampled_at < NOW() - INTERVAL $rawDays DAY");
    $db->exec("DELETE FROM tm_user_sample  WHERE sampled_at < NOW() - INTERVAL $rawDays DAY");
    $db->exec("DELETE FROM tm_iface_hourly WHERE hour_at    < NOW() - INTERVAL $keepDays DAY");
    $db->exec("DELETE FROM tm_user_daily   WHERE day_at     < NOW() - INTERVAL $keepDays DAY");
    // Devices that have not been seen for a day are gone, not idle.
    $db->exec("DELETE FROM tm_user_live    WHERE last_at    < NOW() - INTERVAL 1 DAY");
    $st = $db->prepare("INSERT INTO tm_settings (k,v) VALUES ('last_purge',?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
    $st->execute([(string)time()]);
}

/** tm_setting() caches for the life of the process; the purge stamp must not. */
function tm_setting_raw($db, $key, $default) {
    $st = $db->prepare("SELECT v FROM tm_settings WHERE k=?");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null || $v === '') ? $default : $v;
}

// ---------------------------------------------------------------------------

$db = tm_db();

if (in_array('--seed-wan', $opts, true)) {
    $lk = tm_lock(20);
    if ($lk === false) { tm_say('a poll is running - try again in a moment'); exit(1); }
    try { tm_seed_wan($db); } finally { tm_unlock($lk); }
    exit(0);
}

if (!$loop) {
    $lk = tm_lock(15);
    if ($lk === false) { tm_say('another poll is already running - nothing to do'); exit(0); }
    try { tm_poll_once($db); } finally { tm_unlock($lk); }
    exit(0);
}

while (true) {
    $started = microtime(true);
    $lk = null;
    try {
        $lk = tm_lock(5);
        if ($lk === false) { tm_log('skipped a round: a manual poll held the lock'); }
        else { tm_poll_once($db); }
    } catch (Throwable $e) {
        tm_log('poll failed: ' . $e->getMessage());
        // A dropped MySQL connection must not kill the service - reconnect next round.
        try { $db = tm_db(true); } catch (Throwable $e2) { tm_log('reconnect failed: ' . $e2->getMessage()); }
    } finally {
        tm_unlock($lk);
    }
    $pollSec = max(5, (int)tm_setting_raw($db, 'poll_seconds', 30));
    $sleep   = $pollSec - (microtime(true) - $started);
    if ($sleep > 0) usleep((int)($sleep * 1000000));
}
