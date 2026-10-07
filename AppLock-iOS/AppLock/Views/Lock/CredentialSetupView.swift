import SwiftUI

/// Create (and confirm) a PIN or pattern.
struct CredentialSetupView: View {
    var onComplete: (String, CredentialKind) -> Void

    @Environment(\.verticalSizeClass) private var verticalSizeClass
    @State private var kind: CredentialKind = .pin6
    @State private var firstEntry: String?
    @State private var pin = ""
    @State private var message: LocalizedStringKey?
    @State private var patternError = false
    @State private var shakes = 0

    var body: some View {
        GeometryReader { proxy in
            let compact = verticalSizeClass == .compact
            let keySize = KeypadMetrics.keySize(for: proxy.size, compact: compact)

            AdaptiveStack {
                header(compact: compact)
                input(keySize: keySize)
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
        .sensoryFeedback(.error, trigger: shakes)
        .onChange(of: kind) { _, _ in
            pin = ""
            message = nil
        }
    }

    private func header(compact: Bool) -> some View {
        let title: LocalizedStringKey = firstEntry == nil ? "Create a passcode" : "Confirm your passcode"
        let hint: LocalizedStringKey = kind.isPattern
            ? (firstEntry == nil ? "Connect at least 4 dots." : "Draw the same pattern again.")
            : (firstEntry == nil ? "You'll use it to open AppLock and your locked apps." : "Enter the same PIN again.")

        return VStack(spacing: 14) {
            IconBadge(systemName: kind.isPattern ? "circle.grid.3x3.fill" : "key.fill", size: compact ? 44 : 60)
            Text(title)
                .font(.title2.weight(.semibold))
                .multilineTextAlignment(.center)
            Text(message ?? hint)
                .font(.subheadline)
                .foregroundStyle(message == nil ? Color.secondary : Color.red)
                .multilineTextAlignment(.center)
                .fixedSize(horizontal: false, vertical: true)

            if firstEntry == nil {
                Picker("Lock type", selection: $kind) {
                    ForEach(CredentialKind.allCases) { kind in
                        Text(kind.title).tag(kind)
                    }
                }
                .pickerStyle(.segmented)
                .frame(maxWidth: 340)
            }

            if !kind.isPattern {
                PinDotsView(length: kind.pinLength, filled: pin.count, isError: message != nil && pin.isEmpty)
                    .modifier(ShakeEffect(animatableData: CGFloat(shakes)))
                    .padding(.top, 6)
            }
        }
        .frame(maxWidth: 380)
    }

    @ViewBuilder
    private func input(keySize: CGFloat) -> some View {
        if kind.isPattern {
            PatternLockView(isError: patternError) { pattern in
                handlePattern(pattern)
            }
            .frame(width: keySize * 3.7, height: keySize * 3.7)
        } else {
            PinPadView(length: kind.pinLength, code: $pin, keySize: keySize, onComplete: { value in
                handle(value)
            })
        }
    }

    private func handlePattern(_ pattern: [Int]) {
        guard pattern.count >= 4 else {
            fail("Connect at least 4 dots.")
            return
        }
        handle(CredentialKind.secret(for: pattern))
    }

    private func handle(_ secret: String) {
        pin = ""
        if let firstEntry {
            if firstEntry == secret {
                onComplete(secret, kind)
            } else {
                self.firstEntry = nil
                fail("They didn't match. Try again.")
            }
        } else {
            firstEntry = secret
            message = nil
        }
    }

    private func fail(_ text: LocalizedStringKey) {
        message = text
        withAnimation(.default) { shakes += 1 }
        if kind.isPattern {
            patternError = true
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.6) { patternError = false }
        }
    }
}
