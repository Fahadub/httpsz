"""HTTPSZ - Quick demo runner."""
from httpsz import HTTPSZ, SecurityReport

def main():
    print("HTTPSZ - Next-Generation HTTPS Security Scanner")
    print("=" * 50)
    target = "https://www.google.com"
    print(f"Scanning: {target}\n")
    scanner = HTTPSZ(enable_ech=True, use_doh=True)
    result = scanner.scan(target)
    print(SecurityReport.to_text(result))

if __name__ == "__main__":
    main()
