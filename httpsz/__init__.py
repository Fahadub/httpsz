"""
HTTPSZ - Next-Generation HTTPS Security Scanner

Features:
- TLS 1.3 with strict cipher suites
- Certificate Transparency verification
- OCSP/CRL checking
- DNS over HTTPS
- Post-Quantum Cryptography readiness
- Advanced anomaly detection
- Encrypted Client Hello (ECH)
"""

from httpsz.scanner import HTTPSZ, ScanResult
from httpsz.report import SecurityReport

__version__ = "1.0.0"
__all__ = ["HTTPSZ", "ScanResult", "SecurityReport"]
