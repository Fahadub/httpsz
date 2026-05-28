"""Batch scan multiple domains."""
from httpsz import HTTPSZ

def main():
    scanner = HTTPSZ(min_tls="1.2")
    domains = ["google.com", "github.com", "cloudflare.com", "mozilla.org"]

    print(f"{'Domain':<25} {'Grade':<6} {'Score':<8} {'CT':<10} {'OCSP':<10} {'PQC':<6}")
    print("-" * 75)

    for domain in domains:
        result = scanner.scan(f"https://{domain}")

        ct_status = "OK" if result.ct_status.get("verified") else "FAIL"
        ocsp_status = "OK" if result.ocsp_status.get("checked") else "FAIL"
        pqc_status = "YES" if result.pqc_status.get("quantum_ready") else "NO"

        print(
            f"{domain:<25} {result.security_grade:<6} "
            f"{result.security_score:<8} {ct_status:<10} "
            f"{ocsp_status:<10} {pqc_status:<6}"
        )

if __name__ == "__main__":
    main()
