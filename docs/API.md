# HTTPSZ v2.1 API Reference

## HTTPSZ Class

```python
from httpsz import HTTPSZ

scanner = HTTPSZ(
    pinned_certs=None,      # Dict[str, str]: hostname -> sha256 fingerprint
    enable_ech=True,        # Enable ECH (best-effort)
    use_doh=True,           # Enable DNS over HTTPS
    timeout=15.0,           # Connection timeout in seconds
    min_tls="1.2",          # Minimum TLS version ("1.2" or "1.3")
)
```

## Methods

### scan(url: str) -> ScanResult

Perform a comprehensive security scan.

```python
result = scanner.scan("https://example.com")
```

## ScanResult Fields

| Field | Type | Description |
|-------|------|-------------|
| status | str | "secure" or "failed" |
| url | str | Scanned URL |
| hostname | str | Hostname |
| status_code | int | HTTP status code |
| tls_version | str | TLS version used |
| cipher_used | tuple | Cipher suite |
| resolved_ip | str | Resolved IP address |
| connection_time | str | Connection time |
| cert_info | dict | Certificate metadata |
| pin_status | str | Certificate pin status |
| hsts_enabled | bool | HSTS header present |
| ct_status | dict | CT verification result |
| ocsp_status | dict | OCSP check result |
| pqc_status | dict | PQC detection result |
| ca_analysis | dict | CA reputation analysis |
| anomalies | list | Detected anomalies |
| security_score | int | Score 0-100 |
| security_grade | str | Letter grade (A+ to F) |
| quantum_ready | bool | Post-quantum ready |
| ech_enabled | bool | ECH was enabled |
| doh_used | bool | DoH was used |

## SecurityReport

Generate reports in multiple formats:

```python
from httpsz import SecurityReport

SecurityReport.to_json(result)   # JSON string
SecurityReport.to_text(result)   # Human-readable text
SecurityReport.to_html(result)   # HTML report
```
