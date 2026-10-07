import SwiftUI

struct IntruderLogView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.openURL) private var openURL

    @AppStorage(SettingsKey.intruderEnabled) private var intruderEnabled = false
    @AppStorage(SettingsKey.intruderThreshold) private var intruderThreshold = 2

    @State private var viewerItem: SecureItem?
    @State private var confirmClear = false
    @State private var showCameraDenied = false

    var body: some View {
        NavigationStack {
            List {
                Section {
                    Toggle(isOn: enabledBinding) {
                        HStack(spacing: 12) {
                            IconBadge(systemName: "camera.fill", size: 34)
                            VStack(alignment: .leading, spacing: 2) {
                                Text("Intruder photos")
                                Text("Wrong attempts before photo: \(intruderThreshold)")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                    }
                } footer: {
                    Text("AppLock quietly takes a photo with the front camera when someone enters a wrong passcode in AppLock.")
                }

                if model.intruders.items.isEmpty {
                    Section {
                        ContentUnavailableView(
                            "No intruders yet",
                            systemImage: "eye.trianglebadge.exclamationmark",
                            description: Text("Photos of anyone who enters a wrong passcode will appear here.")
                        )
                    }
                    .listRowBackground(Color.clear)
                } else {
                    Section("Recent attempts") {
                        ForEach(model.intruders.items) { item in
                            Button {
                                viewerItem = item
                            } label: {
                                row(for: item)
                            }
                            .buttonStyle(.plain)
                        }
                        .onDelete { offsets in
                            let items = offsets.map { model.intruders.items[$0] }
                            items.forEach { model.intruders.delete($0) }
                        }
                    }
                }
            }
            .navigationTitle("Intruders")
            .toolbar {
                if !model.intruders.items.isEmpty {
                    ToolbarItem(placement: .primaryAction) {
                        Button("Clear all", role: .destructive) {
                            confirmClear = true
                        }
                    }
                }
            }
            .confirmationDialog("Delete all intruder photos?", isPresented: $confirmClear, titleVisibility: .visible) {
                Button("Delete all", role: .destructive) {
                    model.intruders.deleteAll()
                }
            }
            .alert("Camera access is off", isPresented: $showCameraDenied) {
                Button("Open Settings") {
                    if let url = URL(string: UIApplication.openSettingsURLString) {
                        openURL(url)
                    }
                }
                Button("Cancel", role: .cancel) {}
            } message: {
                Text("Allow camera access in Settings to take intruder photos.")
            }
            .fullScreenCover(item: $viewerItem) { item in
                PhotoViewer(store: model.intruders, startItem: item)
            }
        }
    }

    private func row(for item: SecureItem) -> some View {
        HStack(spacing: 14) {
            SecureThumbnail(store: model.intruders, item: item, cornerRadius: 12)
                .frame(width: 56, height: 56)
            VStack(alignment: .leading, spacing: 3) {
                Text(item.createdAt.formatted(date: .abbreviated, time: .shortened))
                    .font(.headline)
                if let attempts = item.failedAttempts {
                    Text("Failed attempts: \(attempts)")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
            }
            Spacer(minLength: 0)
            Image(systemName: "chevron.forward")
                .font(.footnote.weight(.semibold))
                .foregroundStyle(.tertiary)
        }
        .contentShape(Rectangle())
    }

    private var enabledBinding: Binding<Bool> {
        Binding(
            get: { intruderEnabled },
            set: { newValue in
                guard newValue else {
                    intruderEnabled = false
                    return
                }
                Task {
                    let granted = await IntruderCamera.requestAccess()
                    intruderEnabled = granted
                    showCameraDenied = !granted
                }
            }
        )
    }
}
