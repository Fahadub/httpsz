import FamilyControls
import SwiftUI

struct MainTabView: View {
    @Environment(AppModel.self) private var model

    var body: some View {
        @Bindable var session = model.session

        TabView(selection: $session.selectedTab) {
            ProtectionView()
                .tabItem { Label("Apps", systemImage: "lock.shield") }
                .tag(MainTab.protection)

            VaultView()
                .tabItem { Label("Vault", systemImage: "lock.rectangle.stack") }
                .tag(MainTab.vault)

            IntruderLogView()
                .tabItem { Label("Intruders", systemImage: "eye.trianglebadge.exclamationmark") }
                .tag(MainTab.intruders)

            SettingsView()
                .tabItem { Label("Settings", systemImage: "gearshape") }
                .tag(MainTab.settings)
        }
        .sheet(item: $session.unlockedRequest) { request in
            UnlockedSheet(request: request)
                .presentationDetents([.medium])
                .presentationDragIndicator(.visible)
        }
    }
}

/// Confirms that an app was unlocked after the user came from its lock screen.
private struct UnlockedSheet: View {
    let request: UnlockRequest
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        VStack(spacing: 18) {
            IconBadge(systemName: "lock.open.fill", color: .green, size: 64)
                .padding(.top, 24)

            Text("Unlocked")
                .font(.title2.bold())

            Group {
                if let application = request.application {
                    Label(application)
                } else if let category = request.category {
                    Label(category)
                } else if let webDomain = request.webDomain {
                    Label(webDomain)
                }
            }
            .font(.headline)

            Text("Open it from your Home Screen. It locks again in \(SharedSettings.shared.relockMinutes) min.")
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)

            Spacer(minLength: 0)

            VStack(spacing: 10) {
                Button {
                    dismiss()
                } label: {
                    Text("Done")
                        .font(.headline)
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.borderedProminent)
                .controlSize(.large)

                Button("Lock it again now", role: .destructive) {
                    ShieldController.lockEverythingNow()
                    dismiss()
                }
            }
        }
        .padding(24)
        .readableWidth(480)
    }
}
