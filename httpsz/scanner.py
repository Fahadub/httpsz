"""Main HTTPSZ scanner with REAL CT, OCSP, enhanced PQC, and ECH via DNS."""

import time
from urllib.parse import urlparse
from typing import Dict, Any
from dataclasses import dataclass

from httpsz.core import SecureTLSContext, CertificateParser, ConnectionManager
from httpsz.transparency import CTLogVerifier
from httpsz.ocsp import OCSPVerifier
from httpsz.pqc import PQCDetector
from httpsz.doh import DoHResolver
from httpsz.anomaly import AnomalyDetector


@dataclass
class ScanResult:
    status: str
    url: str
    hostname: str
    status_code: int
    tls_version: str
    cipher_used: Any
    resolved_ip: str
    connection_time: str
    cert_info: Dict[str, Any]
    pin_status: str
    hsts_enabled: bool
    ct_status: Dict[str, Any]
    ocsp_status: Dict[str, Any]
    pqc_status: Dict[str, Any]
    ech_status: Dict[str, Any]
    ca_analysis: Dict[str, Any]
    anomalies: list
    security_score: int
    security_grade: str
    body_preview: str
    quantum_ready: bool
    ech_enabled: bool
    doh_used: bool
    error: str = None


class HTTPSZ:
    """Next-Generation HTTPS Security Scanner with REAL checks."""

    CA_REPUTATION = {
        "Let's Encrypt": 95,
        "DigiCert": 98,
        "GlobalSign": 96,
        "Sectigo": 94,
        "Google Trust Services": 97,
        "Amazon": 96,
        "Microsoft": 97,
        "Comodo": 93,
        "Symantec": 90,
    }

    def __init__(self, pinned_certs=None, enable_ech=True,
                 use_doh=True, timeout=10.0, min_tls="1.2"):
        self.pinned_certs = pinned_certs or {}
        self.enable_ech = enable_ech
        self.use_doh = use_doh
        self.timeout = timeout
        self.min_tls = min_tls

        self.tls_context = SecureTLSContext(min_tls_version=min_tls)
        self.ct_verifier = CTLogVerifier(timeout=timeout)
        self.ocsp_verifier = OCSPVerifier(timeout=timeout)
        self.pqc_detector = PQCDetector()
        self.doh_resolver = DoHResolver(timeout=timeout) if use_doh else None
        self.anomaly_detector = AnomalyDetector()

    def scan(self, url):
        """Perform comprehensive security scan of URL."""
        start_time = time.time()
        parsed = urlparse(url)
        hostname = parsed.hostname
        port = parsed.port or 443
        path = parsed.path or "/"
        if parsed.query:
            path += "?" + parsed.query

        try:
            # 1. DNS Resolution
            if self.doh_resolver:
                doh_result = self.doh_resolver.resolve(hostname)
                resolved_ip = doh_result.ip
                doh_used = doh_result.method == "doh"
            else:
                import socket
                resolved_ip = socket.gethostbyname(hostname)
                doh_used = False

            # 2. TCP Connection
            raw_sock = ConnectionManager.create_connection(
                hostname, port, self.timeout, resolved_ip
            )
            ssl_sock = self.tls_context.wrap_socket(raw_sock, hostname)

            # 3. Extract Certificate and Connection Info
            cert_dict = ssl_sock.getpeercert()
            cert_der = ssl_sock.getpeercert(binary_form=True)
            tls_version = ssl_sock.version()
            cipher_used = ssl_sock.cipher()
            cert_info = CertificateParser.extract_info(cert_dict, cert_der)

            # 4. Certificate Pinning
            pin_status = "not_pinned"
            if hostname in self.pinned_certs:
                expected = self.pinned_certs[hostname]
                if cert_info.fingerprint_sha256 == expected:
                    pin_status = "valid"
                else:
                    pin_status = "MISMATCH"
                    ssl_sock.close()
                    raise Exception(f"Certificate pin mismatch for {hostname}")

            # 5. REAL Certificate Transparency Check
            ct_status = self.ct_verifier.verify(cert_der, hostname)

            # 6. REAL OCSP Check (with automatic issuer fetching)
            ocsp_status = self.ocsp_verifier.check(cert_der)

            # 7. Enhanced Post-Quantum Detection
            pqc_status = self.pqc_detector.detect(cipher_used, ssl_sock, tls_version)

            # 8. REAL ECH Detection via DNS HTTPS records (Type 65)
            ech_status = self._check_ech_support(ssl_sock, hostname)
            ech_enabled = ech_status.get("enabled", False)

            # 9. CA Reputation
            ca_analysis = self._analyze_ca(cert_info.issuer)

            # 10. Anomaly Detection
            connection_time = time.time() - start_time
            anomalies = self.anomaly_detector.detect(
                hostname=hostname,
                cert_fingerprint=cert_info.fingerprint_sha256,
                cert_not_before=cert_info.not_before,
                cert_not_after=cert_info.not_after,
                connection_time=connection_time,
                tls_version=tls_version,
                cipher=str(cipher_used),
            )

            # 11. HTTP Request
            ConnectionManager.send_http_request(ssl_sock, hostname, path)
            response_data = ConnectionManager.receive_response(ssl_sock)
            ssl_sock.close()

            # 12. Parse Response
            status_code, headers, body = ConnectionManager.parse_response(response_data)
            hsts_enabled = "strict-transport-security" in headers

            # 13. Calculate Score
            security_score = self._calculate_score(
                cert_info, ct_status, ocsp_status, pqc_status,
                ech_status, ca_analysis, anomalies
            )

            return ScanResult(
                status="secure",
                url=url,
                hostname=hostname,
                status_code=status_code,
                tls_version=tls_version,
                cipher_used=cipher_used,
                resolved_ip=resolved_ip,
                connection_time=f"{connection_time:.3f}s",
                cert_info=cert_info.__dict__,
                pin_status=pin_status,
                hsts_enabled=hsts_enabled,
                ct_status=ct_status.__dict__,
                ocsp_status=ocsp_status.__dict__,
                pqc_status=pqc_status.__dict__,
                ech_status=ech_status,
                ca_analysis=ca_analysis,
                anomalies=anomalies,
                security_score=security_score,
                security_grade=self._get_grade(security_score),
                body_preview=body[:200] if body else "",
                quantum_ready=pqc_status.quantum_ready,
                ech_enabled=ech_enabled,
                doh_used=doh_used,
            )

        except Exception as e:
            return ScanResult(
                status="failed",
                url=url,
                hostname=hostname if 'hostname' in locals() else "unknown",
                status_code=0,
                tls_version="N/A",
                cipher_used=(),
                resolved_ip="",
                connection_time="0.000s",
                cert_info={},
                pin_status="N/A",
                hsts_enabled=False,
                ct_status={},
                ocsp_status={},
                pqc_status={},
                ech_status={},
                ca_analysis={},
                anomalies=[],
                security_score=0,
                security_grade="F",
                body_preview="",
                quantum_ready=False,
                ech_enabled=False,
                doh_used=self.use_doh,
                error=str(e),
            )

    def _check_ech_support(self, ssl_sock, hostname):
        """
        Check ECH support via DNS HTTPS records (Type 65).

        This is the REAL way browsers detect ECH - by querying DNS
        for HTTPS records that contain 'ech=' parameter with the
        ECHConfig in base64.
        """
        if not self.doh_resolver:
            return {
                "enabled": False,
                "method": "none",
                "details": "DoH disabled, cannot check DNS HTTPS records",
                "ech_config": None,
                "records_found": 0,
                "alpn": [],
            }

        try:
            ech_result = self.doh_resolver.check_ech_support(hostname)
            return {
                "enabled": ech_result["supported"],
                "method": "dns_https_record" if ech_result["supported"] else "none",
                "ech_config": ech_result.get("ech_config"),
                "records_found": ech_result["records_found"],
                "alpn": ech_result["alpn"],
                "details": ech_result["details"],
            }
        except Exception as e:
            return {
                "enabled": False,
                "method": "error",
                "details": str(e),
                "ech_config": None,
                "records_found": 0,
                "alpn": [],
            }

    def _analyze_ca(self, issuer):
        """Analyze Certificate Authority reputation."""
        ca_name = issuer.get("organizationName", "Unknown")
        score = self.CA_REPUTATION.get(ca_name, 50)
        return {
            "ca_name": ca_name,
            "reputation_score": score,
            "trusted": score >= 80,
            "warnings": [] if score >= 80 else [f"CA reputation: {score}/100"],
        }

    def _calculate_score(self, cert_info, ct_status, ocsp_status,
                         pqc_status, ech_status, ca_analysis, anomalies):
        """Calculate overall security score (0-100)."""
        score = 100

        score -= len(anomalies) * 5

        if not ct_status.verified:
            if ct_status.error:
                score -= 5
            else:
                score -= 15

        if not ocsp_status.checked:
            if ocsp_status.error:
                score -= 3
            else:
                score -= 10

        if ocsp_status.revoked:
            score -= 50

        if pqc_status.quantum_ready:
            score += 5

        if ech_status.get("enabled"):
            score += 3

        if not ca_analysis.get("trusted"):
            score -= 20

        days_left = cert_info.days_until_expiry()
        if days_left < 30:
            score -= 15
        elif days_left < 7:
            score -= 30

        return max(0, min(100, score))

    def _get_grade(self, score):
        """Convert score to letter grade."""
        if score >= 95: return "A+"
        if score >= 90: return "A"
        if score >= 85: return "B+"
        if score >= 80: return "B"
        if score >= 70: return "C"
        if score >= 60: return "D"
        return "F"
