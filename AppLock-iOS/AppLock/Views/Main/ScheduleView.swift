import SwiftUI

struct ScheduleView: View {
    @State private var enabled = SharedSettings.shared.scheduleEnabled
    @State private var schedule = SharedSettings.shared.schedule

    var body: some View {
        Form {
            Section {
                Toggle(isOn: $enabled) {
                    SettingsRowLabel(icon: "calendar.badge.clock", title: Text("Lock on a schedule"))
                }
            } footer: {
                Text("When this is off, your selected apps stay locked all the time.")
            }

            if enabled {
                Section("Hours") {
                    DatePicker("Starts", selection: timeBinding(hour: \.startHour, minute: \.startMinute), displayedComponents: .hourAndMinute)
                    DatePicker("Ends", selection: timeBinding(hour: \.endHour, minute: \.endMinute), displayedComponents: .hourAndMinute)
                }

                Section {
                    WeekdayPicker(selection: $schedule.weekdays)
                        .padding(.vertical, 4)
                } header: {
                    Text("Days")
                } footer: {
                    if schedule.isValid {
                        Text("Apps lock automatically at the start time and unlock at the end time, even when AppLock is closed.")
                    } else {
                        Text("Pick at least one day and a window of 15 minutes or more.")
                            .foregroundStyle(.red)
                    }
                }
            }
        }
        .navigationTitle("Schedule")
        .navigationBarTitleDisplayMode(.inline)
        .onChange(of: enabled) { _, _ in save() }
        .onChange(of: schedule) { _, _ in save() }
    }

    private func save() {
        let settings = SharedSettings.shared
        settings.schedule = schedule
        settings.scheduleEnabled = enabled && schedule.isValid
        ShieldController.updateScheduleMonitoring()
        ShieldController.applyCurrentPolicy()
    }

    private func timeBinding(hour: WritableKeyPath<LockSchedule, Int>, minute: WritableKeyPath<LockSchedule, Int>) -> Binding<Date> {
        Binding(
            get: {
                Calendar.current.date(
                    bySettingHour: schedule[keyPath: hour],
                    minute: schedule[keyPath: minute],
                    second: 0,
                    of: Date()
                ) ?? Date()
            },
            set: { newValue in
                let components = Calendar.current.dateComponents([.hour, .minute], from: newValue)
                schedule[keyPath: hour] = components.hour ?? 0
                schedule[keyPath: minute] = components.minute ?? 0
            }
        )
    }
}

/// Seven round toggles, ordered by the user's first weekday.
struct WeekdayPicker: View {
    @Binding var selection: Set<Int>

    var body: some View {
        let calendar = Calendar.current
        let symbols = calendar.veryShortStandaloneWeekdaySymbols
        let fullNames = calendar.standaloneWeekdaySymbols
        let order = (0..<7).map { (calendar.firstWeekday - 1 + $0) % 7 + 1 }

        HStack(spacing: 6) {
            ForEach(order, id: \.self) { day in
                let isOn = selection.contains(day)
                Button {
                    if isOn {
                        selection.remove(day)
                    } else {
                        selection.insert(day)
                    }
                } label: {
                    Text(verbatim: symbols[day - 1])
                        .font(.subheadline.weight(.semibold))
                        .frame(maxWidth: .infinity, minHeight: 38)
                        .background(
                            Circle().fill(isOn ? Color.accentColor : Color(.tertiarySystemFill))
                        )
                        .foregroundStyle(isOn ? Color.white : Color.primary)
                }
                .buttonStyle(.plain)
                .accessibilityLabel(Text(verbatim: fullNames[day - 1]))
                .accessibilityAddTraits(isOn ? .isSelected : [])
            }
        }
    }
}
