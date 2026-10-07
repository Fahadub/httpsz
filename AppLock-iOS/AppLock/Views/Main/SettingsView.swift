import SwiftUI
import UserNotifications

struct SettingsView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.openURL) private var openURL

    @AppStorage(SettingsKey.biometricsEnabled) private var biometricsEnabled = true
    @AppStorage(SettingsKey.intruderThreshold) private var intruderThreshold = 2

    @State private var showChangePasscode = false
    @State private var showPasscodeChanged = false
    @State private var notificationStatus: UNAuthorizationStatus = .notDetermined

    private var appVersion: String {
        let version = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "1.0"
        let build = Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String ?? "1"
        return "\(version) (\(build))"
    }

    var body: some View {
        NavigationStack {
            Form {
                Section("Security") {
                    Button {
                        showChangePasscode = true
                    } label: {
                        HStack {
                            SettingsRowLabel(icon: "key.fill", title: Text("Change passcode"))
                                .foregroundStyle(.primary)
                            Spacer()
                            if let kind = model.credentials.kind {
                                Text(kind.title)
                                    .foregroundStyle(.secondary)
                            }
                        }
                    }

                    if Biometrics.isAvailable {
                        Toggle(isOn: $biometricsEnabled) {
                            SettingsRowLabel(icon: Biometrics.symbolName, title: Text(verbatim: Biometrics.displayName))
                        }
                    }
                }

                Section {
                    Picker(selection: $intruderThreshold) {
                        ForEach([1, 2, 3, 5], id: \.self) { value in
                            Text("\(value)").tag(value)
                        }
                    } label: {
                        SettingsRowLabel(icon: "camera.fill", title: Text("Photo after wrong attempts"))
                    }
                } header: {
                    Text("Intruder photos")
                } footer: {
                    Text("Turn intruder photos on or off from the Intruders tab.")
                }

                Section {
                    if notificationStatus == .authorized || notificationStatus == .provisional {
                        HStack {
                            SettingsRowLabel(icon: "bell.badge", title: Text("Notifications"))
                            Spacer()
                            Text("On")
                                .foregroundStyle(.secondary)
                        }
                    } else {
                        Button {
                            Task { await enableNotifications() }
                        } label: {
                            SettingsRowLabel(icon: "bell.badge", title: Text("Turn on notifications"))
                        }
                    }
                } footer: {
                    Text("When you tap Unlock on a locked app, a notification takes you here to verify it's you.")
                }

                Section {
                    ForEach(FreeFeature.all) { feature in
                        FeatureRow(feature: feature)
                            .padding(.vertical, 4)
                    }
                } header: {
                    Text("Included for free")
                }

                Section {
                    HStack {
                        SettingsRowLabel(icon: "info.circle", title: Text("Version"), color: .secondary)
                        Spacer()
                        Text(verbatim: appVersion)
                            .foregroundStyle(.secondary)
                    }
                }
            }
            .navigationTitle("Settings")
            .task { await refreshNotificationStatus() }
            .sheet(isPresented: $showChangePasscode) {
                NavigationStack {
                    CredentialSetupView { secret, kind in
                        model.credentials.save(secret: secret, kind: kind)
                        showChangePasscode = false
                        showPasscodeChanged = true
                    }
                    .padding()
                    .navigationTitle("Change passcode")
                    .navigationBarTitleDisplayMode(.inline)
                    .toolbar {
                        ToolbarItem(placement: .cancellationAction) {
                            Button("Cancel") { showChangePasscode = false }
                        }
                    }
                }
            }
            .alert("Passcode changed", isPresented: $showPasscodeChanged) {
                Button("OK", role: .cancel) {}
            }
        }
    }

    private func refreshNotificationStatus() async {
        notificationStatus = await UNUserNotificationCenter.current().notificationSettings().authorizationStatus
    }

    private func enableNotifications() async {
        if notificationStatus == .denied {
            if let url = URL(string: UIApplication.openSettingsURLString) {
                openURL(url)
            }
            return
        }
        _ = try? await UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .sound])
        await refreshNotificationStatus()
    }
}
