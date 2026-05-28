# HTTPSZ v2.1 API Reference

## HTTPSZ Class

```python
from httpsz import HTTPSZ

scanner = HTTPSZ(
    pinned_certs=None,      # Dict[str, str]: hostname -> sha256
    enable_ech=True,        # Enable ECH (best-effort)
    use_doh=True,           # Enable DNS over HTTPS
    timeout=15.0,           # Connection timeout
    min_tls="1.2",          # Minimum TLS version ("1.2" or "1.3")
)
result = scanner.scan("https://example.com")
```

## ScanResult Fields

| Field | Description |
|-------|-------------|
| status | "secure" or "failed" |
| tls_version | Actual negotiated TLS version |
| cipher_used | Actual negotiated cipher |
| ct_status | Real CT verification via crt.sh |
| ocsp_status | Real OCSP with auto issuer fetch |
| pqc_status | PQC detection with honest reporting |
| security_score | 0-100 with proper deductions |

## OCSP Implementation Details

The OCSP verifier automatically:
1. Extracts OCSP URL from AIA extension
2. Fetches issuer certificate from CA Issuers URL
3. Supports DER, PEM, and PKCS#7 formats
4. Builds proper OCSP request with issuer
5. Parses response and detects revocation

## Known Limitations

- ECH: Python ssl has limited TLS extension visibility
- PQC in TLS 1.3: Key exchange negotiated separately, not visible
