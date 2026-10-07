import SwiftUI

struct RootView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.scenePhase) private var scenePhase
    @AppStorage(SettingsKey.hasCompletedOnboarding) private var hasCompletedOnboarding = false

    var body: some View {
        ZStack {
            if !hasCompletedOnboarding || !model.credentials.hasCredential {
                OnboardingView {
                    hasCompletedOnboarding = true
                    model.session.unlock()
                }
                .transition(.opacity)
            } else if model.session.isUnlocked {
                MainTabView()
                    .transition(.opacity)
            } else {
                LockScreenView()
                    .transition(.opacity)
            }

            // Hides content in the app switcher while AppLock is unlocked.
            if scenePhase != .active && model.session.isUnlocked {
                PrivacyCover()
            }
        }
        .animation(.easeInOut(duration: 0.25), value: model.session.isUnlocked)
        .onChange(of: scenePhase) { _, phase in
            switch phase {
            case .background:
                if hasCompletedOnboarding {
                    model.session.lock()
                }
            case .active:
                ShieldController.applyCurrentPolicy()
            default:
                break
            }
        }
    }
}

private struct PrivacyCover: View {
    var body: some View {
        ZStack {
            Rectangle().fill(.ultraThickMaterial).ignoresSafeArea()
            IconBadge(systemName: "lock.fill", size: 72)
        }
    }
}
