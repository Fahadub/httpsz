import FamilyControls
import ManagedSettings
import SwiftUI

struct ProtectionView: View {
    @ObservedObject private var authorization = AuthorizationCenter.shared

    @State private var selection = FamilyActivitySelection()
    @State private var protectionEnabled = false
    @State private var relockMinutes = 5
    @State private var scheduleEnabled = false
    @State private var schedule = LockSchedule()
    @State private var temporaryUnlock: TemporaryUnlock?
    @State private var showPicker = false
    @State private var authorizationError: String?

    private let relockOptions = [1, 5, 15, 30, 60]

    private var isAuthorized: Bool { authorization.authorizationStatus == .approved }

    private var lockedCount: Int {
        selection.applicationTokens.count + selection.categoryTokens.count + selection.webDomainTokens.count
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    if !isAuthorized {
                        authorizationCard
                    }
                    statusCard
                    lockedAppsCard
                    timingCard
                    if let temporaryUnlock, temporaryUnlock.isActive {
                        temporaryUnlockCard(temporaryUnlock)
                    }
                }
                .padding(16)
                .readableWidth(640)
            }
            .background(Color(.systemGroupedBackground))
            .navigationTitle("AppLock")
            .familyActivityPicker(isPresented: $showPicker, selection: $selection)
            .onAppear(perform: reload)
            .onChange(of: selection) { _, newValue in
                let settings = SharedSettings.shared
                guard newValue != settings.selection else { return }
                settings.selection = newValue
                if !protectionEnabled && lockedCount > 0 {
                    // Turning protection on also applies the new selection.
                    protectionEnabled = true
                } else {
                    applyChanges()
                }
            }
            .onChange(of: protectionEnabled) { _, newValue in
                let settings = SharedSettings.shared
                guard newValue != settings.protectionEnabled else { return }
                settings.protectionEnabled = newValue
                applyChanges()
            }
            .onChange(of: relockMinutes) { _, newValue in
                SharedSettings.shared.relockMinutes = newValue
            }
        }
    }

    // MARK: - Cards

    private var authorizationCard: some View {
        Card {
            VStack(alignment: .leading, spacing: 12) {
                HStack(spacing: 12) {
                    IconBadge(systemName: "hourglass", color: .orange, size: 40)
                    VStack(alignment: .leading, spacing: 2) {
                        Text("Screen Time access needed")
                            .font(.headline)
                        Text("AppLock uses Screen Time to lock apps. Nothing leaves your device.")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                }
                Button {
                    Task {
                        do {
                            try await AuthorizationCenter.shared.requestAuthorization(for: .individual)
                            authorizationError = nil
                        } catch {
                            authorizationError = error.localizedDescription
                        }
                    }
                } label: {
                    Text("Allow access")
                        .frame(maxWidth: .infinity)
                }
                .buttonStyle(.borderedProminent)
                .tint(.orange)

                if let authorizationError {
                    Text(authorizationError)
                        .font(.footnote)
                        .foregroundStyle(.red)
                }
            }
        }
    }

    private var statusCard: some View {
        let isOn = protectionEnabled && lockedCount > 0
        let title: LocalizedStringKey = isOn ? "Protection is on" : "Protection is off"

        return Card {
            HStack(spacing: 14) {
                IconBadge(
                    systemName: isOn ? "lock.shield.fill" : "shield.slash",
                    color: isOn ? .accentColor : .secondary,
                    size: 52
                )
                VStack(alignment: .leading, spacing: 3) {
                    Text(title)
                        .font(.headline)
                    Group {
                        if lockedCount == 0 {
                            Text("Choose the apps you want to lock.")
                        } else if scheduleEnabled {
                            Text("Locked items: \(lockedCount), on a schedule")
                        } else {
                            Text("Locked items: \(lockedCount)")
                        }
                    }
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                }
                Spacer(minLength: 8)
                Toggle("Protection", isOn: $protectionEnabled)
                    .labelsHidden()
                    .disabled(!isAuthorized || lockedCount == 0)
            }
        }
    }

    private var lockedAppsCard: some View {
        Card {
            VStack(alignment: .leading, spacing: 14) {
                HStack {
                    Text("Locked apps")
                        .font(.headline)
                    Spacer()
                    Button {
                        showPicker = true
                    } label: {
                        Text(lockedCount == 0 ? LocalizedStringKey("Choose") : LocalizedStringKey("Edit"))
                    }
                    .disabled(!isAuthorized)
                }

                if lockedCount == 0 {
                    HStack(spacing: 12) {
                        Image(systemName: "apps.iphone")
                            .font(.title2)
                            .foregroundStyle(.secondary)
                        Text("No apps selected yet.")
                            .foregroundStyle(.secondary)
                    }
                    .padding(.vertical, 6)
                } else {
                    VStack(alignment: .leading, spacing: 10) {
                        ForEach(Array(selection.applicationTokens.prefix(8)), id: \.self) { token in
                            Label(token)
                        }
                        ForEach(Array(selection.categoryTokens.prefix(4)), id: \.self) { token in
                            Label(token)
                        }
                        ForEach(Array(selection.webDomainTokens.prefix(4)), id: \.self) { token in
                            Label(token)
                        }
                        let shown = min(8, selection.applicationTokens.count)
                            + min(4, selection.categoryTokens.count)
                            + min(4, selection.webDomainTokens.count)
                        if lockedCount > shown {
                            Text("And \(lockedCount - shown) more")
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                        }
                    }
                }
            }
        }
    }

    private var timingCard: some View {
        Card {
            VStack(spacing: 0) {
                HStack(spacing: 12) {
                    IconBadge(systemName: "timer", size: 34)
                    VStack(alignment: .leading, spacing: 2) {
                        Text("Relock after")
                        Text("After you unlock an app")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    Spacer()
                    Picker("Relock after", selection: $relockMinutes) {
                        ForEach(relockOptions, id: \.self) { minutes in
                            Text("\(minutes) min").tag(minutes)
                        }
                    }
                    .labelsHidden()
                    .pickerStyle(.menu)
                }
                .padding(.bottom, 12)

                Divider().padding(.leading, 46)

                NavigationLink {
                    ScheduleView()
                } label: {
                    HStack(spacing: 12) {
                        IconBadge(systemName: "calendar.badge.clock", size: 34)
                        Text("Schedule")
                            .foregroundStyle(.primary)
                        Spacer()
                        scheduleSummary
                            .foregroundStyle(.secondary)
                        Image(systemName: "chevron.forward")
                            .font(.footnote.weight(.semibold))
                            .foregroundStyle(.tertiary)
                    }
                    .padding(.top, 12)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
        }
    }

    @ViewBuilder
    private var scheduleSummary: some View {
        if scheduleEnabled {
            Text(verbatim: "\(Self.timeString(schedule.startHour, schedule.startMinute)) – \(Self.timeString(schedule.endHour, schedule.endMinute))")
        } else {
            Text("Always")
        }
    }

    private func temporaryUnlockCard(_ unlock: TemporaryUnlock) -> some View {
        Card {
            HStack(spacing: 14) {
                IconBadge(systemName: "lock.open.fill", color: .green, size: 40)
                VStack(alignment: .leading, spacing: 2) {
                    Text("Temporarily unlocked")
                        .font(.headline)
                    Text("Until \(unlock.expiresAt.formatted(date: .omitted, time: .shortened))")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
                Spacer(minLength: 8)
                Button("Lock now") {
                    ShieldController.lockEverythingNow()
                    reload()
                }
                .buttonStyle(.borderedProminent)
                .buttonBorderShape(.capsule)
            }
        }
    }

    // MARK: - Helpers

    private func reload() {
        let settings = SharedSettings.shared
        selection = settings.selection
        protectionEnabled = settings.protectionEnabled
        relockMinutes = settings.relockMinutes
        scheduleEnabled = settings.scheduleEnabled
        schedule = settings.schedule
        temporaryUnlock = settings.temporaryUnlock
    }

    private func applyChanges() {
        ShieldController.updateScheduleMonitoring()
        ShieldController.applyCurrentPolicy()
    }

    static func timeString(_ hour: Int, _ minute: Int) -> String {
        let date = Calendar.current.date(bySettingHour: hour, minute: minute, second: 0, of: Date()) ?? Date()
        return date.formatted(date: .omitted, time: .shortened)
    }
}
