"""Continuous monitoring example with certificate pinning."""

import time
import json
from datetime import datetime
from httpsz import HTTPSZ


TARGETS = {
    "https://www.google.com": None,
    "https://github.com": None,
    "https://www.cloudflare.com": None,
    "https://www.mozilla.org": None,
}

SCAN_INTERVAL = 300  # seconds
ALERT_THRESHOLD = 80


def log_event(event_type, hostname, data):
    """Log event in JSON format for SIEM integration."""
    log_entry = {
        "timestamp": datetime.now().isoformat(),
        "event_type": event_type,
        "hostname": hostname,
        **data,
    }
    print(json.dumps(log_entry))


def run_monitor():
    """Run continuous monitoring loop."""
    pinned_certs = {}
    for url, pin in TARGETS.items():
        if pin is not None:
            hostname = url.replace("https://", "").replace("http://", "").split("/")[0]
            pinned_certs[hostname] = pin

    scanner = HTTPSZ(
        pinned_certs=pinned_certs,
        enable_ech=True,
        use_doh=True,
        min_tls="1.2",
        timeout=15.0,
    )

    print("[MONITOR] Starting HTTPSZ v2.1 monitor")
    print(f"[MONITOR] Targets: {len(TARGETS)}")
    print(f"[MONITOR] Interval: {SCAN_INTERVAL}s")
    print(f"[MONITOR] Alert threshold: {ALERT_THRESHOLD}")
    print("=" * 80)

    previous_scores = {}

    try:
        while True:
            cycle_start = time.time()
            print(f"\n[{time.strftime('%Y-%m-%d %H:%M:%S')}] === Scan Cycle ===")

            for url in TARGETS:
                result = scanner.scan(url)

                if result.status != "secure":
                    status = "FAIL"
                    log_event("scan_failed", result.hostname, {
                        "error": result.error,
                    })
                elif result.security_score < ALERT_THRESHOLD:
                    status = "ALERT"
                    log_event("low_security_score", result.hostname, {
                        "score": result.security_score,
                        "grade": result.security_grade,
                        "anomalies": result.anomalies,
                    })
                else:
                    status = "OK"

                prev_score = previous_scores.get(result.hostname)
                if prev_score is not None:
                    degradation = prev_score - result.security_score
                    if degradation >= 10:
                        log_event("score_degradation", result.hostname, {
                            "previous": prev_score,
                            "current": result.security_score,
                            "drop": degradation,
                        })

                previous_scores[result.hostname] = result.security_score

                ct_mark = "v" if result.ct_status.get("verified") else "x"
                ocsp_mark = "v" if result.ocsp_status.get("checked") else "x"
                ech_mark = "v" if result.ech_enabled else "x"

                print(
                    f"[{time.strftime('%H:%M:%S')}] "
                    f"{status:<6} | "
                    f"{result.hostname:<30} | "
                    f"{result.security_grade:<3} "
                    f"{result.security_score:>3}/100 | "
                    f"CT={ct_mark} OCSP={ocsp_mark} ECH={ech_mark}"
                )

                if result.anomalies:
                    for anomaly in result.anomalies:
                        print(f"         ! {anomaly}")

            elapsed = time.time() - cycle_start
            sleep_time = max(0, SCAN_INTERVAL - elapsed)
            if sleep_time > 0:
                print(f"\n[MONITOR] Next scan in {int(sleep_time)}s...")
                time.sleep(sleep_time)

    except KeyboardInterrupt:
        print("\n[MONITOR] Stopped by user")


if __name__ == "__main__":
    run_monitor()
