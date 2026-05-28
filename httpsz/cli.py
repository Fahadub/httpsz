"""Command-line interface for HTTPSZ."""

import sys
import argparse
from httpsz.scanner import HTTPSZ
from httpsz.report import SecurityReport


def main():
    parser = argparse.ArgumentParser(
        prog="httpsz",
        description="HTTPSZ v2.1 - Real HTTPS Security Scanner",
    )
    parser.add_argument("url", help="URL to scan")
    parser.add_argument("--format", choices=["text", "json", "html"],
                        default="text")
    parser.add_argument("--output", "-o", help="Write report to file")
    parser.add_argument("--no-doh", action="store_true")
    parser.add_argument("--no-ech", action="store_true")
    parser.add_argument("--pin", action="append", default=[])
    parser.add_argument("--timeout", type=float, default=15.0)
    parser.add_argument("--min-tls", choices=["1.2", "1.3"], default="1.2",
                        help="Minimum TLS version (default: 1.2)")

    args = parser.parse_args()

    pinned_certs = {}
    for pin in args.pin:
        if "=" in pin:
            host, fp = pin.split("=", 1)
            pinned_certs[host] = fp

    scanner = HTTPSZ(
        pinned_certs=pinned_certs,
        enable_ech=not args.no_ech,
        use_doh=not args.no_doh,
        timeout=args.timeout,
        min_tls=args.min_tls,
    )

    result = scanner.scan(args.url)

    if args.format == "json":
        output = SecurityReport.to_json(result)
    elif args.format == "html":
        output = SecurityReport.to_html(result)
    else:
        output = SecurityReport.to_text(result)

    if args.output:
        with open(args.output, "w", encoding="utf-8") as f:
            f.write(output)
        print(f"Report saved to: {args.output}")
    else:
        print(output)

    sys.exit(0 if result.status == "secure" else 1)


if __name__ == "__main__":
    main()
