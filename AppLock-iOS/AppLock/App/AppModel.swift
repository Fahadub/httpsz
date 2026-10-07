import Foundation
import Observation

/// Keys for settings that only the main app uses.
enum SettingsKey {
    static let hasCompletedOnboarding = "hasCompletedOnboarding"
    static let biometricsEnabled = "biometricsEnabled"
    static let intruderEnabled = "intruderEnabled"
    static let intruderThreshold = "intruderThreshold"
}

/// Shared app state, injected into the SwiftUI environment.
@Observable
final class AppModel {
    let session = LockSession()
    let credentials = CredentialStore()
    let vault = SecureMediaStore(folderName: "Vault")
    let intruders = SecureMediaStore(folderName: "Intruders")
}
