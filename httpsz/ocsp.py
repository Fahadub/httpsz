"""REAL OCSP checking with automatic issuer certificate fetching."""

from datetime import datetime
from dataclasses import dataclass
from typing import Optional
import urllib.request

try:
    from cryptography import x509
    from cryptography.x509 import (
        load_der_x509_certificate,
        load_pem_x509_certificate,
        ExtensionOID,
        AuthorityInformationAccessOID,
    )
    from cryptography.x509.ocsp import (
        OCSPRequestBuilder,
        OCSPResponseStatus,
        OCSPCertStatus,
        load_der_ocsp_response,
    )
    from cryptography.hazmat.primitives import hashes, serialization
    CRYPTOGRAPHY_AVAILABLE = True
except ImportError:
    CRYPTOGRAPHY_AVAILABLE = False


@dataclass
class OCSPStatus:
    checked: bool
    revoked: bool
    response: Optional[str] = None
    next_update: Optional[str] = None
    error: str = None
    method: str = "unknown"
    issuer_fetched: bool = False
    ocsp_url: Optional[str] = None
    issuer_url: Optional[str] = None


class OCSPVerifier:
    """
    REAL OCSP verification with automatic issuer certificate fetching.

    Process:
    1. Extract OCSP responder URL from AIA extension
    2. Extract CA Issuers URL from AIA extension
    3. Download issuer certificate
    4. Build and send OCSP request with proper issuer
    5. Parse and verify OCSP response
    """

    def __init__(self, timeout: float = 15.0):
        self.timeout = timeout

    def check(self, cert_der, issuer_cert_der=None):
        """REAL OCSP check with automatic issuer fetching."""
        status = OCSPStatus(checked=False, revoked=False)

        if not CRYPTOGRAPHY_AVAILABLE:
            status.error = "cryptography library not installed"
            status.method = "skipped"
            return status

        try:
            cert = load_der_x509_certificate(cert_der)

            ocsp_url = self._extract_ocsp_url(cert)
            status.ocsp_url = ocsp_url
            if not ocsp_url:
                status.error = "No OCSP responder URL in certificate AIA"
                status.method = "no_ocsp_url"
                return status

            if issuer_cert_der:
                issuer_cert = load_der_x509_certificate(issuer_cert_der)
            else:
                issuer_cert = self._fetch_issuer_cert(cert)
                if issuer_cert:
                    status.issuer_fetched = True
                    status.issuer_url = self._extract_ca_issuers_url(cert)
                else:
                    status.error = "Could not fetch issuer certificate from AIA"
                    status.method = "issuer_fetch_failed"
                    return status

            builder = OCSPRequestBuilder()
            builder = builder.add_certificate(
                cert,
                issuer_cert,
                hashes.SHA256()
            )
            ocsp_request = builder.build()

            request_data = ocsp_request.public_bytes(serialization.Encoding.DER)

            req = urllib.request.Request(
                ocsp_url,
                data=request_data,
                headers={
                    "Content-Type": "application/ocsp-request",
                    "User-Agent": "HTTPSZ/2.1 OCSP-Checker",
                },
                method="POST",
            )

            with urllib.request.urlopen(req, timeout=self.timeout) as response:
                ocsp_response_data = response.read()

                ocsp_response = load_der_ocsp_response(ocsp_response_data)

                if ocsp_response.response_status == OCSPResponseStatus.SUCCESSFUL:
                    status.checked = True
                    status.method = "ocsp"

                    cert_status = ocsp_response.certificate_status

                    if cert_status == OCSPCertStatus.GOOD:
                        status.revoked = False
                        status.response = "good"
                    elif cert_status == OCSPCertStatus.REVOKED:
                        status.revoked = True
                        status.response = "revoked"
                    else:
                        status.response = "unknown"

                    if ocsp_response.next_update:
                        status.next_update = ocsp_response.next_update.isoformat()
                else:
                    status.error = f"OCSP response status: {ocsp_response.response_status}"
                    status.method = "ocsp_error"

        except Exception as e:
            status.error = str(e)
            status.method = "error"

        return status

    def _extract_ocsp_url(self, cert):
        """Extract OCSP responder URL from AIA extension."""
        try:
            aia = cert.extensions.get_extension_for_oid(
                ExtensionOID.AUTHORITY_INFORMATION_ACCESS
            )
            for desc in aia.value:
                if desc.access_method == AuthorityInformationAccessOID.OCSP:
                    return desc.access_location.value
        except Exception:
            pass
        return None

    def _extract_ca_issuers_url(self, cert):
        """Extract CA Issuers URL from AIA extension."""
        try:
            aia = cert.extensions.get_extension_for_oid(
                ExtensionOID.AUTHORITY_INFORMATION_ACCESS
            )
            for desc in aia.value:
                if desc.access_method == AuthorityInformationAccessOID.CA_ISSUERS:
                    return desc.access_location.value
        except Exception:
            pass
        return None

    def _fetch_issuer_cert(self, cert):
        """Fetch issuer certificate from CA Issuers URL."""
        ca_issuers_url = self._extract_ca_issuers_url(cert)
        if not ca_issuers_url:
            return None

        try:
            req = urllib.request.Request(
                ca_issuers_url,
                headers={"User-Agent": "HTTPSZ/2.1 OCSP-Checker"},
            )

            with urllib.request.urlopen(req, timeout=self.timeout) as response:
                issuer_data = response.read()

                try:
                    return load_der_x509_certificate(issuer_data)
                except Exception:
                    pass

                try:
                    return load_pem_x509_certificate(issuer_data)
                except Exception:
                    pass

                try:
                    from cryptography.hazmat.primitives.serialization import pkcs7
                    certs = pkcs7.load_der_pkcs7_certificates(issuer_data)
                    if certs:
                        return certs[0]
                except Exception:
                    pass

                return None

        except Exception:
            return None
