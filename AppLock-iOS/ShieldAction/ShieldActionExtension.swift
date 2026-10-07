import Foundation
import ManagedSettings
import UserNotifications

/// Handles the buttons on the lock screen shown over a locked app.
/// "Unlock" stores a request and posts a notification that opens AppLock,
/// where the user verifies with their passcode, pattern or Face ID.
final class ShieldActionExtension: ShieldActionDelegate {
    override func handle(action: ShieldAction, for application: ApplicationToken, completionHandler: @escaping (ShieldActionResponse) -> Void) {
        handle(action, request: UnlockRequest(application: application), completionHandler: completionHandler)
    }

    override func handle(action: ShieldAction, for webDomain: WebDomainToken, completionHandler: @escaping (ShieldActionResponse) -> Void) {
        handle(action, request: UnlockRequest(webDomain: webDomain), completionHandler: completionHandler)
    }

    override func handle(action: ShieldAction, for category: ActivityCategoryToken, completionHandler: @escaping (ShieldActionResponse) -> Void) {
        handle(action, request: UnlockRequest(category: category), completionHandler: completionHandler)
    }

    private func handle(_ action: ShieldAction, request: UnlockRequest, completionHandler: @escaping (ShieldActionResponse) -> Void) {
        switch action {
        case .primaryButtonPressed:
            SharedSettings.shared.unlockRequest = request
            postUnlockNotification {
                // `.defer` keeps the shield up and refreshes its text.
                completionHandler(.defer)
            }
        case .secondaryButtonPressed:
            completionHandler(.close)
        @unknown default:
            completionHandler(.close)
        }
    }

    private func postUnlockNotification(completion: @escaping () -> Void) {
        let content = UNMutableNotificationContent()
        content.title = String(localized: "Unlock requested")
        content.body = String(localized: "Tap to verify it's you.")
        content.sound = .default

        let request = UNNotificationRequest(identifier: "applock.unlock", content: content, trigger: nil)
        UNUserNotificationCenter.current().add(request) { _ in
            completion()
        }
    }
}
