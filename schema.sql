-- MikroTik Traffic Monitor - schema.
-- Every table is prefixed tm_ and is NEW; nothing in the existing billing panel
-- (radcheck, radacct, routers, admin_users, ...) is modified in any way.
-- The monitor reads `routers` and `admin_users`, and writes only to tm_* tables.

CREATE TABLE IF NOT EXISTS tm_iface (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  router_id   INT NOT NULL,
  name        VARCHAR(64) NOT NULL,
  type        VARCHAR(48)  NOT NULL DEFAULT '',
  comment     VARCHAR(160) NOT NULL DEFAULT '',
  running     TINYINT(1)   NOT NULL DEFAULT 0,
  disabled    TINYINT(1)   NOT NULL DEFAULT 0,
  -- watched=1 shows the interface on the dashboard. Set from the UI so a router
  -- with 30 interfaces does not drown the page.
  watched     TINYINT(1)   NOT NULL DEFAULT 0,
  -- is_wan=1 means this link faces the internet. Seeded from the router's own
  -- default route on first discovery, then owned by the UI. The dashboard total
  -- uses only WAN links when any are marked, because a bridge and its member
  -- ports report the same bytes and adding them up inflates the figure.
  is_wan      TINYINT(1)   NOT NULL DEFAULT 0,
  -- last raw counters, used to turn cumulative bytes into a per-second rate
  last_rx     BIGINT UNSIGNED DEFAULT NULL,
  last_tx     BIGINT UNSIGNED DEFAULT NULL,
  last_at     DATETIME DEFAULT NULL,
  rx_bps      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tx_bps      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  seen_at     DATETIME DEFAULT NULL,
  UNIQUE KEY uq_iface (router_id, name),
  KEY k_watched (watched)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tm_iface_sample (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  iface_id   INT NOT NULL,
  sampled_at DATETIME NOT NULL,
  rx_bps     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tx_bps     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rx_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- bytes moved during this interval
  tx_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY k_iface_time (iface_id, sampled_at),
  KEY k_time (sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per live hotspot device, refreshed every poll.
CREATE TABLE IF NOT EXISTS tm_user_live (
  router_id INT NOT NULL,
  ukey      VARCHAR(120) NOT NULL,          -- username|mac, so one device = one row
  username  VARCHAR(96)  NOT NULL DEFAULT '',
  mac       VARCHAR(32)  NOT NULL DEFAULT '',
  ip        VARCHAR(45)  NOT NULL DEFAULT '',
  uptime    VARCHAR(32)  NOT NULL DEFAULT '',
  last_in   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_out  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  in_bps    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  out_bps   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_at   DATETIME NOT NULL,
  PRIMARY KEY (router_id, ukey),
  KEY k_last (last_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-user traffic actually moved in each interval. Summing this over a day gives
-- "how much did this voucher use today" without trusting a counter that resets
-- every time the customer reconnects.
CREATE TABLE IF NOT EXISTS tm_user_sample (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  router_id  INT NOT NULL,
  username   VARCHAR(96) NOT NULL DEFAULT '',
  mac        VARCHAR(32) NOT NULL DEFAULT '',
  sampled_at DATETIME NOT NULL,
  in_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  out_bytes  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY k_user_time (username, sampled_at),
  KEY k_time (sampled_at),
  KEY k_router_time (router_id, sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Roll-ups.
--
-- Raw samples are kept for a few days only. A 30s poll over 3 routers is roughly
-- 70k rows a day, which would be gigabytes within a year, so every poll also adds
-- its bytes straight into an hourly (per interface) and a daily (per user) bucket.
-- The long-range charts and the monthly usage report read the buckets, never the
-- raw table, so history stays complete while the raw table stays small.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS tm_iface_hourly (
  iface_id   INT NOT NULL,
  hour_at    DATETIME NOT NULL,               -- truncated to the hour
  rx_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tx_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  peak_rx_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
  peak_tx_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
  samples    INT NOT NULL DEFAULT 0,
  PRIMARY KEY (iface_id, hour_at),
  KEY k_hour (hour_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tm_user_daily (
  router_id INT NOT NULL,
  username  VARCHAR(96) NOT NULL DEFAULT '',
  day_at    DATE NOT NULL,
  in_bytes  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  out_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (router_id, username, day_at),
  KEY k_day (day_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Poll health, so "no traffic" can be told apart from "router unreachable".
CREATE TABLE IF NOT EXISTS tm_poll (
  router_id INT PRIMARY KEY,
  ok        TINYINT(1) NOT NULL DEFAULT 0,
  error     VARCHAR(255) NOT NULL DEFAULT '',
  ms        INT NOT NULL DEFAULT 0,
  last_try  DATETIME DEFAULT NULL,
  last_ok   DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tm_settings (
  k VARCHAR(64) PRIMARY KEY,
  v VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO tm_settings (k, v) VALUES
  ('poll_seconds',      '30'),
  ('raw_retain_days',   '7'),    -- tm_iface_sample
  ('user_raw_retain_days','2'),  -- tm_user_sample: one row per online device per poll
  ('hourly_retain_days','400'),  -- tm_iface_hourly / tm_user_daily
  ('site_name',         'Traffic Monitor');
