<?php
/** Live figures for the dashboard's in-place refresh. Reads the database only -
 *  the routers are polled by the collector service, never by a page view. */
require_once __DIR__ . '/../includes/tm.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['tm_admin_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not signed in']);
    exit;
}

$db  = tm_db();
$sum = tm_summary($db);

$ifaces = [];
foreach (tm_ifaces($db) as $i) {
    $ifaces[(string)$i['id']] = [(int)$i['rx_bps'], (int)$i['tx_bps'], (int)$i['running']];
}

$pollSec = max(5, (int)tm_setting('poll_seconds', 30));

echo json_encode([
    'ok'            => true,
    'rx_bps'        => $sum['rx_bps'],
    'tx_bps'        => $sum['tx_bps'],
    'online'        => $sum['online'],
    'routers_up'    => $sum['routers_up'],
    'routers_total' => $sum['routers_total'],
    'last_poll'     => $sum['last_poll'],
    // The page shows a warning when this is true; it is the difference between
    // "no traffic right now" and "nobody has asked the router in an hour".
    'stale'         => !$sum['last_poll'] || (time() - strtotime($sum['last_poll'])) > max(180, $pollSec * 4),
    'ifaces'        => $ifaces,
]);
