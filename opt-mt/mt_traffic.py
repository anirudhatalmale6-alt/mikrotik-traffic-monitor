#!/usr/bin/env python3
# Traffic collector for the MikroTik Traffic Monitor.
#
# Reads JSON from stdin:
#   {"routers":[{"id","name","host","port","user","pass"}], "hotspot": true}
# Prints JSON:
#   {"routers":[{"id","name","ok","error","ms",
#                "ifaces":[{name,type,comment,running,disabled,rx,tx}],
#                "users":[{user,mac,address,uptime,in,out}]}]}
#
# This script only READS from the router. It never changes anything.
#
# !! librouteros' api(...) returns a LAZY GENERATOR - the command only reaches the
# wire when that generator is iterated. Every call here goes through run() so a
# result is always consumed. See /opt/mt/mt_disconnect.py for the bug this caused.
import sys, json, socket, time

try:
    import librouteros
except Exception as e:
    print(json.dumps({"routers": [], "fatal": "librouteros not available: %s" % e}))
    sys.exit(0)


def run(api, cmd, **kwargs):
    """Send a command and CONSUME the generator, otherwise it never reaches the router."""
    return tuple(api(cmd, **kwargs))


def as_int(v):
    # RouterOS hands numbers back as ints sometimes and as strings other times.
    try:
        return int(str(v).strip())
    except Exception:
        return 0


def as_bool(v):
    return str(v).strip().lower() in ("true", "yes", "1")


def read_ifaces(api):
    """Interface list with its byte counters. Counters are cumulative since boot."""
    rows = run(api, "/interface/print")
    out = []
    for r in rows:
        name = str(r.get("name", "")).strip()
        if not name:
            continue
        out.append({
            "name":     name,
            "type":     str(r.get("type", "")),
            "comment":  str(r.get("comment", "")),
            "running":  as_bool(r.get("running")),
            "disabled": as_bool(r.get("disabled")),
            "rx":       as_int(r.get("rx-byte")),
            "tx":       as_int(r.get("tx-byte")),
        })
    return out


def fill_missing_counters(api, ifaces):
    """Some RouterOS builds omit rx-byte/tx-byte from /interface/print. Only in that
    case fall back to a one-shot monitor-traffic, which is more expensive."""
    missing = [i["name"] for i in ifaces if i["rx"] == 0 and i["tx"] == 0 and not i["disabled"]]
    if not missing:
        return
    try:
        rows = run(api, "/interface/monitor-traffic",
                   **{"interface": ",".join(missing), "once": ""})
    except Exception:
        return
    by_name = {i["name"]: i for i in ifaces}
    for r in rows:
        i = by_name.get(str(r.get("name", "")))
        if not i:
            continue
        # monitor-traffic reports bits/second directly - stash it so the caller can
        # use it as-is instead of differencing counters it does not have.
        i["rx_bps_direct"] = as_int(r.get("rx-bits-per-second"))
        i["tx_bps_direct"] = as_int(r.get("tx-bits-per-second"))


def read_wan_ifaces(api):
    """Which interfaces carry the default route, i.e. face the internet.

    Knowing this matters: a bridge and its member ports all report the same bytes,
    so adding every interface up would show a total several times the real one.
    RouterOS spells the gateway differently across versions - v7 gives
    immediate-gw "10.0.0.1%ether1", v6 gives gateway-status "10.0.0.1 reachable
    via ether1" - so both spellings are parsed and anything unrecognised is simply
    left out rather than guessed at.
    """
    names = set()
    try:
        rows = run(api, "/ip/route/print")
    except Exception:
        return names
    for r in rows:
        if str(r.get("dst-address", "")).strip() not in ("0.0.0.0/0", "::/0"):
            continue
        if str(r.get("active", "")).strip().lower() in ("false", "no"):
            continue
        gw = str(r.get("immediate-gw", ""))
        if "%" in gw:
            names.add(gw.split("%", 1)[1].strip())
            continue
        st = str(r.get("gateway-status", ""))
        if " via " in st:
            names.add(st.split(" via ", 1)[1].strip())
        elif str(r.get("gateway", "")) and not any(c.isdigit() for c in str(r.get("gateway", ""))):
            # A gateway given as a plain interface name (PPPoE / LTE links).
            names.add(str(r.get("gateway")).strip())
    return set(n for n in names if n)


def read_hotspot(api):
    rows = run(api, "/ip/hotspot/active/print")
    out = []
    for r in rows:
        out.append({
            "user":    str(r.get("user", "")),
            "mac":     str(r.get("mac-address", "")),
            "address": str(r.get("address", "")),
            "uptime":  str(r.get("uptime", "")),
            "in":      as_int(r.get("bytes-in")),
            "out":     as_int(r.get("bytes-out")),
        })
    return out


def main():
    data = json.load(sys.stdin)
    want_hotspot = data.get("hotspot", True)
    results = []

    for r in data.get("routers", []):
        entry = {"id": r.get("id"), "name": r.get("name", "?"), "ok": False,
                 "error": "", "ms": 0, "ifaces": [], "users": []}
        t0 = time.time()
        api = None
        try:
            ip = socket.gethostbyname(r["host"])
            api = librouteros.connect(host=ip, port=int(r["port"]),
                                      username=r["user"], password=r["pass"], timeout=8)
        except Exception as e:
            entry["error"] = "connect failed (%s)" % type(e).__name__
            entry["ms"] = int((time.time() - t0) * 1000)
            results.append(entry)
            continue

        try:
            entry["ifaces"] = read_ifaces(api)
            fill_missing_counters(api, entry["ifaces"])
            wan = read_wan_ifaces(api)
            for i in entry["ifaces"]:
                i["wan"] = i["name"] in wan
            entry["ok"] = True
        except Exception as e:
            entry["error"] = "interface list failed (%s)" % e

        if want_hotspot:
            try:
                entry["users"] = read_hotspot(api)
            except Exception:
                # A router without a hotspot is normal, not an error worth showing.
                entry["users"] = []

        entry["ms"] = int((time.time() - t0) * 1000)
        results.append(entry)
        try:
            api.close()
        except Exception:
            pass

    print(json.dumps({"routers": results}))


main()
