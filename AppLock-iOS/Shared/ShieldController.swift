import Foundation
import ManagedSettings
import FamilyControls
import DeviceActivity

extension DeviceActivityName {
    static let relock = DeviceActivityName("applock.relock")

    static func schedule(weekday: Int) -> DeviceActivityName {
        DeviceActivityName("applock.schedule.\(weekday)")
    }

    var isSchedule: Bool { rawValue.hasPrefix("applock.schedule") }
}

/// Applies and removes Screen Time shields. Used by the app and by the
/// DeviceActivity monitor extension, so it only relies on shared settings.
enum ShieldController {
    private static let store = ManagedSettingsStore(named: ManagedSettingsStore.Name("applock"))

    /// Shields the selected items according to the current settings.
    /// - Parameter scheduleActive: pass a value when the caller already knows
    ///   whether the schedule window is active (the monitor extension does).
    static func applyCurrentPolicy(scheduleActive: Bool? = nil, now: Date = Date()) {
        let settings = SharedSettings.shared

        guard settings.protectionEnabled else {
            clearShields()
            return
        }
        if settings.scheduleEnabled {
            let active = scheduleActive ?? settings.schedule.contains(now)
            guard active else {
                clearShields()
                return
            }
        }

        let selection = settings.selection
        var applications = selection.applicationTokens
        let categories = selection.categoryTokens
        var webDomains = selection.webDomainTokens
        var exceptApplications = Set<ApplicationToken>()
        var exceptWebDomains = Set<WebDomainToken>()
        var unlockedCategories = Set<ActivityCategoryToken>()

        if let unlock = settings.temporaryUnlock, unlock.expiresAt > now {
            if unlock.everything {
                clearShields()
                return
            }
            applications.subtract(unlock.applications)
            webDomains.subtract(unlock.webDomains)
            exceptApplications = unlock.applications
            exceptWebDomains = unlock.webDomains
            unlockedCategories = unlock.categories
        }

        let lockedCategories = categories.subtracting(unlockedCategories)

        store.shield.applications = applications.isEmpty ? nil : applications
        store.shield.webDomains = webDomains.isEmpty ? nil : webDomains
        if lockedCategories.isEmpty {
            store.shield.applicationCategories = nil
            store.shield.webDomainCategories = nil
        } else {
            store.shield.applicationCategories = .specific(lockedCategories, except: exceptApplications)
            store.shield.webDomainCategories = .specific(lockedCategories, except: exceptWebDomains)
        }
    }

    static func clearShields() {
        store.shield.applications = nil
        store.shield.applicationCategories = nil
        store.shield.webDomains = nil
        store.shield.webDomainCategories = nil
    }

    /// Ends every temporary unlock and locks all selected items again.
    /// Clears an expired temporary unlock and shields everything again.
    /// Called by the monitor extension when the relock interval starts.
    static func relockExpiredItems(now: Date = Date()) {
        let settings = SharedSettings.shared
        // A newer unlock may have replaced the one this timer was set for.
        if let unlock = settings.temporaryUnlock, unlock.expiresAt > now.addingTimeInterval(60) {
            return
        }
        settings.temporaryUnlock = nil
        applyCurrentPolicy(now: now)
    }

    static func lockEverythingNow() {
        SharedSettings.shared.temporaryUnlock = nil
        DeviceActivityCenter().stopMonitoring([.relock])
        applyCurrentPolicy()
    }

    /// Unlocks the requested item (or everything when `request` is nil) and
    /// schedules it to lock again after `minutes`.
    static func temporarilyUnlock(_ request: UnlockRequest?, minutes: Int) {
        let settings = SharedSettings.shared
        let expiry = Date().addingTimeInterval(TimeInterval(max(1, minutes) * 60))

        var unlock: TemporaryUnlock
        if let current = settings.temporaryUnlock, current.isActive {
            unlock = current
        } else {
            unlock = TemporaryUnlock(expiresAt: expiry)
        }
        unlock.expiresAt = expiry

        if let request {
            if let application = request.application { unlock.applications.insert(application) }
            if let category = request.category { unlock.categories.insert(category) }
            if let webDomain = request.webDomain { unlock.webDomains.insert(webDomain) }
        } else {
            unlock.everything = true
        }

        settings.temporaryUnlock = unlock
        settings.unlockRequest = nil
        applyCurrentPolicy()
        scheduleRelock(at: expiry)
    }

    /// DeviceActivity intervals must be at least 15 minutes long, so the
    /// interval starts at the relock time and the monitor extension
    /// relocks in `intervalDidStart`.
    static func scheduleRelock(at relockDate: Date) {
        let center = DeviceActivityCenter()
        center.stopMonitoring([.relock])

        let start = relockDate
        let end = relockDate.addingTimeInterval(16 * 60)
        let calendar = Calendar.current
        let components: Set<Calendar.Component> = [.year, .month, .day, .hour, .minute, .second]
        let schedule = DeviceActivitySchedule(
            intervalStart: calendar.dateComponents(components, from: start),
            intervalEnd: calendar.dateComponents(components, from: end),
            repeats: false
        )
        do {
            try center.startMonitoring(.relock, during: schedule)
        } catch {
            // The app also relocks expired items whenever it becomes active.
            print("Relock scheduling failed: \(error)")
        }
    }

    /// Registers one repeating DeviceActivity schedule per selected weekday.
    static func updateScheduleMonitoring() {
        let center = DeviceActivityCenter()
        center.stopMonitoring((1...7).map { DeviceActivityName.schedule(weekday: $0) })

        let settings = SharedSettings.shared
        let schedule = settings.schedule
        guard settings.protectionEnabled, settings.scheduleEnabled, schedule.isValid else { return }

        for weekday in schedule.weekdays {
            let endWeekday = schedule.isOvernight ? (weekday % 7) + 1 : weekday
            let activitySchedule = DeviceActivitySchedule(
                intervalStart: DateComponents(hour: schedule.startHour, minute: schedule.startMinute, weekday: weekday),
                intervalEnd: DateComponents(hour: schedule.endHour, minute: schedule.endMinute, weekday: endWeekday),
                repeats: true
            )
            do {
                try center.startMonitoring(.schedule(weekday: weekday), during: activitySchedule)
            } catch {
                print("Schedule monitoring failed for weekday \(weekday): \(error)")
            }
        }
    }
}
