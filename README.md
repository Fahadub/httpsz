# HTTPSZ v2.1

**Real HTTPS Security Scanner** with genuine Certificate Transparency, OCSP, and enhanced Post-Quantum Cryptography detection.

## What's New in v2.1

### Fixed from v2.0

- **REAL OCSP**: Now fetches issuer certificate automatically from AIA extension
- **Enhanced PQC detection**: Multiple methods with honest reporting of TLS 1.3 limitations
- **Honest ECH reporting**: Conservative reporting acknowledging Python ssl limitations
- **Removed unused `requests` dependency**: Only `cryptography` required
- **Fixed socket close ordering**: TLS checks complete before socket closure

### Features

- TLS 1.2/1.3 with strict cipher suites
- REAL Certificate Transparency via crt.sh API
- REAL OCSP with automatic issuer fetching (DER, PEM, PKCS#7)
- Post-Quantum Cryptography detection
- DNS over HTTPS (Cloudflare, Google, Quad9)
- Certificate pinning
- Anomaly detection
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
git clone https://github.com/Fahadub/httpsz.git
cd httpsz
pip install -r requirements.txt
pip install -e .
```

## Quick Start

### Python API

```python
from httpsz import HTTPSZ, SecurityReport

scanner = HTTPSZ(enable_ech=True, use_doh=True, min_tls="1.2")
result = scanner.scan("https://www.google.com")

print(f"Grade: {result.security_grade}")
print(f"Score: {result.security_score}/100")
print(SecurityReport.to_text(result))
```

### Command Line

```bash
httpsz https://www.google.com
httpsz https://www.google.com --format json -o report.json
httpsz https://api.bank.com --pin api.bank.com=<sha256>
httpsz https://example.com --min-tls 1.3
```

## Known Limitations (Honestly Documented)

### ECH Detection

Python's `ssl` module has limited visibility into TLS extensions. Real ECH detection requires parsing ClientHello/ServerHello for the `encrypted_client_hello` extension (0xfe0d). We report False unless concrete evidence is found.

### Post-Quantum Cryptography in TLS 1.3

In TLS 1.3, the key exchange algorithm (e.g., X25519Kyber768) is negotiated separately from the cipher suite via `supported_groups`. Python's ssl module does not expose the negotiated group, so PQC may be active but undetectable at this layer. We document this honestly in reports.

## Real Security Checks

### Certificate Transparency (CT)

Queries crt.sh API and matches certificate SHA256 fingerprint against public CT logs.

### OCSP Checking

- Extracts OCSP responder URL from certificate's AIA extension
- Fetches issuer certificate from CA Issuers URL (supports DER, PEM, PKCS#7)
- Builds and sends real OCSP request
- Parses and verifies OCSP response

### Post-Quantum Detection

Detects hybrid post-quantum cipher suites (X25519Kyber768, MLKEM768, etc.) when visible in cipher name.

## License

MIT
