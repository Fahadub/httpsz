"""Anomaly detection for connection fingerprinting."""

import time
from collections import defaultdict
from dataclasses import dataclass
from typing import List, Dict
from datetime import datetime


@dataclass
class ConnectionFingerprint:
    timestamp: float
    cert_fingerprint: str
    connection_time: float
    tls_version: str
    cipher: str


class AnomalyDetector:
    """Detect anomalies in HTTPS connections."""

    def __init__(self):
        self.connection_history = defaultdict(list)

    def detect(self, hostname, cert_fingerprint, cert_not_before,
               cert_not_after, connection_time, tls_version, cipher):
        anomalies = []
        try:
            not_before = datetime.strptime(
                cert_not_before, "%b %d %H:%M:%S %Y GMT"
            )
            not_after = datetime.strptime(
                cert_not_after, "%b %d %H:%M:%S %Y GMT"
            )
            cert_age = (datetime.now() - not_before).days
            cert_validity = (not_after - not_before).days

            if cert_validity > 398:
                anomalies.append(
                    f"Certificate validity period too long: {cert_validity} days"
                )
            if cert_age < 1:
                anomalies.append(f"Certificate is very new: {cert_age} days old")
        except (ValueError, TypeError):
            pass

        if connection_time > 5.0:
            anomalies.append(f"Slow connection: {connection_time:.2f}s")

        fingerprint = ConnectionFingerprint(
            timestamp=time.time(),
            cert_fingerprint=cert_fingerprint,
            connection_time=connection_time,
            tls_version=tls_version,
            cipher=cipher,
        )
        self.connection_history[hostname].append(fingerprint)

        if len(self.connection_history[hostname]) > 1:
            prev = self.connection_history[hostname][-2]
            curr = self.connection_history[hostname][-1]
            if prev.cert_fingerprint != curr.cert_fingerprint:
                anomalies.append(
                    "Certificate fingerprint changed! Potential MITM attack."
                )
            if prev.tls_version != curr.tls_version:
                anomalies.append(
                    f"TLS version changed: {prev.tls_version} -> {curr.tls_version}"
                )
        return anomalies

    def get_history(self, hostname):
        return self.connection_history.get(hostname, [])
