import SwiftUI

/// Shown whenever AppLock opens. Accepts the PIN or pattern, or biometrics.
struct LockScreenView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.verticalSizeClass) private var verticalSizeClass

    @AppStorage(SettingsKey.biometricsEnabled) private var biometricsEnabled = true
    @AppStorage(SettingsKey.intruderEnabled) private var intruderEnabled = false
    @AppStorage(SettingsKey.intruderThreshold) private var intruderThreshold = 2

    @State private var pin = ""
    @State private var shakes = 0
    @State private var patternError = false
    @State private var lockoutRemaining = 0
    @State private var didAutoPrompt = false

    private var kind: CredentialKind { model.credentials.kind ?? .pin6 }
    private var canUseBiometrics: Bool { biometricsEnabled && Biometrics.isAvailable }
    private var isLockedOut: Bool { lockoutRemaining > 0 }

    var body: some View {
        GeometryReader { proxy in
            let compact = verticalSizeClass == .compact
            let keySize = KeypadMetrics.keySize(for: proxy.size, compact: compact)

            AdaptiveStack {
                header(compact: compact)
                input(keySize: keySize)
                    .disabled(isLockedOut)
                    .opacity(isLockedOut ? 0.35 : 1)
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
        .padding()
        .background(Color(.systemGroupedBackground).ignoresSafeArea())
        .sensoryFeedback(.error, trigger: shakes)
        .onAppear {
            startLockoutCountdown()
            if scenePhase == .active { autoPromptBiometrics() }
        }
        .onChange(of: scenePhase) { _, phase in
            if phase == .active {
                autoPromptBiometrics()
            } else if phase == .background {
                didAutoPrompt = false
            }
        }
    }

    // MARK: - Layout

    private func header(compact: Bool) -> some View {
        let title: LocalizedStringKey = kind.isPattern ? "Draw your pattern" : "Enter your PIN"

        return VStack(spacing: 14) {
            IconBadge(systemName: "lock.fill", size: compact ? 48 : 64)
            Text(title)
                .font(.title2.weight(.semibold))
            statusText
                .font(.subheadline)
                .multilineTextAlignment(.center)
                .frame(minHeight: 20)

            if !kind.isPattern {
                PinDotsView(length: kind.pinLength, filled: pin.count, isError: shakes > 0 && pin.isEmpty)
                    .modifier(ShakeEffect(animatableData: CGFloat(shakes)))
                    .padding(.top, 6)
            }

            if kind.isPattern && canUseBiometrics {
                Button {
                    authenticateWithBiometrics()
                } label: {
                    Label {
                        Text(verbatim: Biometrics.displayName)
                    } icon: {
                        Image(systemName: Biometrics.symbolName)
                    }
                }
                .buttonStyle(.bordered)
                .buttonBorderShape(.capsule)
                .padding(.top, 4)
            }
        }
        .frame(maxWidth: 360)
    }

    @ViewBuilder
    private var statusText: some View {
        if isLockedOut {
            Text("Too many attempts. Try again in \(lockoutRemaining) s.")
                .foregroundStyle(.red)
        } else if model.session.failedAttempts > 0 {
            Text("Wrong passcode. Attempts: \(model.session.failedAttempts)")
                .foregroundStyle(.red)
        } else {
            Text("AppLock is locked")
                .foregroundStyle(.secondary)
        }
    }

    @ViewBuilder
    private func input(keySize: CGFloat) -> some View {
        if kind.isPattern {
            PatternLockView(isError: patternError) { pattern in
                // A single accidental tap is not counted as an attempt.
                guard pattern.count > 1 else { return }
                submit(CredentialKind.secret(for: pattern))
            }
            .frame(width: keySize * 3.7, height: keySize * 3.7)
        } else {
            PinPadView(
                length: kind.pinLength,
                code: $pin,
                keySize: keySize,
                biometricSymbol: canUseBiometrics ? Biometrics.symbolName : nil,
                onBiometric: canUseBiometrics ? { authenticateWithBiometrics() } : nil,
                onComplete: { value in submit(value) }
            )
        }
    }

    // MARK: - Actions

    private func submit(_ secret: String) {
        guard !isLockedOut else { return }
        if model.credentials.verify(secret) {
            pin = ""
            model.session.unlock()
            return
        }

        pin = ""
        withAnimation(.default) { shakes += 1 }
        if kind.isPattern {
            patternError = true
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.6) { patternError = false }
        }

        let attempts = model.session.registerFailure()
        captureIntruderIfNeeded(attempts: attempts)
        startLockoutCountdown()
    }

    private func captureIntruderIfNeeded(attempts: Int) {
        guard intruderEnabled, attempts == max(1, intruderThreshold) else { return }
        let store = model.intruders
        IntruderCamera.shared.capture { data in
            guard let data else { return }
            try? store.add(data, failedAttempts: attempts)
        }
    }

    private func autoPromptBiometrics() {
        guard canUseBiometrics, !didAutoPrompt, !isLockedOut else { return }
        didAutoPrompt = true
        authenticateWithBiometrics()
    }

    private func authenticateWithBiometrics() {
        Task {
            let success = await Biometrics.authenticate(reason: String(localized: "Unlock AppLock"))
            if success {
                model.session.unlock()
            }
        }
    }

    private func startLockoutCountdown() {
        guard let until = model.session.lockoutUntil, until > Date() else {
            lockoutRemaining = 0
            return
        }
        lockoutRemaining = Int(until.timeIntervalSinceNow.rounded(.up))
        Task {
            while lockoutRemaining > 0 {
                try? await Task.sleep(for: .seconds(1))
                let remaining = (model.session.lockoutUntil ?? Date()).timeIntervalSinceNow
                lockoutRemaining = max(0, Int(remaining.rounded(.up)))
            }
        }
    }
}
