import SwiftUI

@main
struct AppLockApp: App {
    @StateObject private var store = LockStore()
    @Environment(\.scenePhase) private var phase

    var body: some Scene {
        WindowGroup {
            Group {
                if !store.hasPasscode {
                    SetPasscodeView()
                } else if store.isLocked {
                    UnlockView()
                } else {
                    AppsListView()
                }
            }
            .environmentObject(store)
            .environment(\.layoutDirection, .rightToLeft)
            // applock://open?app=WhatsApp  — يُستدعى من الاختصارات
            .onOpenURL { url in
                let items = URLComponents(url: url, resolvingAgainstBaseURL: false)?.queryItems
                store.pendingApp = items?.first(where: { $0.name == "app" })?.value
                store.isLocked = true
            }
        }
        .onChange(of: phase) { p in
            if p == .background { store.isLocked = true }
        }
    }
}
