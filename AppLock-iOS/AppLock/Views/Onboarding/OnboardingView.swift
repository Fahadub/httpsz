import FamilyControls
import SwiftUI
import UserNotifications

struct OnboardingView: View {
    var onFinish: () -> Void

    @Environment(AppModel.self) private var model
    @State private var step: Step = .welcome

    private enum Step {
        case welcome
        case passcode
        case permissions
    }

    var body: some View {
        ZStack {
            Color(.systemGroupedBackground).ignoresSafeArea()

            switch step {
            case .welcome:
                WelcomeStep {
                    withAnimation { step = .passcode }
                }
                .transition(.move(edge: .trailing).combined(with: .opacity))
            case .passcode:
                CredentialSetupView { secret, kind in
                    model.credentials.save(secret: secret, kind: kind)
                    withAnimation { step = .permissions }
                }
                .padding()
                .transition(.move(edge: .trailing).combined(with: .opacity))
            case .permissions:
                PermissionsStep(onFinish: onFinish)
                    .transition(.move(edge: .trailing).combined(with: .opacity))
            }
        }
    }
}

private struct WelcomeStep: View {
    var onContinue: () -> Void

    var body: some View {
        ScrollView {
            VStack(spacing: 28) {
                VStack(spacing: 14) {
                    IconBadge(systemName: "lock.shield.fill", size: 88)
                    Text("AppLock")
                        .font(.largeTitle.bold())
                    Text("Lock any app with a PIN or a swipe pattern. Every feature is free.")
                        .font(.body)
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                }
                .padding(.top, 40)

                Card {
                    VStack(alignment: .leading, spacing: 18) {
                        ForEach(FreeFeature.all) { feature in
                            FeatureRow(feature: feature)
                        }
                    }
                }

                Button(action: onContinue) {
                    Text("Get Started")
                        .font(.headline)
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.borderedProminent)
                .controlSize(.large)
                .buttonBorderShape(.roundedRectangle(radius: 16))
            }
            .padding(20)
            .readableWidth()
        }
    }
}

private struct PermissionsStep: View {
    var onFinish: () -> Void

    @ObservedObject private var authorization = AuthorizationCenter.shared
    @AppStorage(SettingsKey.biometricsEnabled) private var biometricsEnabled = true
    @State private var notificationsGranted = false
    @State private var errorText: String?

    var body: some View {
        ScrollView {
            VStack(spacing: 16) {
                VStack(spacing: 10) {
                    IconBadge(systemName: "checkmark.shield.fill", size: 72)
                    Text("Almost done")
                        .font(.title.bold())
                    Text("AppLock needs a few permissions to protect your apps.")
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                }
                .padding(.top, 32)
                .padding(.bottom, 8)

                PermissionCard(
                    icon: "hourglass",
                    title: "Screen Time",
                    subtitle: "Required to lock other apps. Nothing leaves your device.",
                    granted: authorization.authorizationStatus == .approved
                ) {
                    Task { await requestScreenTime() }
                }

                PermissionCard(
                    icon: "bell.badge",
                    title: "Notifications",
                    subtitle: "Lets you unlock a locked app from its lock screen.",
                    granted: notificationsGranted
                ) {
                    Task { await requestNotifications() }
                }

                if Biometrics.isAvailable {
                    Card {
                        Toggle(isOn: $biometricsEnabled) {
                            HStack(spacing: 14) {
                                IconBadge(systemName: Biometrics.symbolName, size: 44)
                                VStack(alignment: .leading, spacing: 3) {
                                    Text(verbatim: Biometrics.displayName)
                                        .font(.headline)
                                    Text("Unlock instantly without typing.")
                                        .font(.subheadline)
                                        .foregroundStyle(.secondary)
                                }
                            }
                        }
                    }
                }

                if let errorText {
                    Text(errorText)
                        .font(.footnote)
                        .foregroundStyle(.red)
                        .multilineTextAlignment(.center)
                }

                Button(action: onFinish) {
                    Text("Continue")
                        .font(.headline)
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.borderedProminent)
                .controlSize(.large)
                .buttonBorderShape(.roundedRectangle(radius: 16))
                .padding(.top, 8)
            }
            .padding(20)
            .readableWidth()
        }
        .task {
            let settings = await UNUserNotificationCenter.current().notificationSettings()
            notificationsGranted = settings.authorizationStatus == .authorized
        }
    }

    private func requestScreenTime() async {
        do {
            try await AuthorizationCenter.shared.requestAuthorization(for: .individual)
            errorText = nil
        } catch {
            errorText = error.localizedDescription
        }
    }

    private func requestNotifications() async {
        let granted = (try? await UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .sound])) ?? false
        notificationsGranted = granted
    }
}

struct PermissionCard: View {
    let icon: String
    let title: LocalizedStringKey
    let subtitle: LocalizedStringKey
    let granted: Bool
    let action: () -> Void

    var body: some View {
        Card {
            HStack(spacing: 14) {
                IconBadge(systemName: icon, size: 44)
                VStack(alignment: .leading, spacing: 3) {
                    Text(title)
                        .font(.headline)
                    Text(subtitle)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .fixedSize(horizontal: false, vertical: true)
                }
                Spacer(minLength: 8)
                if granted {
                    Image(systemName: "checkmark.circle.fill")
                        .font(.title2)
                        .foregroundStyle(.green)
                        .accessibilityLabel(Text("Allowed"))
                } else {
                    Button("Allow", action: action)
                        .buttonStyle(.bordered)
                        .buttonBorderShape(.capsule)
                }
            }
        }
    }
}
