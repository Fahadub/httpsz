import ManagedSettings
import ManagedSettingsUI
import UIKit

/// Draws the screen iOS shows on top of a locked app or website.
final class ShieldConfigurationExtension: ShieldConfigurationDataSource {
    override func configuration(shielding application: Application) -> ShieldConfiguration {
        makeConfiguration(name: application.localizedDisplayName)
    }

    override func configuration(shielding application: Application, in category: ActivityCategory) -> ShieldConfiguration {
        makeConfiguration(name: application.localizedDisplayName)
    }

    override func configuration(shielding webDomain: WebDomain) -> ShieldConfiguration {
        makeConfiguration(name: webDomain.domain)
    }

    override func configuration(shielding webDomain: WebDomain, in category: ActivityCategory) -> ShieldConfiguration {
        makeConfiguration(name: webDomain.domain)
    }

    private func makeConfiguration(name: String?) -> ShieldConfiguration {
        let accent = UIColor { traits in
            traits.userInterfaceStyle == .dark
                ? UIColor(red: 0.49, green: 0.55, blue: 1.00, alpha: 1)
                : UIColor(red: 0.30, green: 0.36, blue: 0.83, alpha: 1)
        }

        let title: String
        if let name, !name.isEmpty {
            title = String(localized: "\(name) is locked")
        } else {
            title = String(localized: "This app is locked")
        }

        let requestPending = SharedSettings.shared.unlockRequest?.isRecent ?? false
        let subtitle = requestPending
            ? String(localized: "Tap the AppLock notification to verify it's you.")
            : String(localized: "Enter your passcode in AppLock to open it.")

        let icon = UIImage(
            systemName: requestPending ? "bell.badge.fill" : "lock.fill",
            withConfiguration: UIImage.SymbolConfiguration(pointSize: 46, weight: .semibold)
        )?.withTintColor(accent, renderingMode: .alwaysOriginal)

        return ShieldConfiguration(
            backgroundBlurStyle: .systemThickMaterial,
            backgroundColor: nil,
            icon: icon,
            title: ShieldConfiguration.Label(text: title, color: .label),
            subtitle: ShieldConfiguration.Label(text: subtitle, color: .secondaryLabel),
            primaryButtonLabel: ShieldConfiguration.Label(text: String(localized: "Unlock"), color: .white),
            primaryButtonBackgroundColor: accent,
            secondaryButtonLabel: ShieldConfiguration.Label(text: String(localized: "Close"), color: accent)
        )
    }
}
