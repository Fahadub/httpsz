"""
HTTPSZ v2.1 - Real HTTPS Security Scanner

Features (REAL implementations):
- TLS 1.2/1.3 with strict cipher suites
- REAL Certificate Transparency (crt.sh API)
- REAL OCSP with automatic issuer certificate fetching (AIA)
- Enhanced Post-Quantum Cryptography detection
- DNS over HTTPS
- Advanced anomaly detection
- Certificate pinning
- Honest reporting of limitations (ECH, PQC in TLS 1.3)
"""

from httpsz.scanner import HTTPSZ, ScanResult
from httpsz.report import SecurityReport

__version__ = "2.1.0"
__all__ = ["HTTPSZ", "ScanResult", "SecurityReport"]
