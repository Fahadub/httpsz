"""REAL Certificate Transparency (CT) verification via crt.sh."""

import hashlib
import json
import urllib.request
from datetime import datetime
from typing import List
from dataclasses import dataclass, field


@dataclass
class CTStatus:
    verified: bool
    logs_checked: int
    found_in: List[str] = field(default_factory=list)
    timestamp: str = ""
    error: str = None
    cert_count: int = 0
    exact_match: bool = False


class CTLogVerifier:
    """
    REAL Certificate Transparency verification using crt.sh API.

    crt.sh aggregates data from all major CT logs:
    - Google Argon/Xenon
    - Cloudflare Nimbus
    - Let's Encrypt Oak
    - DigiCert Yeti
    - Sectigo Sabre
    """

    def __init__(self, timeout: float = 15.0):
        self.timeout = timeout

    def verify(self, cert_der, hostname):
        """REAL CT verification via crt.sh API."""
        status = CTStatus(
            verified=False,
            logs_checked=0,
            timestamp=datetime.now().isoformat(),
        )

        try:
            cert_fingerprint = hashlib.sha256(cert_der).hexdigest().upper()

            url = f"https://crt.sh/?q=%25.{hostname}&output=json"

            req = urllib.request.Request(
                url,
                headers={
                    "User-Agent": "HTTPSZ/2.1 CT-Verifier",
                    "Accept": "application/json",
                }
            )

            with urllib.request.urlopen(req, timeout=self.timeout) as response:
                data = json.loads(response.read().decode("utf-8"))

                status.cert_count = len(data)
                status.logs_checked = 1

                for cert_entry in data:
                    entry_fp = cert_entry.get("sha256_fingerprint", "")
                    if not entry_fp:
                        entry_fp = cert_entry.get("fingerprint", "")
                    entry_fp = entry_fp.upper().replace(":", "").replace(" ", "")

                    if entry_fp and entry_fp == cert_fingerprint:
                        status.verified = True
                        status.exact_match = True
                        entry_id = cert_entry.get("id", "unknown")
                        status.found_in.append(f"crt.sh (entry #{entry_id})")
                        break

                if not status.verified and data:
                    status.found_in.append(
                        f"crt.sh ({len(data)} certs for domain, no exact match)"
                    )

        except Exception as e:
            status.error = str(e)

        return status
