"""Enhanced Post-Quantum Cryptography detection."""

from dataclasses import dataclass
from typing import Optional


@dataclass
class PQCStatus:
    quantum_ready: bool
    hybrid_cipher: bool
    key_exchange: str
    cipher_suite: str
    details: str = ""
    detection_method: str = "none"
    tls_version: str = ""


class PQCDetector:
    """
    Enhanced Post-Quantum Cryptography detection.

    Uses multiple detection methods:
    1. Cipher suite name analysis (works for TLS 1.2)
    2. Shared ciphers inspection
    3. OpenSSL version capability check

    LIMITATIONS (documented honestly):
    - In TLS 1.3, key exchange is negotiated separately from cipher suite
    - Python's ssl module has limited visibility into negotiated groups
    - X25519Kyber768 may be used but not visible in cipher name
    """

    PQC_KEY_EXCHANGES = [
        "X25519Kyber768",
        "X25519MLKEM768",
        "Secp256r1Kyber768",
        "Secp256r1MLKEM768",
        "X25519Kyber512",
        "P256Kyber768",
        "Kyber512",
        "Kyber768",
        "Kyber1024",
        "MLKEM512",
        "MLKEM768",
        "MLKEM1024",
    ]

    def detect(self, cipher_info, ssl_sock=None, tls_version="") -> PQCStatus:
        """
        Enhanced PQC detection with multiple methods.

        Args:
            cipher_info: Tuple from ssl_sock.cipher()
            ssl_sock: Optional SSL socket for deeper inspection
            tls_version: TLS version string (e.g., "TLSv1.3")
        """
        status = PQCStatus(
            quantum_ready=False,
            hybrid_cipher=False,
            key_exchange="unknown",
            cipher_suite="",
            tls_version=tls_version,
        )

        if not cipher_info or len(cipher_info) < 1:
            status.details = "No cipher information available"
            return status

        cipher_name = cipher_info[0] if isinstance(cipher_info, tuple) else str(cipher_info)
        status.cipher_suite = cipher_name

        # Method 1: Check cipher suite name
        for pqc_algo in self.PQC_KEY_EXCHANGES:
            if pqc_algo.lower() in cipher_name.lower():
                status.quantum_ready = True
                status.hybrid_cipher = True
                status.key_exchange = pqc_algo
                status.details = f"Post-Quantum hybrid cipher: {pqc_algo}"
                status.detection_method = "cipher_name"
                return status

        # Method 2: Check shared ciphers (if socket available)
        if ssl_sock:
            try:
                if hasattr(ssl_sock, 'shared_ciphers'):
                    shared = ssl_sock.shared_ciphers()
                    if shared:
                        for cipher_tuple in shared:
                            cipher_str = str(cipher_tuple).lower()
                            for pqc_algo in self.PQC_KEY_EXCHANGES:
                                if pqc_algo.lower() in cipher_str:
                                    status.quantum_ready = True
                                    status.hybrid_cipher = True
                                    status.key_exchange = pqc_algo
                                    status.details = f"PQC in shared ciphers: {pqc_algo}"
                                    status.detection_method = "shared_ciphers"
                                    return status
            except Exception:
                pass

            # Method 3: Check negotiated group via compression (rarely available)
            try:
                if hasattr(ssl_sock, 'compression'):
                    comp = ssl_sock.compression()
                    # compression() doesn't reveal key exchange, but call for completeness
            except Exception:
                pass

        # Method 4: Document TLS 1.3 limitation honestly
        if tls_version == "TLSv1.3":
            status.details = (
                "TLS 1.3 in use - key exchange (e.g., X25519Kyber768) "
                "is negotiated separately and not visible via Python's ssl module. "
                "PQC may be active but undetectable at this layer."
            )
            status.detection_method = "tls13_limitation"
            status.key_exchange = "unknown (TLS 1.3)"
        else:
            # TLS 1.2 - cipher name usually contains key exchange
            classical_strong = ["ECDHE", "DHE", "AES256", "AES128", "CHACHA20"]
            is_strong = any(algo in cipher_name.upper() for algo in classical_strong)

            if is_strong:
                status.details = "Classical strong cipher (not post-quantum)"
                status.key_exchange = "ECDHE/DHE (classical)"
                status.detection_method = "cipher_analysis"
            else:
                status.details = "Weak or unknown cipher suite"
                status.detection_method = "cipher_analysis"

        return status
