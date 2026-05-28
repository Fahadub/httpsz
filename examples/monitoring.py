"""Continuous monitoring example."""
import time
from httpsz import HTTPSZ

def main():
    scanner = HTTPSZ(pinned_certs={
        "www.google.com": "17d0a6f172dbcbb5fd3715cc95481763d5772e57fc4d6a365bd98a766b1b545e"
    })
    print("Starting monitoring (Ctrl+C to stop)")
    try:
        while True:
            result = scanner.scan("https://www.google.com")
            status = "OK" if result.status == "secure" else "ALERT"
            print(f"[{time.strftime('%H:%M:%S')}] {status} | "
                  f"{result.security_grade} | {result.connection_time}")
            time.sleep(60)
    except KeyboardInterrupt:
        print("Stopped.")

if __name__ == "__main__":
    main()
