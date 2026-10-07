import Foundation
import FamilyControls

/// Settings that both the app and its extensions need to read.
/// Backed by the App Group's UserDefaults.
final class SharedSettings {
    static let shared = SharedSettings()

    private let defaults = AppGroup.defaults

    private enum Key {
        static let selection = "selection"
        static let protectionEnabled = "protectionEnabled"
        static let relockMinutes = "relockMinutes"
        static let scheduleEnabled = "scheduleEnabled"
        static let schedule = "schedule"
        static let temporaryUnlock = "temporaryUnlock"
        static let unlockRequest = "unlockRequest"
    }

    /// Apps, categories and websites chosen in the Screen Time picker.
    var selection: FamilyActivitySelection {
        get { decode(Key.selection) ?? FamilyActivitySelection() }
        set { encode(newValue, for: Key.selection) }
    }

    var protectionEnabled: Bool {
        get { defaults.bool(forKey: Key.protectionEnabled) }
        set { defaults.set(newValue, forKey: Key.protectionEnabled) }
    }

    /// Minutes an app stays unlocked before it locks itself again.
    var relockMinutes: Int {
        get {
            let value = defaults.integer(forKey: Key.relockMinutes)
            return value > 0 ? value : 5
        }
        set { defaults.set(newValue, forKey: Key.relockMinutes) }
    }

    var scheduleEnabled: Bool {
        get { defaults.bool(forKey: Key.scheduleEnabled) }
        set { defaults.set(newValue, forKey: Key.scheduleEnabled) }
    }

    var schedule: LockSchedule {
        get { decode(Key.schedule) ?? LockSchedule() }
        set { encode(newValue, for: Key.schedule) }
    }

    var temporaryUnlock: TemporaryUnlock? {
        get { decode(Key.temporaryUnlock) }
        set { encode(newValue, for: Key.temporaryUnlock) }
    }

    var unlockRequest: UnlockRequest? {
        get { decode(Key.unlockRequest) }
        set { encode(newValue, for: Key.unlockRequest) }
    }

    private func decode<T: Decodable>(_ key: String) -> T? {
        guard let data = defaults.data(forKey: key) else { return nil }
        return try? JSONDecoder().decode(T.self, from: data)
    }

    private func encode<T: Encodable>(_ value: T?, for key: String) {
        guard let value, let data = try? JSONEncoder().encode(value) else {
            defaults.removeObject(forKey: key)
            return
        }
        defaults.set(data, forKey: key)
    }
}
