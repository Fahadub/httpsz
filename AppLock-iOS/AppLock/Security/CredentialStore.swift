import CryptoKit
import Foundation
import Observation
import Security
import SwiftUI

enum CredentialKind: String, Codable, CaseIterable, Identifiable {
    case pin4
    case pin6
    case pattern

    var id: String { rawValue }

    var title: LocalizedStringKey {
        switch self {
        case .pin4: return "4-digit PIN"
        case .pin6: return "6-digit PIN"
        case .pattern: return "Pattern"
        }
    }

    var pinLength: Int {
        switch self {
        case .pin4: return 4
        case .pin6: return 6
        case .pattern: return 0
        }
    }

    var isPattern: Bool { self == .pattern }

    /// Patterns are stored as dot indexes joined by dashes, for example "0-4-8-5".
    static func secret(for pattern: [Int]) -> String {
        pattern.map(String.init).joined(separator: "-")
    }
}

/// Stores a salted, stretched hash of the passcode or pattern in the Keychain.
@Observable
final class CredentialStore {
    private struct StoredCredential: Codable {
        let kind: CredentialKind
        let salt: Data
        let hash: Data
    }

    private static let keychainKey = "credential"

    private(set) var kind: CredentialKind?

    var hasCredential: Bool { kind != nil }

    init() {
        kind = Self.loadRecord()?.kind
    }

    func save(secret: String, kind: CredentialKind) {
        var salt = Data(count: 16)
        let status = salt.withUnsafeMutableBytes { buffer in
            SecRandomCopyBytes(kSecRandomDefault, 16, buffer.baseAddress!)
        }
        if status != errSecSuccess {
            salt = Data(UUID().uuidString.utf8)
        }

        let record = StoredCredential(kind: kind, salt: salt, hash: Self.hash(secret, salt: salt))
        guard let data = try? JSONEncoder().encode(record) else { return }
        Keychain.set(data, for: Self.keychainKey)
        self.kind = kind
    }

    func verify(_ secret: String) -> Bool {
        guard let record = Self.loadRecord() else { return false }
        return Self.hash(secret, salt: record.salt) == record.hash
    }

    private static func loadRecord() -> StoredCredential? {
        guard let data = Keychain.data(for: keychainKey) else { return nil }
        return try? JSONDecoder().decode(StoredCredential.self, from: data)
    }

    private static func hash(_ secret: String, salt: Data) -> Data {
        var digest = Data(SHA256.hash(data: salt + Data(secret.utf8)))
        for _ in 0..<20_000 {
            digest = Data(SHA256.hash(data: digest + salt))
        }
        return digest
    }
}
