# HTTPSZ API Reference

## HTTPSZ Class

```python
from httpsz import HTTPSZ
scanner = HTTPSZ(
    pinned_certs=None,
    enable_ech=True,
    use_doh=True,
    timeout=10.0,
)
result = scanner.scan("https://example.com")
```

## ScanResult Fields

- status, url, hostname, status_code
- tls_version, cipher_used, resolved_ip, connection_time
- cert_info, pin_status, hsts_enabled
- ct_status, ocsp_status, ca_analysis
- anomalies, security_score, security_grade
- quantum_ready, ech_enabled, doh_used

## SecurityReport

```python
from httpsz import SecurityReport
SecurityReport.to_json(result)
SecurityReport.to_text(result)
SecurityReport.to_html(result)
```
