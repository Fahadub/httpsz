"""Basic HTTPSZ v2.1 scan example."""
from httpsz import HTTPSZ, SecurityReport


def main():
    """Run a basic scan and display the report."""
    print("HTTPSZ v2.1 - Scanning with REAL CT, OCSP, and PQC checks")
    print("=" * 60)

    scanner = HTTPSZ(enable_ech=True, use_doh=True, min_tls="1.2")
    result = scanner.scan("https://www.google.com")

    print(SecurityReport.to_text(result))


if __name__ == "__main__":
    main()
