import SwiftUI

/// Rounded surface used for grouped content.
struct Card<Content: View>: View {
    private let content: Content

    init(@ViewBuilder content: () -> Content) {
        self.content = content()
    }

    var body: some View {
        content
            .padding(16)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(
                Color(.secondarySystemGroupedBackground),
                in: RoundedRectangle(cornerRadius: 20, style: .continuous)
            )
    }
}

/// SF Symbol on a soft tinted square.
struct IconBadge: View {
    let systemName: String
    var color: Color = .accentColor
    var size: CGFloat = 36

    var body: some View {
        Image(systemName: systemName)
            .font(.system(size: size * 0.44, weight: .semibold))
            .foregroundStyle(color)
            .frame(width: size, height: size)
            .background(
                color.opacity(0.14),
                in: RoundedRectangle(cornerRadius: size * 0.3, style: .continuous)
            )
            .accessibilityHidden(true)
    }
}

/// Icon plus title used inside Form rows.
struct SettingsRowLabel: View {
    let icon: String
    let title: Text
    var color: Color = .accentColor

    var body: some View {
        HStack(spacing: 12) {
            IconBadge(systemName: icon, color: color, size: 30)
            title
        }
    }
}

/// Lays out children vertically, or side by side when height is compact
/// (iPhone in landscape).
struct AdaptiveStack<Content: View>: View {
    @Environment(\.verticalSizeClass) private var verticalSizeClass
    private let spacing: CGFloat
    private let content: Content

    init(spacing: CGFloat = 28, @ViewBuilder content: () -> Content) {
        self.spacing = spacing
        self.content = content()
    }

    var body: some View {
        let layout = verticalSizeClass == .compact
            ? AnyLayout(HStackLayout(spacing: spacing * 1.6))
            : AnyLayout(VStackLayout(spacing: spacing))
        layout { content }
    }
}

/// Key size for the PIN pad and pattern grid, based on the available space.
enum KeypadMetrics {
    static func keySize(for size: CGSize, compact: Bool) -> CGFloat {
        if compact {
            return min(62, max(44, (size.height - 24) / 5))
        }
        let byHeight = (size.height - 300) / 5
        let byWidth = (size.width - 40) / 4
        return min(80, max(52, min(byHeight, byWidth)))
    }
}

/// Horizontal shake used for wrong passcodes.
struct ShakeEffect: GeometryEffect {
    var animatableData: CGFloat

    func effectValue(size: CGSize) -> ProjectionTransform {
        ProjectionTransform(CGAffineTransform(translationX: 10 * sin(animatableData * .pi * 4), y: 0))
    }
}

/// The five features that are usually paid in other lock apps.
struct FreeFeature: Identifiable {
    let icon: String
    let title: LocalizedStringKey
    let subtitle: LocalizedStringKey

    var id: String { icon }

    static let all: [FreeFeature] = [
        FreeFeature(
            icon: "faceid",
            title: "Face ID and Touch ID",
            subtitle: "Unlock instantly without typing."
        ),
        FreeFeature(
            icon: "eye.trianglebadge.exclamationmark",
            title: "Intruder photos",
            subtitle: "See who tried to guess your passcode."
        ),
        FreeFeature(
            icon: "lock.rectangle.stack",
            title: "Private photo vault",
            subtitle: "Hide photos with AES-256 encryption."
        ),
        FreeFeature(
            icon: "calendar.badge.clock",
            title: "Scheduled locking",
            subtitle: "Lock apps automatically at set hours."
        ),
        FreeFeature(
            icon: "timer",
            title: "Smart relock",
            subtitle: "Unlock an app for a few minutes, then it locks itself."
        )
    ]
}

struct FeatureRow: View {
    let feature: FreeFeature

    var body: some View {
        HStack(alignment: .top, spacing: 14) {
            IconBadge(systemName: feature.icon, size: 40)
            VStack(alignment: .leading, spacing: 3) {
                Text(feature.title)
                    .font(.headline)
                Text(feature.subtitle)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .fixedSize(horizontal: false, vertical: true)
            }
            Spacer(minLength: 0)
        }
    }
}

extension View {
    /// Keeps forms and cards readable on iPad and in landscape.
    func readableWidth(_ maxWidth: CGFloat = 560) -> some View {
        frame(maxWidth: maxWidth).frame(maxWidth: .infinity)
    }
}
