import DeviceActivity
import Foundation

/// Runs in the background when a schedule window starts or ends, or when a
/// temporary unlock expires, and updates the shields accordingly.
final class ActivityMonitorExtension: DeviceActivityMonitor {
    override func intervalDidStart(for activity: DeviceActivityName) {
        super.intervalDidStart(for: activity)
        if activity == .relock {
            ShieldController.relockExpiredItems()
        } else if activity.isSchedule {
            ShieldController.applyCurrentPolicy(scheduleActive: true)
        }
    }

    override func intervalDidEnd(for activity: DeviceActivityName) {
        super.intervalDidEnd(for: activity)
        if activity == .relock {
            ShieldController.relockExpiredItems()
        } else if activity.isSchedule {
            ShieldController.applyCurrentPolicy(scheduleActive: false)
        }
    }
}
