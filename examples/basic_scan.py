"""Basic HTTPSZ v2.1 scan with REAL checks."""
from httpsz import HTTPSZ, SecurityReport

def main():
    print("HTTPSZ v2.1 - Real CT + Real OCSP (with issuer fetching)")
    print("=" * 60)

    scanner = HTTPSZ(enable_ech=True, use_doh=True, min_tls="1.2")
    result = scanner.scan("https://www.google.com")

    print(SecurityReport.to_text(result))

if __name__ == "__main__":
    main()
