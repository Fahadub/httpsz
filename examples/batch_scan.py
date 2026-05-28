"""Batch scan multiple domains."""
from httpsz import HTTPSZ

def main():
    scanner = HTTPSZ()
    domains = ["google.com", "github.com", "cloudflare.com", "mozilla.org"]
    print(f"{'Domain':<25} {'Grade':<6} {'Score':<8} {'TLS':<10}")
    print("-" * 55)
    for domain in domains:
        result = scanner.scan(f"https://{domain}")
        print(
            f"{domain:<25} {result.security_grade:<6} "
            f"{result.security_score:<8} {result.tls_version:<10}"
        )

if __name__ == "__main__":
    main()
