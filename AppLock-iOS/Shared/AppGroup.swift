import Foundation

/// The App Group shared by the app and its Screen Time extensions.
/// The identifier comes from the `AppGroupIdentifier` Info.plist key, which is
/// derived from the `BASE_BUNDLE_ID` build setting.
enum AppGroup {
    static let identifier: String = {
        if let value = Bundle.main.object(forInfoDictionaryKey: "AppGroupIdentifier") as? String,
           !value.isEmpty {
            return value
        }
        return "group.com.example.applock"
    }()

    static var defaults: UserDefaults {
        UserDefaults(suiteName: identifier) ?? .standard
    }
}
