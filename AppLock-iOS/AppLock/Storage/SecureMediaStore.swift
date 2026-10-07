import CryptoKit
import Foundation
import ImageIO
import Observation
import UIKit

struct SecureItem: Codable, Identifiable, Hashable {
    let id: UUID
    let createdAt: Date
    /// Only used for intruder photos.
    var failedAttempts: Int?
}

/// Stores photos encrypted with AES-256-GCM. The key lives in the Keychain
/// and never leaves this device.
@Observable
final class SecureMediaStore {
    private(set) var items: [SecureItem] = []

    private let folder: URL
    private let indexURL: URL
    private let thumbnails = NSCache<NSUUID, UIImage>()

    init(folderName: String) {
        let base = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        var folder = base.appendingPathComponent(folderName, isDirectory: true)
        try? FileManager.default.createDirectory(at: folder, withIntermediateDirectories: true)

        // The key is device-only, so backups of these files could never be opened.
        var values = URLResourceValues()
        values.isExcludedFromBackup = true
        try? folder.setResourceValues(values)

        self.folder = folder
        self.indexURL = folder.appendingPathComponent("index.bin")
        loadIndex()
    }

    // MARK: - Writing

    func add(_ data: Data, failedAttempts: Int? = nil) throws {
        let item = SecureItem(id: UUID(), createdAt: Date(), failedAttempts: failedAttempts)
        let sealed = try Self.encrypt(data)
        try sealed.write(to: fileURL(for: item.id), options: [.atomic, .completeFileProtection])
        items.insert(item, at: 0)
        saveIndex()
    }

    func delete(_ item: SecureItem) {
        try? FileManager.default.removeItem(at: fileURL(for: item.id))
        thumbnails.removeObject(forKey: item.id as NSUUID)
        items.removeAll { $0.id == item.id }
        saveIndex()
    }

    func deleteAll() {
        items.forEach { try? FileManager.default.removeItem(at: fileURL(for: $0.id)) }
        thumbnails.removeAllObjects()
        items.removeAll()
        saveIndex()
    }

    // MARK: - Reading

    func thumbnail(for item: SecureItem, maxPixelSize: CGFloat = 320) async -> UIImage? {
        if let cached = thumbnails.object(forKey: item.id as NSUUID) {
            return cached
        }
        let url = fileURL(for: item.id)
        let image = await Task.detached(priority: .userInitiated) { () -> UIImage? in
            guard let data = Self.decryptFile(at: url) else { return nil }
            return Self.downsample(data, maxPixelSize: maxPixelSize)
        }.value
        if let image {
            thumbnails.setObject(image, forKey: item.id as NSUUID)
        }
        return image
    }

    func fullImage(for item: SecureItem) async -> UIImage? {
        let url = fileURL(for: item.id)
        return await Task.detached(priority: .userInitiated) { () -> UIImage? in
            guard let data = Self.decryptFile(at: url) else { return nil }
            return Self.downsample(data, maxPixelSize: 3000)
        }.value
    }

    // MARK: - Private

    private func fileURL(for id: UUID) -> URL {
        folder.appendingPathComponent(id.uuidString).appendingPathExtension("bin")
    }

    private func loadIndex() {
        guard let data = Self.decryptFile(at: indexURL),
              let decoded = try? JSONDecoder().decode([SecureItem].self, from: data) else { return }
        items = decoded
    }

    private func saveIndex() {
        guard let data = try? JSONEncoder().encode(items),
              let sealed = try? Self.encrypt(data) else { return }
        try? sealed.write(to: indexURL, options: [.atomic, .completeFileProtection])
    }

    private static let key: SymmetricKey = {
        let keychainKey = "vault.key"
        if let data = Keychain.data(for: keychainKey) {
            return SymmetricKey(data: data)
        }
        let key = SymmetricKey(size: .bits256)
        Keychain.set(key.withUnsafeBytes { Data($0) }, for: keychainKey)
        return key
    }()

    private static func encrypt(_ data: Data) throws -> Data {
        guard let combined = try AES.GCM.seal(data, using: key).combined else {
            throw CocoaError(.fileWriteUnknown)
        }
        return combined
    }

    private static func decryptFile(at url: URL) -> Data? {
        guard let sealed = try? Data(contentsOf: url),
              let box = try? AES.GCM.SealedBox(combined: sealed) else { return nil }
        return try? AES.GCM.open(box, using: key)
    }

    private static func downsample(_ data: Data, maxPixelSize: CGFloat) -> UIImage? {
        let sourceOptions = [kCGImageSourceShouldCache: false] as CFDictionary
        guard let source = CGImageSourceCreateWithData(data as CFData, sourceOptions) else { return nil }
        let options = [
            kCGImageSourceCreateThumbnailFromImageAlways: true,
            kCGImageSourceShouldCacheImmediately: true,
            kCGImageSourceCreateThumbnailWithTransform: true,
            kCGImageSourceThumbnailMaxPixelSize: maxPixelSize
        ] as CFDictionary
        guard let image = CGImageSourceCreateThumbnailAtIndex(source, 0, options) else { return nil }
        return UIImage(cgImage: image)
    }
}
