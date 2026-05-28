"""Core TLS/SSL functionality with strict security enforcement."""

import ssl
import socket
import hashlib
from datetime import datetime
from typing import Dict, Any, Optional, Tuple
from dataclasses import dataclass


@dataclass
class CertificateInfo:
    """Certificate metadata and fingerprints."""
    subject: Dict[str, str]
    issuer: Dict[str, str]
    serial: str
    not_before: str
    not_after: str
    fingerprint_sha256: str
    fingerprint_sha384: str
    san: list

    def is_expired(self) -> bool:
        try:
            expiry = datetime.strptime(self.not_after, "%b %d %H:%M:%S %Y GMT")
            return datetime.now() > expiry
        except (ValueError, TypeError):
            return False

    def days_until_expiry(self) -> int:
        try:
            expiry = datetime.strptime(self.not_after, "%b %d %H:%M:%S %Y GMT")
            return max(0, (expiry - datetime.now()).days)
        except (ValueError, TypeError):
            return 0


class SecureTLSContext:
    """Strict TLS 1.3 context with strong cipher suites."""

    STRICT_CIPHERS = [
        "TLS_AES_256_GCM_SHA384",
        "TLS_CHACHA20_POLY1305_SHA256",
        "TLS_AES_128_GCM_SHA256",
        "ECDHE-ECDSA-AES256-GCM-SHA384",
        "ECDHE-RSA-AES256-GCM-SHA384",
    ]

    def __init__(self, enable_ech: bool = True):
        self.enable_ech = enable_ech
        self.context = self._create_context()

    def _create_context(self) -> ssl.SSLContext:
        context = ssl.create_default_context()
        context.minimum_version = ssl.TLSVersion.TLSv1_3
        context.maximum_version = ssl.TLSVersion.TLSv1_3

        if self.enable_ech and hasattr(ssl, "OP_ECH"):
            context.options |= ssl.OP_ECH

        context.options |= ssl.OP_CIPHER_SERVER_PREFERENCE

        try:
            context.set_ciphers(":".join(self.STRICT_CIPHERS))
        except ssl.SSLError:
            pass

        return context

    def wrap_socket(self, sock, server_hostname):
        return self.context.wrap_socket(sock, server_hostname=server_hostname)


class CertificateParser:
    """Parse and extract certificate information."""

    @staticmethod
    def parse_field(field) -> Dict[str, str]:
        result = {}
        try:
            for item in field:
                for key, value in item:
                    result[key] = value
        except (TypeError, ValueError):
            pass
        return result

    @staticmethod
    def extract_info(cert_dict, cert_der) -> CertificateInfo:
        return CertificateInfo(
            subject=CertificateParser.parse_field(cert_dict.get("subject", ())),
            issuer=CertificateParser.parse_field(cert_dict.get("issuer", ())),
            serial=cert_dict.get("serialNumber", "N/A"),
            not_before=cert_dict.get("notBefore", "N/A"),
            not_after=cert_dict.get("notAfter", "N/A"),
            fingerprint_sha256=hashlib.sha256(cert_der).hexdigest(),
            fingerprint_sha384=hashlib.sha384(cert_der).hexdigest(),
            san=list(cert_dict.get("subjectAltName", [])),
        )


class ConnectionManager:
    """Manage secure connections."""

    @staticmethod
    def create_connection(hostname, port, timeout, resolved_ip=None):
        target = resolved_ip if resolved_ip else hostname
        return socket.create_connection((target, port), timeout=timeout)

    @staticmethod
    def send_http_request(ssl_sock, hostname, path, method="GET"):
        request = (
            f"{method} {path} HTTP/1.1\r\n"
            f"Host: {hostname}\r\n"
            f"User-Agent: HTTPSZ/1.0\r\n"
            f"Accept: */*\r\n"
            f"Connection: close\r\n"
            f"\r\n"
        )
        ssl_sock.sendall(request.encode("utf-8"))

    @staticmethod
    def receive_response(ssl_sock, buffer_size=4096):
        response_data = b""
        while True:
            chunk = ssl_sock.recv(buffer_size)
            if not chunk:
                break
            response_data += chunk
        return response_data

    @staticmethod
    def parse_response(response_data):
        text = response_data.decode("utf-8", errors="ignore")
        headers_part, _, body = text.partition("\r\n\r\n")
        status_line = headers_part.split("\r\n")[0]
        try:
            status_code = int(status_line.split(" ")[1])
        except (IndexError, ValueError):
            status_code = 0

        headers = {}
        for line in headers_part.split("\r\n")[1:]:
            if ":" in line:
                key, value = line.split(":", 1)
                headers[key.strip().lower()] = value.strip()

        return status_code, headers, body
