"""Basic HTTPSZ scan example."""
from httpsz import HTTPSZ, SecurityReport

def main():
    scanner = HTTPSZ(enable_ech=True, use_doh=True)
    result = scanner.scan("https://www.google.com")
    print(SecurityReport.to_text(result))

if __name__ == "__main__":
    main()
