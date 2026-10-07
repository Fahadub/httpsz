import SwiftUI

/// Row of dots that fill in as digits are typed.
struct PinDotsView: View {
    let length: Int
    let filled: Int
    var isError = false

    var body: some View {
        HStack(spacing: 16) {
            ForEach(0..<length, id: \.self) { index in
                let tint: Color = isError ? .red : .accentColor
                Circle()
                    .fill(index < filled ? tint : Color.clear)
                    .overlay(
                        Circle().strokeBorder(index < filled ? tint : Color.secondary.opacity(0.5), lineWidth: 1.5)
                    )
                    .frame(width: 14, height: 14)
            }
        }
        .environment(\.layoutDirection, .leftToRight)
        .animation(.easeOut(duration: 0.12), value: filled)
        .accessibilityElement()
        .accessibilityLabel(Text("Entered \(filled) of \(length) digits"))
    }
}

/// Numeric keypad. Calls `onComplete` once `length` digits are entered.
struct PinPadView: View {
    let length: Int
    @Binding var code: String
    var keySize: CGFloat = 76
    var biometricSymbol: String? = nil
    var onBiometric: (() -> Void)? = nil
    var onComplete: (String) -> Void

    private let rows = [["1", "2", "3"], ["4", "5", "6"], ["7", "8", "9"]]

    var body: some View {
        VStack(spacing: keySize * 0.22) {
            ForEach(rows, id: \.self) { row in
                HStack(spacing: keySize * 0.34) {
                    ForEach(row, id: \.self) { digit in
                        digitKey(digit)
                    }
                }
            }
            HStack(spacing: keySize * 0.34) {
                if let biometricSymbol, let onBiometric {
                    iconKey(biometricSymbol, label: Text("Use biometrics"), action: onBiometric)
                } else {
                    Color.clear.frame(width: keySize, height: keySize)
                }
                digitKey("0")
                iconKey("delete.left", label: Text("Delete")) {
                    if !code.isEmpty { code.removeLast() }
                }
                .opacity(code.isEmpty ? 0 : 1)
                .disabled(code.isEmpty)
            }
        }
        .environment(\.layoutDirection, .leftToRight)
        .sensoryFeedback(.impact(weight: .light), trigger: code.count)
    }

    private func digitKey(_ digit: String) -> some View {
        Button {
            guard code.count < length else { return }
            code.append(digit)
            if code.count == length {
                let value = code
                // Let the last dot fill in before validating.
                DispatchQueue.main.asyncAfter(deadline: .now() + 0.12) {
                    onComplete(value)
                }
            }
        } label: {
            Text(verbatim: digit)
                .font(.system(size: keySize * 0.4, weight: .regular, design: .rounded))
                .foregroundStyle(.primary)
                .frame(width: keySize, height: keySize)
                .background(Circle().fill(Color(.secondarySystemGroupedBackground)))
        }
        .buttonStyle(KeyButtonStyle())
    }

    private func iconKey(_ symbol: String, label: Text, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: symbol)
                .font(.system(size: keySize * 0.32, weight: .regular))
                .foregroundStyle(.primary)
                .frame(width: keySize, height: keySize)
                .contentShape(Circle())
        }
        .buttonStyle(KeyButtonStyle())
        .accessibilityLabel(label)
    }
}

private struct KeyButtonStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .scaleEffect(configuration.isPressed ? 0.92 : 1)
            .opacity(configuration.isPressed ? 0.6 : 1)
            .animation(.easeOut(duration: 0.12), value: configuration.isPressed)
    }
}
