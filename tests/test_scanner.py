import pytest
from httpsz.scanner import HTTPSZ
from httpsz.core import CertificateParser, SecureTLSContext
from httpsz.doh import DoHResolver
from httpsz.anomaly import AnomalyDetector
from httpsz.pqc import PQCDetector


def test_scanner_instantiation():
    scanner = HTTPSZ()
    assert scanner.enable_ech is True
    assert scanner.use_doh is True


def test_scanner_custom_options():
    scanner = HTTPSZ(
        pinned_certs={"example.com": "abc123"},
        enable_ech=False,
        use_doh=False,
        timeout=5.0,
        min_tls="1.3"
    )
    assert scanner.pinned_certs == {"example.com": "abc123"}
    assert scanner.enable_ech is False
    assert scanner.min_tls == "1.3"


def test_tls_context_creation():
    import ssl
    ctx = SecureTLSContext(min_tls_version="1.3")
    assert ctx.context.minimum_version == ssl.TLSVersion.TLSv1_3


def test_certificate_parser_empty():
    info = CertificateParser.extract_info({}, b"test")
    assert info.subject == {}
    assert info.fingerprint_sha256


def test_doh_resolver_providers():
    resolver = DoHResolver(provider="cloudflare")
    assert resolver.provider == "cloudflare"
    with pytest.raises(ValueError):
        DoHResolver(provider="invalid_provider")


def test_anomaly_detector_slow_connection():
    detector = AnomalyDetector()
    anomalies = detector.detect(
        hostname="example.com",
        cert_fingerprint="abc123",
        cert_not_before="Jan 01 00:00:00 2024 GMT",
        cert_not_after="Jan 01 00:00:00 2026 GMT",
        connection_time=10.0,
        tls_version="TLSv1.3",
        cipher="TLS_AES_256_GCM_SHA384",
    )
    assert any("Slow connection" in a for a in anomalies)


def test_pqc_detector_classical():
    detector = PQCDetector()
    status = detector.detect(("TLS_AES_256_GCM_SHA384", "TLSv1.3", 256))
    assert status.quantum_ready is False


def test_pqc_detector_hybrid():
    detector = PQCDetector()
    status = detector.detect(("TLS_AES_256_GCM_SHA384_X25519Kyber768", "TLSv1.3", 256))
    assert status.quantum_ready is True
    assert status.hybrid_cipher is True


def test_pqc_tls13_limitation():
    detector = PQCDetector()
    status = detector.detect(
        ("TLS_AES_256_GCM_SHA384", "TLSv1.3", 256),
        tls_version="TLSv1.3"
    )
    assert status.detection_method == "tls13_limitation"
    assert "TLS 1.3" in status.details


def test_grade_calculation():
    scanner = HTTPSZ()
    assert scanner._get_grade(100) == "A+"
    assert scanner._get_grade(92) == "A"
    assert scanner._get_grade(87) == "B+"
    assert scanner._get_grade(82) == "B"
    assert scanner._get_grade(75) == "C"
    assert scanner._get_grade(65) == "D"
    assert scanner._get_grade(50) == "F"
