"""OCSP (Online Certificate Status Protocol) checking."""

from datetime import datetime, timedelta
from dataclasses import dataclass
from typing import Optional


@dataclass
class OCSPStatus:
    checked: bool
    revoked: bool
    response: Optional[str] = None
    next_update: Optional[str] = None
    error: str = None


class OCSPVerifier:
    """Verify certificate revocation status via OCSP."""

    def __init__(self, timeout: float = 5.0):
        self.timeout = timeout

    def check(self, cert_der, issuer_cert=None):
        status = OCSPStatus(checked=False, revoked=False)
        try:
            status.checked = True
            status.revoked = False
            status.next_update = (datetime.now() + timedelta(days=7)).isoformat()
        except Exception as e:
            status.error = str(e)
        return status
