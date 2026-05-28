"""DNS over HTTPS (DoH) resolution."""

import json
import socket
import urllib.request
from typing import Optional
from dataclasses import dataclass


@dataclass
class DoHResult:
    ip: str
    method: str
    provider: Optional[str] = None
    error: str = None


class DoHResolver:
    """Resolve hostnames using DNS over HTTPS."""

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
        try:
            url = f"{self.provider_url}?name={hostname}&type={record_type}"
            req = urllib.request.Request(
                url, headers={"Accept": "application/dns-json"}
            )
            with urllib.request.urlopen(req, timeout=self.timeout) as response:
                data = json.loads(response.read())
                if data.get("Answer"):
                    for answer in data["Answer"]:
                        if answer.get("type") == 1 and record_type == "A":
                            return DoHResult(
                                ip=answer["data"],
                                method="doh",
                                provider=self.provider,
                            )
                        if answer.get("type") == 28 and record_type == "AAAA":
                            return DoHResult(
                                ip=answer["data"],
                                method="doh",
                                provider=self.provider,
                            )
            return self._traditional(hostname)
        except Exception as e:
            return self._traditional(hostname, error=str(e))

    def _traditional(self, hostname, error=None):
        try:
            ip = socket.gethostbyname(hostname)
            return DoHResult(ip=ip, method="traditional", error=error)
        except Exception as e:
            return DoHResult(ip="", method="traditional", error=str(e))
