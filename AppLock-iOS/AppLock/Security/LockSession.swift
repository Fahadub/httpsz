import Foundation
import Observation

enum MainTab: Hashable {
    case protection
    case vault
    case intruders
    case settings
}

/// Tracks whether AppLock itself is unlocked, plus wrong-attempt lockouts.
@Observable
final class LockSession {
    private enum Key {
        static let failedAttempts = "failedAttempts"
        static let lockoutUntil = "lockoutUntil"
    }

    private(set) var isUnlocked = false
    private(set) var failedAttempts: Int
    private(set) var lockoutUntil: Date?

    /// Set after unlocking when the user came from a locked app's "Unlock" button.
    var unlockedRequest: UnlockRequest?
    var selectedTab: MainTab = .protection

    init() {
        let defaults = UserDefaults.standard
        failedAttempts = defaults.integer(forKey: Key.failedAttempts)
        lockoutUntil = defaults.object(forKey: Key.lockoutUntil) as? Date
    }

    var isLockedOut: Bool {
        guard let lockoutUntil else { return false }
        return lockoutUntil > Date()
    }

    func lock() {
        isUnlocked = false
        unlockedRequest = nil
    }

    func unlock() {
        failedAttempts = 0
        lockoutUntil = nil
        persist()
        isUnlocked = true
        handlePendingUnlockRequest()
    }

    /// Records a wrong attempt and returns the running total.
    /// Every fifth wrong attempt starts a lockout that grows by 30 seconds.
    @discardableResult
    func registerFailure() -> Int {
        failedAttempts += 1
        if failedAttempts % 5 == 0 {
            let seconds = Double(30 * (failedAttempts / 5))
            lockoutUntil = Date().addingTimeInterval(seconds)
        }
        persist()
        return failedAttempts
    }

    private func handlePendingUnlockRequest() {
        let settings = SharedSettings.shared
        guard let request = settings.unlockRequest else { return }
        guard request.isRecent else {
            settings.unlockRequest = nil
            return
        }
        ShieldController.temporarilyUnlock(request, minutes: settings.relockMinutes)
        unlockedRequest = request
        selectedTab = .protection
    }

    private func persist() {
        let defaults = UserDefaults.standard
        defaults.set(failedAttempts, forKey: Key.failedAttempts)
        defaults.set(lockoutUntil, forKey: Key.lockoutUntil)
    }
}
