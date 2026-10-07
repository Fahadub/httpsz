import Foundation
import ManagedSettings

/// Daily hours during which the selected apps are locked.
struct LockSchedule: Codable, Equatable {
    var startHour = 22
    var startMinute = 0
    var endHour = 7
    var endMinute = 0
    /// Calendar weekdays, 1 = Sunday ... 7 = Saturday.
    var weekdays: Set<Int> = Set(1...7)

    var startMinutes: Int { startHour * 60 + startMinute }
    var endMinutes: Int { endHour * 60 + endMinute }

    /// True when the window crosses midnight (for example 22:00 to 07:00).
    var isOvernight: Bool { endMinutes <= startMinutes }

    var durationMinutes: Int {
        isOvernight ? (24 * 60 - startMinutes + endMinutes) : (endMinutes - startMinutes)
    }

    /// DeviceActivity requires every monitored interval to be at least 15 minutes long.
    var isValid: Bool { durationMinutes >= 15 && !weekdays.isEmpty }

    func contains(_ date: Date, calendar: Calendar = .current) -> Bool {
        let components = calendar.dateComponents([.weekday, .hour, .minute], from: date)
        guard let weekday = components.weekday,
              let hour = components.hour,
              let minute = components.minute else { return false }
        let now = hour * 60 + minute

        if !isOvernight {
            return weekdays.contains(weekday) && now >= startMinutes && now < endMinutes
        }
        let previousDay = weekday == 1 ? 7 : weekday - 1
        return (weekdays.contains(weekday) && now >= startMinutes)
            || (weekdays.contains(previousDay) && now < endMinutes)
    }
}

/// Items that the user unlocked for a limited time.
struct TemporaryUnlock: Codable {
    var applications: Set<ApplicationToken> = []
    var categories: Set<ActivityCategoryToken> = []
    var webDomains: Set<WebDomainToken> = []
    var everything = false
    var expiresAt: Date

    var isActive: Bool { expiresAt > Date() }
}

/// Written by the shield action extension when the user taps "Unlock" on a
/// locked app, then consumed by the main app after the user authenticates.
struct UnlockRequest: Codable, Identifiable {
    var application: ApplicationToken? = nil
    var category: ActivityCategoryToken? = nil
    var webDomain: WebDomainToken? = nil
    var createdAt = Date()

    var id: Date { createdAt }

    /// Requests older than five minutes are ignored.
    var isRecent: Bool { Date().timeIntervalSince(createdAt) < 300 }
}
