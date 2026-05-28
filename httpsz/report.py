"""Security report generation in multiple formats."""

import json
from datetime import datetime


class SecurityReport:
    """Generate security reports in various formats."""

    @staticmethod
    def to_json(result, indent=2):
        """Generate JSON report."""
        return json.dumps(result.__dict__, indent=indent, default=str)

    @staticmethod
    def to_text(result):
        """Generate human-readable text report."""
        lines = []
        lines.append("=" * 70)
        lines.append("HTTPSZ v2.1 SECURITY REPORT (REAL IMPLEMENTATION)")
        lines.append("=" * 70)
        lines.append(f"URL: {result.url}")
        lines.append(f"Hostname: {result.hostname}")
        lines.append(f"Status: {result.status}")
        lines.append(f"Security Grade: {result.security_grade}")
        lines.append(f"Security Score: {result.security_score}/100")
        lines.append(f"Connection Time: {result.connection_time}")
        lines.append(f"Resolved IP: {result.resolved_ip}")
        lines.append("-" * 70)
        lines.append("")
        lines.append("CONNECTION DETAILS:")
        lines.append(f"  TLS Version: {result.tls_version}")
        lines.append(f"  Cipher: {result.cipher_used}")
        lines.append(f"  HTTP Status: {result.status_code}")
        hsts = "Enabled" if result.hsts_enabled else "Disabled"
        ech = "Enabled" if result.ech_enabled else "Not detected (see limitations)"
        doh = "Used" if result.doh_used else "Not Used"
        lines.append(f"  HSTS: {hsts}")
        lines.append(f"  ECH: {ech}")
        lines.append(f"  DoH: {doh}")

        if result.cert_info:
            cert = result.cert_info
            lines.append("")
            lines.append("CERTIFICATE INFO:")
            subj = cert.get("subject", {}).get("commonName", "N/A")
            iss = cert.get("issuer", {}).get("commonName", "N/A")
            lines.append(f"  Subject: {subj}")
            lines.append(f"  Issuer: {iss}")
            lines.append(f"  Valid: {cert.get('not_before')} - {cert.get('not_after')}")
            lines.append(f"  SHA256: {cert.get('fingerprint_sha256')}")

        lines.append("")
        lines.append("SECURITY CHECKS (REAL):")
        lines.append(f"  Certificate Pin: {result.pin_status}")

        ct = result.ct_status
        if ct:
            if ct.get("verified"):
                lines.append(f"  CT Logs: VERIFIED (exact: {ct.get('exact_match')}, certs: {ct.get('cert_count', 0)})")
                for log in ct.get("found_in", []):
                    lines.append(f"    -> {log}")
            elif ct.get("error"):
                lines.append(f"  CT Logs: ERROR - {ct['error']}")
            else:
                lines.append("  CT Logs: NOT VERIFIED")

        ocsp = result.ocsp_status
        if ocsp:
            if ocsp.get("revoked"):
                lines.append(f"  OCSP: REVOKED! (method: {ocsp.get('method', 'unknown')})")
            elif ocsp.get("checked"):
                issuer_note = " [issuer auto-fetched]" if ocsp.get("issuer_fetched") else ""
                lines.append(f"  OCSP: GOOD (method: {ocsp.get('method', 'unknown')}){issuer_note}")
                if ocsp.get("ocsp_url"):
                    lines.append(f"    -> Responder: {ocsp['ocsp_url']}")
                if ocsp.get("next_update"):
                    lines.append(f"    -> Next update: {ocsp['next_update']}")
            elif ocsp.get("error"):
                lines.append(f"  OCSP: ERROR - {ocsp['error']}")
            else:
                lines.append("  OCSP: NOT CHECKED")

        pqc = result.pqc_status
        if pqc:
            if pqc.get("quantum_ready"):
                lines.append(f"  Post-Quantum: YES - {pqc.get('key_exchange', '')}")
            else:
                details = pqc.get("details", "")
                lines.append(f"  Post-Quantum: No (method: {pqc.get('detection_method', 'none')})")
                if details:
                    lines.append(f"    -> {details}")

        rep = result.ca_analysis.get("reputation_score", "N/A")
        lines.append(f"  CA Reputation: {rep}/100")

        if result.anomalies:
            lines.append("")
            lines.append(f"ANOMALIES DETECTED ({len(result.anomalies)}):")
            for a in result.anomalies:
                lines.append(f"  - {a}")
        else:
            lines.append("")
            lines.append("No anomalies detected")

        lines.append("")
        lines.append("-" * 70)
        lines.append("LIMITATIONS:")
        lines.append("  - ECH detection: Python ssl has limited TLS extension visibility")
        lines.append("  - PQC in TLS 1.3: Key exchange not visible via Python ssl")
        lines.append("=" * 70)
        return "\n".join(lines)

    @staticmethod
    def to_html(result):
        """Generate HTML report."""
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        return f"""<!DOCTYPE html>
<html>
<head>
    <title>HTTPSZ v2.1 Security Report</title>
    <style>
        body {{ font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }}
        .header {{ background: #2c3e50; color: white; padding: 20px; border-radius: 8px; }}
        .section {{ margin: 20px 0; padding: 15px; background: white; border-radius: 8px; }}
        .grade {{ font-size: 48px; font-weight: bold; color: #27ae60; }}
        .real {{ color: #27ae60; font-weight: bold; }}
    </style>
</head>
<body>
    <div class="header">
        <h1>HTTPSZ v2.1 Security Report <span class="real">(REAL Implementation)</span></h1>
        <p>Generated: {now}</p>
    </div>
    <div class="section">
        <h2>Summary</h2>
        <p><strong>URL:</strong> {result.url}</p>
        <p><strong>Status:</strong> {result.status}</p>
        <p><strong>Grade:</strong> <span class="grade">{result.security_grade}</span></p>
        <p><strong>Score:</strong> {result.security_score}/100</p>
    </div>
    <div class="section">
        <h2>Connection Details</h2>
        <ul>
            <li>TLS Version: {result.tls_version}</li>
            <li>Cipher: {result.cipher_used}</li>
            <li>HTTP Status: {result.status_code}</li>
            <li>Connection Time: {result.connection_time}</li>
        </ul>
    </div>
</body>
</html>"""
