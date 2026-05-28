def _check_ech_support(self, ssl_sock, hostname):
    """
    Check ECH support via DNS HTTPS records (Type 65).

    This is the REAL way browsers detect ECH - by querying DNS
    for HTTPS records that contain 'ech=' parameter with the
    ECHConfig in base64.

    Python's ssl module cannot detect ECH from TLS handshake alone,
    so DNS-based detection is the reliable method.
    """
    if not self.doh_resolver:
        return {
            "enabled": False,
            "method": "none",
            "details": "DoH disabled, cannot check DNS HTTPS records",
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
        }
