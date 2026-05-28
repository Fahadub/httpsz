"""HTTPSZ v2.1 - Quick demo runner with REAL checks."""
from httpsz import HTTPSZ, SecurityReport

def main():
    print("HTTPSZ v2.1 - Real HTTPS Security Scanner")
    print("=" * 50)
    print("Features: REAL CT, REAL OCSP (auto-issuer), enhanced PQC")
    print("=" * 50)

    target = "https://www.google.com"
    print(f"Scanning: {target}\n")

    scanner = HTTPSZ(enable_ech=True, use_doh=True, min_tls="1.2")
    result = scanner.scan(target)

    print(SecurityReport.to_text(result))

if __name__ == "__main__":
    main()
