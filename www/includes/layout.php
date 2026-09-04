<?php
/** Shared chrome for the Traffic Monitor. Same visual language as the billing panel. */

function tm_icon($key) {
    $i = [
        'live'      => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'ifaces'    => '<rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="18" x2="6.01" y2="18"/><line x1="10" y1="18" x2="10.01" y2="18"/><path d="M12 14V9"/><path d="M8.5 9a3.5 3.5 0 0 1 7 0"/><path d="M5.5 9a6.5 6.5 0 0 1 13 0"/>',
        'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'routers'   => '<rect x="3" y="3" width="18" height="7" rx="2"/><rect x="3" y="14" width="18" height="7" rx="2"/><line x1="7" y1="6.5" x2="7.01" y2="6.5"/><line x1="7" y1="17.5" x2="7.01" y2="17.5"/>',
        'reports'   => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'panel'     => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
    ];
    $svg = $i[$key] ?? '<circle cx="12" cy="12" r="9"/>';
    return '<svg class="ni" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $svg . '</svg>';
}

function tm_header($title, $active = '', $extraHead = '') {
    $user    = $_SESSION['tm_admin_user'] ?? 'Admin';
    $initial = strtoupper(substr($user, 0, 1));
    $site    = tm_setting('site_name', 'Traffic Monitor');
    $nav = [
        'Monitor' => [
            ['live',    'live',    '/index.php',            'Live Traffic'],
            ['ifaces',  'ifaces',  '/pages/interfaces.php', 'Interfaces'],
            ['users',   'users',   '/pages/users.php',      'Users'],
        ],
        'Network' => [
            ['routers', 'routers', '/pages/routers.php',    'Routers'],
        ],
    ];
    if (tm_is_admin()) {
        $nav['System'] = [['settings', 'settings', '/pages/settings.php', 'Settings']];
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?> - <?= h($site) ?></title>
<style>
:root{--bg:#0b1120;--panel:#131c31;--panel-2:#172136;--line:#24314d;--line-soft:#1b263f;
 --txt:#e6ecf7;--muted:#8b98b0;--muted-2:#6b7994;--accent:#6366f1;--accent-2:#3b82f6;
 --down:#34d399;--up:#60a5fa;--glow:rgba(99,102,241,.35);--radius:14px;}
*{margin:0;padding:0;box-sizing:border-box}
html,body{min-height:100%}
body{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;
 background:radial-gradient(1200px 600px at 15% -10%,#16213c 0%,transparent 55%),var(--bg);
 color:var(--txt);-webkit-font-smoothing:antialiased}
::-webkit-scrollbar{width:10px;height:10px}
::-webkit-scrollbar-thumb{background:#263450;border-radius:10px;border:2px solid transparent;background-clip:padding-box}
a{color:#93c5fd}
.sidebar{position:fixed;left:0;top:0;bottom:0;width:236px;z-index:60;
 background:linear-gradient(180deg,#101a30 0%,#0c1423 100%);border-right:1px solid var(--line-soft);
 padding:22px 0 30px;overflow-y:auto;transition:transform .25s ease}
.sidebar .brand{display:flex;align-items:center;gap:12px;padding:0 20px 18px;margin-bottom:6px;border-bottom:1px solid var(--line-soft)}
.sidebar .brand .mark{width:40px;height:40px;border-radius:12px;flex:0 0 auto;
 background:linear-gradient(135deg,var(--accent) 0%,var(--accent-2) 100%);display:flex;align-items:center;justify-content:center;
 box-shadow:0 8px 20px -6px var(--glow)}
.sidebar .brand .mark svg{width:21px;height:21px;stroke:#fff}
.sidebar .brand h2{color:#f4f7fc;font-size:13.5px;font-weight:700;white-space:nowrap}
.sidebar .brand span{color:var(--muted-2);font-size:11px}
.nav-section{padding:15px 20px 6px}
.nav-section span{color:#5a6885;font-size:10.5px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase}
.sidebar nav a{display:flex;align-items:center;gap:12px;margin:2px 12px;padding:10px 12px;color:var(--muted);
 text-decoration:none;font-size:13.5px;font-weight:500;border-radius:10px;position:relative;transition:background .15s,color .15s}
.sidebar nav a .ni{width:18px;height:18px;flex:0 0 auto;opacity:.85}
.sidebar nav a:hover{background:rgba(255,255,255,.04);color:#dbe3f2}
.sidebar nav a.active{background:linear-gradient(90deg,rgba(99,102,241,.20),rgba(59,130,246,.06));color:#fff;font-weight:600}
.sidebar nav a.active .ni{color:#a5b4ff}
.sidebar nav a.active::before{content:'';position:absolute;left:-12px;top:6px;bottom:6px;width:3px;border-radius:0 4px 4px 0;
 background:linear-gradient(180deg,var(--accent),var(--accent-2))}
.main{margin-left:236px;padding:0 30px 40px}
.topbar{position:sticky;top:0;z-index:40;display:flex;justify-content:space-between;align-items:center;
 padding:20px 0 16px;margin-bottom:22px;background:linear-gradient(180deg,var(--bg) 55%,rgba(11,17,32,0))}
.topbar .tb-left{display:flex;align-items:center;gap:14px}
.topbar h1{font-size:21px;font-weight:700}
.hamburger{display:none;width:40px;height:40px;border-radius:10px;border:1px solid var(--line);background:var(--panel);
 color:var(--txt);cursor:pointer;align-items:center;justify-content:center}
.hamburger svg{width:20px;height:20px}
.topbar .user{display:flex;align-items:center;gap:12px}
.topbar .user .who{text-align:right;line-height:1.2}
.topbar .user .who b{color:#eef2fb;font-size:13.5px;font-weight:600;display:block}
.topbar .user .who small{color:var(--muted-2);font-size:11px}
.topbar .user .avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent-2));
 color:#fff;font-weight:700;display:flex;align-items:center;justify-content:center}
.topbar .user a.logout{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:1px solid var(--line);
 border-radius:9px;color:#f2a3a3;text-decoration:none;font-size:13px;font-weight:600}
.topbar .user a.logout:hover{background:rgba(220,38,38,.12);border-color:#7f1d1d}
.card{background:linear-gradient(180deg,var(--panel) 0%,var(--panel-2) 100%);border:1px solid var(--line-soft);
 border-radius:var(--radius);padding:22px;margin-bottom:20px;box-shadow:0 12px 30px -18px rgba(0,0,0,.9)}
.card h3{font-size:15px;font-weight:700;margin-bottom:4px}
.card .sub{color:var(--muted-2);font-size:12.5px}
.card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px;flex-wrap:wrap}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:20px}
.stat-card{position:relative;overflow:hidden;background:linear-gradient(180deg,var(--panel) 0%,var(--panel-2) 100%);
 border:1px solid var(--line-soft);border-radius:var(--radius);padding:18px 20px;transition:transform .18s,border-color .18s}
.stat-card:hover{transform:translateY(-3px);border-color:#33456a}
.stat-card .label{color:var(--muted-2);font-size:12.5px;margin-bottom:7px;font-weight:500}
.stat-card .value{font-size:26px;font-weight:800;color:#f4f7fc;letter-spacing:-.5px;line-height:1.1}
.stat-card .value.green{color:var(--down)}.stat-card .value.blue{color:var(--up)}
.stat-card .value.yellow{color:#fbbf24}.stat-card .value.red{color:#f87171}
.stat-card .foot{color:var(--muted-2);font-size:11.5px;margin-top:6px}
table{width:100%;border-collapse:collapse}
th{text-align:left;padding:11px 14px;color:var(--muted-2);font-size:11px;font-weight:700;text-transform:uppercase;
 letter-spacing:.7px;border-bottom:1px solid var(--line);white-space:nowrap}
td{padding:12px 14px;border-bottom:1px solid var(--line-soft);font-size:13.5px;color:#d5deee}
tr:hover td{background:rgba(99,102,241,.06)}
tr:last-child td{border-bottom:none}
.num{font-variant-numeric:tabular-nums;text-align:right}
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:600;border:1px solid transparent}
.badge.green{background:rgba(6,78,59,.55);color:#6ee7b7;border-color:rgba(16,185,129,.25)}
.badge.red{background:rgba(127,29,29,.5);color:#fca5a5;border-color:rgba(239,68,68,.25)}
.badge.blue{background:rgba(30,58,95,.6);color:#93c5fd;border-color:rgba(59,130,246,.25)}
.badge.grey{background:rgba(55,65,81,.5);color:#cbd5e1;border-color:rgba(148,163,184,.2)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 16px;border:none;border-radius:9px;
 font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;transition:filter .15s}
.btn-primary{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff}
.btn-ghost{background:transparent;border:1px solid var(--line);color:var(--muted)}
.btn-ghost:hover{color:#dbe3f2;border-color:#33456a}
.btn-sm{padding:6px 12px;font-size:12px}
.form-group{margin-bottom:14px}
.form-group label{display:block;color:var(--muted);margin-bottom:6px;font-size:13px}
.form-group input,.form-group select{width:100%;padding:10px 13px;background:#0c1526;border:1px solid var(--line);
 border-radius:9px;color:#f1f5f9;font-size:14px;outline:none}
.form-group input:focus,.form-group select:focus{border-color:var(--accent)}
.alert{padding:12px 15px;border-radius:10px;margin-bottom:16px;font-size:13.5px;border:1px solid transparent}
.alert-success{background:rgba(6,78,59,.45);color:#6ee7b7;border-color:rgba(16,185,129,.3)}
.alert-error{background:rgba(127,29,29,.4);color:#fca5a5;border-color:rgba(239,68,68,.3)}
.alert-warn{background:rgba(113,63,18,.45);color:#fde68a;border-color:rgba(234,179,8,.3)}
.range{display:inline-flex;gap:6px;flex-wrap:wrap}
.range a{padding:6px 12px;border:1px solid var(--line);border-radius:8px;color:var(--muted);text-decoration:none;font-size:12.5px}
.range a.on{background:rgba(99,102,241,.18);border-color:#3b4a72;color:#fff}
.legend{display:flex;gap:16px;align-items:center;font-size:12px;color:var(--muted)}
.legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px}
.tmchart{width:100%;display:block;overflow:visible}
.tmchart-wrap{position:relative}
.tmtip{position:absolute;pointer-events:none;background:#0b1220;border:1px solid var(--line);border-radius:8px;
 padding:7px 10px;font-size:12px;color:#e6ecf7;white-space:nowrap;opacity:0;transition:opacity .1s;z-index:5;
 box-shadow:0 10px 24px -12px rgba(0,0,0,.9)}
.bar-row{display:flex;align-items:center;gap:10px}
.bar{flex:1;height:8px;background:#0c1526;border-radius:6px;overflow:hidden}
.bar i{display:block;height:100%;border-radius:6px;background:linear-gradient(90deg,var(--accent),var(--accent-2))}
.dim{color:var(--muted-2)}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:55}
@media (max-width:992px){.sidebar{transform:translateX(-100%)}body.sb-open .sidebar{transform:translateX(0)}
 body.sb-open .sb-overlay{display:block}.main{margin-left:0;padding:0 16px 40px}.hamburger{display:inline-flex}}
</style>
<?= $extraHead ?>
</head>
<body>
<div class="sb-overlay" onclick="document.body.classList.remove('sb-open')"></div>
<div class="sidebar">
  <div class="brand">
    <div class="mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg></div>
    <div><h2><?= h($site) ?></h2><span>MikroTik Live Traffic</span></div>
  </div>
  <nav>
    <?php foreach ($nav as $section => $items): ?>
      <div class="nav-section"><span><?= h($section) ?></span></div>
      <?php foreach ($items as $it): list($key, $act, $href, $label) = $it; ?>
        <a href="<?= $href ?>" class="<?= $active === $act ? 'active' : '' ?>"><?= tm_icon($key) ?><span><?= h($label) ?></span></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
</div>
<div class="main">
  <div class="topbar">
    <div class="tb-left">
      <button class="hamburger" onclick="document.body.classList.toggle('sb-open')" aria-label="Menu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <h1><?= h($title) ?></h1>
    </div>
    <div class="user">
      <div class="who"><b><?= h($user) ?></b><small><?= tm_is_admin() ? 'Administrator' : 'Operator' ?></small></div>
      <div class="avatar"><?= h($initial) ?></div>
      <a class="logout" href="/logout.php">Logout</a>
    </div>
  </div>
<?php
}

function tm_footer($extraBody = '') {
?>
</div>
<script src="/assets/tm.js"></script>
<?= $extraBody ?>
</body>
</html>
<?php
}
