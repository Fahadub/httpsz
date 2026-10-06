import SwiftUI
import CryptoKit
import LocalAuthentication

struct LockedApp: Identifiable, Codable, Hashable {
    var id = UUID()
    var name: String
    var scheme: String   // مثال: whatsapp://
}

final class LockStore: ObservableObject {
    @Published var isLocked = true
    @Published var pendingApp: String?
    @Published var apps: [LockedApp] = [] { didSet { saveApps() } }
    @AppStorage("passHash") private var passHash = ""

    var hasPasscode: Bool { !passHash.isEmpty }

    init() {
        if let d = UserDefaults.standard.data(forKey: "apps"),
           let a = try? JSONDecoder().decode([LockedApp].self, from: d) {
            apps = a
        } else {
            apps = [LockedApp(name: "WhatsApp", scheme: "whatsapp://"),
                    LockedApp(name: "Instagram", scheme: "instagram://")]
        }
    }

    private func hash(_ s: String) -> String {
        SHA256.hash(data: Data(s.utf8)).map { String(format: "%02x", $0) }.joined()
    }

    func setPasscode(_ code: String) {
        passHash = hash(code)
        isLocked = false
        objectWillChange.send()
    }

    func check(_ code: String) -> Bool {
        guard hash(code) == passHash else { return false }
        unlocked()
        return true
    }

    func faceID() {
        let ctx = LAContext()
        guard ctx.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: nil) else { return }
        ctx.evaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, localizedReason: "افتح القفل") { ok, _ in
            if ok { DispatchQueue.main.async { self.unlocked() } }
        }
    }

    private func unlocked() {
        isLocked = false
        if let name = pendingApp,
           let app = apps.first(where: { $0.name.lowercased() == name.lowercased() }) {
            open(app)
        }
        pendingApp = nil
    }

    func open(_ app: LockedApp) {
        if let url = URL(string: app.scheme) { UIApplication.shared.open(url) }
    }

    private func saveApps() {
        if let d = try? JSONEncoder().encode(apps) { UserDefaults.standard.set(d, forKey: "apps") }
    }
}
