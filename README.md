# MikroTik Traffic Monitor

A small web panel that shows live bandwidth per router interface and per hotspot
user, with history, for a MikroTik network already managed by a RADIUS billing
panel. It runs alongside that panel on the same server, on its own sub-domain, and
shares its `routers` and `admin_users` tables read-only.

## What it does

- **Live traffic** - current RX/TX per interface, users online, routers reachable,
  and a chart of the last 1h / 3h / 12h / 24h / 7d / 30d.
- **Interfaces** - every interface each router reports. Tick which ones to record,
  and mark which one faces the internet.
- **Users** - who is online right now with their live speed, plus the heaviest
  users over today / 7 / 30 / 90 days and a per-voucher daily chart.
- **Routers** - whether each router answered its last poll, how long it took, and
  the error when it did not.
- **Settings** - poll interval, retention, storage used.

## How it collects

`tm-collect.service` runs `tm_collect.php --loop`, which calls `mt_traffic.py`
over the RouterOS API every 30 seconds (configurable). The router reports
cumulative byte counters; the collector turns consecutive readings into a rate.

Three details that decide whether the numbers are true:

1. **One interface per router is counted in the totals.** A MikroTik counts the
   same packet on the bridge, on the member port and on the WAN port. Adding every
   interface up reports several times the real throughput. The internet-facing
   interface is detected from the router's own default route, preferring the
   PPPoE/LTE session over the physical port it rides on, and can be corrected in
   the UI.
2. **Counter resets are handled explicitly.** A router reboot restarts the
   interface counters at zero: that sample is dropped rather than charted as a
   huge spike. A hotspot user reconnecting also restarts theirs, but there the new
   counter is real traffic the customer just used, so it is counted - otherwise
   every reconnect would vanish from the daily total.
3. **Only one collector runs at a time.** The service polls on its own clock while
   the "Poll now" button can fire another. Two collectors would each measure from a
   baseline the other had already moved, inventing spikes. A file lock serialises
   them, and if the lock cannot be taken it says so instead of failing silently.

Raw samples are kept for a few days; every poll also adds its bytes into an hourly
bucket per interface and a daily bucket per user, so long-range history survives
the purge without the raw table growing without bound.

## Install

```sh
# 1. web root
mkdir -p /var/www/traffic-monitor
cp -r www/. /var/www/traffic-monitor/
chown -R www-data:www-data /var/www/traffic-monitor

# 2. collector + config (outside the web root: it holds the DB password)
cp opt-mt/mt_traffic.py opt-mt/tm_collect.php opt-mt/tm_config.php /opt/mt/
chmod 750 /opt/mt/tm_config.php && chown root:www-data /opt/mt/tm_config.php
$EDITOR /opt/mt/tm_config.php          # set TM_DB_PASS

# 3. lock file - must be openable by both root and www-data.
#    Not /tmp (the service uses PrivateTmp) and not /run/lock
#    (fs.protected_regular blocks cross-user writes there).
touch /opt/mt/tm_collect.lock
chown www-data:www-data /opt/mt/tm_collect.lock && chmod 664 /opt/mt/tm_collect.lock

# 4. tables (only creates tm_* tables, touches nothing existing)
mysql -u USER -p DBNAME < schema.sql

# 5. vhost - put your sub-domain in place of __TM_SERVER_NAME__
sed 's/__TM_SERVER_NAME__/monitor.example.com/' deploy/nginx-traffic-monitor.conf \
  > /etc/nginx/sites-available/traffic-monitor
ln -s /etc/nginx/sites-available/traffic-monitor /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx

# 6. collector service
cp deploy/tm-collect.service /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now tm-collect

# 7. detect each router's internet link
php /opt/mt/tm_collect.php --seed-wan -v
```

Sign in with the same username and password as the billing panel.

## Command line

```sh
php /opt/mt/tm_collect.php --once -v      # one poll, printing what it found
php /opt/mt/tm_collect.php --loop         # what the service runs
php /opt/mt/tm_collect.php --seed-wan -v  # re-detect the internet link per router
systemctl status tm-collect
```

## Requirements

PHP 8 with PDO MySQL, Python 3 with `librouteros`, MariaDB/MySQL, nginx, and the
RouterOS API enabled on each router with the credentials already stored in the
billing panel's `routers` table.
