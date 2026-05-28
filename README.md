# HTTPSZ

**Next-Generation HTTPS Security Scanner** with Post-Quantum Cryptography readiness.

A comprehensive, future-proof security scanner built in pure Python.

## Features

- TLS 1.3 enforcement with strict cipher suites
- Certificate Transparency (CT) verification
- OCSP / CRL revocation checking
- DNS over HTTPS (DoH) resolution
- Post-Quantum Cryptography readiness
- Advanced anomaly detection
- Encrypted Client Hello (ECH) support
- Certificate pinning
- CA reputation analysis
- Security scoring (0-100, A+ to F)
- Multiple report formats: JSON, text, HTML
- Command-line interface

## Installation

```bash
pip install httpsz
```

Or from source:

```bash
git clone https://github.com/httpsz/httpsz.git
cd httpsz
pip install -e .
```

## Quick Start

```python
from httpsz import HTTPSZ, SecurityReport

scanner = HTTPSZ(enable_ech=True, use_doh=True)
result = scanner.scan("https://www.google.com")

print(f"Grade: {result.security_grade}")
print(f"Score: {result.security_score}/100")
print(SecurityReport.to_text(result))
```

## CLI Usage

```bash
httpsz https://www.google.com
httpsz https://www.google.com --format json -o report.json
httpsz https://api.bank.com --pin api.bank.com=<sha256>
```

## License

MIT
