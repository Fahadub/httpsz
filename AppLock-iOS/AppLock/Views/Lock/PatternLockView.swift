import SwiftUI

/// 3x3 swipe pattern. Dots are numbered 0...8 from the top left.
struct PatternLockView: View {
    var isError = false
    var onComplete: ([Int]) -> Void

    @State private var selected: [Int] = []
    @State private var dragLocation: CGPoint?
    @State private var isFinished = false

    var body: some View {
        GeometryReader { proxy in
            let side = min(proxy.size.width, proxy.size.height)
            let centers = Self.centers(in: side)
            let tint: Color = isError ? .red : .accentColor

            ZStack {
                Path { path in
                    guard let first = selected.first else { return }
                    path.move(to: centers[first])
                    for index in selected.dropFirst() {
                        path.addLine(to: centers[index])
                    }
                    if let dragLocation, !isFinished {
                        path.addLine(to: dragLocation)
                    }
                }
                .stroke(tint.opacity(0.55), style: StrokeStyle(lineWidth: 6, lineCap: .round, lineJoin: .round))

                ForEach(0..<9, id: \.self) { index in
                    let isOn = selected.contains(index)
                    ZStack {
                        Circle()
                            .fill(isOn ? tint.opacity(0.16) : Color(.secondarySystemGroupedBackground))
                            .frame(width: side * 0.2, height: side * 0.2)
                        Circle()
                            .fill(isOn ? tint : Color.secondary.opacity(0.45))
                            .frame(width: isOn ? side * 0.075 : side * 0.055, height: isOn ? side * 0.075 : side * 0.055)
                    }
                    .position(centers[index])
                    .animation(.spring(response: 0.25, dampingFraction: 0.6), value: isOn)
                }
            }
            .frame(width: side, height: side)
            .contentShape(Rectangle())
            .gesture(
                DragGesture(minimumDistance: 0)
                    .onChanged { value in
                        track(value.location, centers: centers, hitRadius: side * 0.13)
                    }
                    .onEnded { _ in
                        finish()
                    }
            )
            .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
        .aspectRatio(1, contentMode: .fit)
        .environment(\.layoutDirection, .leftToRight)
        .sensoryFeedback(.selection, trigger: selected.count)
        .accessibilityElement()
        .accessibilityLabel(Text("Pattern grid"))
    }

    private func track(_ location: CGPoint, centers: [CGPoint], hitRadius: CGFloat) {
        guard !isFinished else { return }
        dragLocation = location

        guard let hit = centers.firstIndex(where: { hypot($0.x - location.x, $0.y - location.y) < hitRadius }),
              !selected.contains(hit) else { return }

        // Passing straight over a dot selects it too, like on Android.
        if let last = selected.last, let middle = Self.middleDot(between: last, and: hit), !selected.contains(middle) {
            selected.append(middle)
        }
        selected.append(hit)
    }

    private func finish() {
        guard !isFinished else { return }
        dragLocation = nil
        guard !selected.isEmpty else { return }

        isFinished = true
        onComplete(selected)
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.6) {
            selected = []
            isFinished = false
        }
    }

    private static func centers(in side: CGFloat) -> [CGPoint] {
        let step = side / 3
        return (0..<9).map { index in
            CGPoint(x: step * (CGFloat(index % 3) + 0.5), y: step * (CGFloat(index / 3) + 0.5))
        }
    }

    private static func middleDot(between a: Int, and b: Int) -> Int? {
        let rowSum = a / 3 + b / 3
        let columnSum = a % 3 + b % 3
        guard rowSum % 2 == 0, columnSum % 2 == 0 else { return nil }
        let middle = (rowSum / 2) * 3 + columnSum / 2
        return middle == a || middle == b ? nil : middle
    }
}
