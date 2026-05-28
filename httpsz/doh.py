"""DNS over HTTPS (DoH) resolution with HTTPS/SVCB record support for ECH detection."""

import json
import socket
import urllib.request
from typing import Optional, List, Dict
from dataclasses import dataclass, field


@dataclass
class DoHResult:
    ip: str
    method: str
    provider: Optional[str] = None
    error: str = None


@dataclass
class HTTPSRecord:
    """DNS HTTPS record (Type 65) for ECH and connection hints."""
    priority: int
    target: str
    alpn: List[str] = field(default_factory=list)
    port: Optional[int] = None
    ipv4hint: List[str] = field(default_factory=list)
    ipv6hint: List[str] = field(default_factory=list)
    ech: Optional[str] = None
    raw_data: str = ""


class DoHResolver:
    """
    Resolve hostnames using DNS over HTTPS.

    Supports:
    - A records (IPv4)
    - AAAA records (IPv6)
    - HTTPS records (Type 65) for ECH detection
    """

    RECORD_TYPES = {
        "A": 1,
        "AAAA": 28,
        "HTTPS": 65,
        "SVCB": 64,
    }

    PROVIDERS = {
        "cloudflare": "https://cloudflare-dns.com/dns-query",
        "google": "https://dns.google/dns-query",
        "quad9": "https://dns.quad9.net/dns-query",
    }

    def __init__(self, provider: str = "cloudflare", timeout: float = 5.0):
        if provider not in self.PROVIDERS:
            raise ValueError(f"Unknown DoH provider: {provider}")
        self.provider = provider
        self.provider_url = self.PROVIDERS[provider]
        self.timeout = timeout

    def resolve(self, hostname, record_type="A"):
        """Resolve hostname using DNS over HTTPS."""
        try:
            url = f"{self.provider_url}?name={hostname}&type={record_type}"
            req = urllib.request.Request(
                url, headers={"Accept": "application/dns-json"}
            )
            with urllib.request.urlopen(req, timeout=self.timeout) as response:
                data = json.loads(response.read())
                if data.get("Answer"):
                    for answer in data["Answer"]:
                        rtype = answer.get("type")
                        if rtype == 1 and record_type == "A":
                            return DoHResult(
                                ip=answer["data"],
                                method="doh",
                                provider=self.provider,
                            )
                        if rtype == 28 and record_type == "AAAA":
                            return DoHResult(
                                ip=answer["data"],
                                method="doh",
                                provider=self.provider,
                            )
            return self._traditional(hostname)
        except Exception as e:
            return self._traditional(hostname, error=str(e))

    def resolve_https_record(self, hostname) -> List[HTTPSRecord]:
        """
        Resolve HTTPS records (Type 65) for ECH detection.

        HTTPS records contain:
        - ECH config (ech= parameter) - proves server supports ECH
        - ALPN hints (h2, h3)
        - IP hints (ipv4hint, ipv6hint)
        - Port override

        This is the RECOMMENDED way to detect ECH support
        (used by Chrome, Firefox, Safari).
        """
        records = []
        try:
            url = f"{self.provider_url}?name={hostname}&type=HTTPS"
            req = urllib.request.Request(
                url, headers={"Accept": "application/dns-json"}
            )

            with urllib.request.urlopen(req, timeout=self.timeout) as response:
                data = json.loads(response.read())

                for answer in data.get("Answer", []):
                    if answer.get("type") == 65:
                        raw_data = answer.get("data", "")
                        record = self._parse_https_record(raw_data)
                        if record:
                            records.append(record)

        except Exception:
            pass

        return records

    def check_ech_support(self, hostname) -> Dict:
        """
        Check if hostname supports ECH via DNS HTTPS records.

        Returns:
            {
                "supported": bool,
                "ech_config": str or None,
                "records_found": int,
                "alpn": list,
                "details": str
            }
        """
        result = {
            "supported": False,
            "ech_config": None,
            "records_found": 0,
            "alpn": [],
            "details": "",
        }

        records = self.resolve_https_record(hostname)
        result["records_found"] = len(records)

        if not records:
            result["details"] = "No HTTPS records found for this domain"
            return result

        for record in records:
            if record.ech:
                result["supported"] = True
                result["ech_config"] = record.ech
                result["alpn"] = record.alpn
                result["details"] = (
                    f"ECH supported via DNS HTTPS record "
                    f"(priority={record.priority}, target={record.target})"
                )
                return result

        for record in records:
            result["alpn"].extend(record.alpn)
        result["alpn"] = list(set(result["alpn"]))
        result["details"] = (
            f"HTTPS records found but no ECH config present "
            f"(ALPN: {result['alpn']})"
        )

        return result

    def _parse_https_record(self, raw_data: str) -> Optional[HTTPSRecord]:
        """
        Parse HTTPS record data from DNS JSON response.

        Format example:
            "1 . alpn=h2,h3 ipv4hint=1.2.3.4 ech=BASE64DATA"
            "1 cloudflare-ech.com ech=BASE64DATA"
        """
        try:
            parts = raw_data.strip().split()
            if len(parts) < 2:
                return None

            priority = int(parts[0])
            target = parts[1] if parts[1] != "." else ""

            record = HTTPSRecord(
                priority=priority,
                target=target,
                raw_data=raw_data,
            )

            for part in parts[2:]:
                if "=" not in part:
                    continue
                key, value = part.split("=", 1)
                key = key.lower()

                if key == "alpn":
                    record.alpn = value.split(",")
                elif key == "port":
                    try:
                        record.port = int(value)
                    except ValueError:
                        pass
                elif key == "ipv4hint":
                    record.ipv4hint = value.split(",")
                elif key == "ipv6hint":
                    record.ipv6hint = value.split(",")
                elif key == "ech":
                    record.ech = value

            return record

        except Exception:
            return None

    def _traditional(self, hostname, error=None):
        """Fallback to traditional DNS resolution."""
        try:
            ip = socket.gethostbyname(hostname)
            return DoHResult(ip=ip, method="traditional", error=error)
        except Exception as e:
            return DoHResult(ip="", method="traditional", error=str(e))
