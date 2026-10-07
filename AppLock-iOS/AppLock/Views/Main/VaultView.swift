import PhotosUI
import SwiftUI

struct VaultView: View {
    @Environment(AppModel.self) private var model

    @State private var pickerItems: [PhotosPickerItem] = []
    @State private var isImporting = false
    @State private var importedCount = 0
    @State private var showImportedAlert = false
    @State private var viewerItem: SecureItem?

    private let columns = [GridItem(.adaptive(minimum: 104, maximum: 200), spacing: 4)]

    var body: some View {
        NavigationStack {
            Group {
                if model.vault.items.isEmpty {
                    ContentUnavailableView {
                        Label("Your vault is empty", systemImage: "photo.on.rectangle.angled")
                    } description: {
                        Text("Photos you add here are encrypted and only open with your passcode.")
                    } actions: {
                        PhotosPicker(selection: $pickerItems, matching: .images) {
                            Text("Add photos")
                        }
                        .buttonStyle(.borderedProminent)
                    }
                } else {
                    ScrollView {
                        LazyVGrid(columns: columns, spacing: 4) {
                            ForEach(model.vault.items) { item in
                                Button {
                                    viewerItem = item
                                } label: {
                                    SecureThumbnail(store: model.vault, item: item)
                                }
                                .buttonStyle(.plain)
                            }
                        }
                        .padding(4)

                        Label("Encrypted with AES-256 on this device", systemImage: "lock.fill")
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                            .padding(.vertical, 16)
                    }
                }
            }
            .background(Color(.systemGroupedBackground))
            .navigationTitle("Vault")
            .toolbar {
                ToolbarItem(placement: .primaryAction) {
                    PhotosPicker(selection: $pickerItems, matching: .images) {
                        Image(systemName: "plus")
                    }
                    .accessibilityLabel(Text("Add photos"))
                }
            }
            .overlay {
                if isImporting {
                    ProgressView("Encrypting")
                        .padding(24)
                        .background(.regularMaterial, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                }
            }
            .onChange(of: pickerItems) { _, items in
                importItems(items)
            }
            .fullScreenCover(item: $viewerItem) { item in
                PhotoViewer(store: model.vault, startItem: item)
            }
            .alert("Added to vault", isPresented: $showImportedAlert) {
                Button("OK", role: .cancel) {}
            } message: {
                Text("Photos added: \(importedCount). You can now delete the originals from the Photos app.")
            }
        }
    }

    private func importItems(_ items: [PhotosPickerItem]) {
        guard !items.isEmpty else { return }
        isImporting = true
        Task {
            var count = 0
            for item in items {
                guard let data = try? await item.loadTransferable(type: Data.self) else { continue }
                do {
                    try model.vault.add(data)
                    count += 1
                } catch {
                    continue
                }
            }
            pickerItems = []
            isImporting = false
            importedCount = count
            showImportedAlert = count > 0
        }
    }
}

/// Square thumbnail that decrypts its image in the background.
struct SecureThumbnail: View {
    let store: SecureMediaStore
    let item: SecureItem
    var cornerRadius: CGFloat = 6

    @State private var image: UIImage?

    var body: some View {
        Color(.secondarySystemGroupedBackground)
            .aspectRatio(1, contentMode: .fit)
            .overlay {
                if let image {
                    Image(uiImage: image)
                        .resizable()
                        .scaledToFill()
                } else {
                    ProgressView()
                }
            }
            .clipShape(RoundedRectangle(cornerRadius: cornerRadius, style: .continuous))
            .contentShape(Rectangle())
            .task(id: item.id) {
                image = await store.thumbnail(for: item)
            }
    }
}

/// Full-screen, swipeable viewer for encrypted photos.
struct PhotoViewer: View {
    let store: SecureMediaStore

    @Environment(\.dismiss) private var dismiss
    @State private var selection: UUID
    @State private var shareImage: UIImage?
    @State private var confirmDelete = false

    init(store: SecureMediaStore, startItem: SecureItem) {
        self.store = store
        _selection = State(initialValue: startItem.id)
    }

    private var currentItem: SecureItem? {
        store.items.first { $0.id == selection }
    }

    var body: some View {
        NavigationStack {
            TabView(selection: $selection) {
                ForEach(store.items) { item in
                    ZoomableImage(store: store, item: item)
                        .tag(item.id)
                }
            }
            .tabViewStyle(.page(indexDisplayMode: .never))
            .background(Color.black.ignoresSafeArea())
            .toolbarBackground(.visible, for: .navigationBar, .bottomBar)
            .toolbarColorScheme(.dark, for: .navigationBar, .bottomBar)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button {
                        dismiss()
                    } label: {
                        Image(systemName: "xmark")
                    }
                    .accessibilityLabel(Text("Close"))
                }
                ToolbarItem(placement: .principal) {
                    if let currentItem {
                        Text(currentItem.createdAt.formatted(date: .abbreviated, time: .shortened))
                            .font(.subheadline.weight(.semibold))
                            .foregroundStyle(.white)
                    }
                }
                ToolbarItemGroup(placement: .bottomBar) {
                    if let shareImage {
                        ShareLink(
                            item: Image(uiImage: shareImage),
                            preview: SharePreview("Photo", image: Image(uiImage: shareImage))
                        ) {
                            Image(systemName: "square.and.arrow.up")
                        }
                        .accessibilityLabel(Text("Share"))
                    }
                    Spacer()
                    Button(role: .destructive) {
                        confirmDelete = true
                    } label: {
                        Image(systemName: "trash")
                    }
                    .accessibilityLabel(Text("Delete"))
                }
            }
            .confirmationDialog("Delete this photo?", isPresented: $confirmDelete, titleVisibility: .visible) {
                Button("Delete", role: .destructive, action: deleteCurrent)
            } message: {
                Text("This can't be undone.")
            }
            .task(id: selection) {
                shareImage = nil
                if let currentItem {
                    shareImage = await store.fullImage(for: currentItem)
                }
            }
        }
    }

    private func deleteCurrent() {
        guard let currentItem, let index = store.items.firstIndex(of: currentItem) else { return }
        store.delete(currentItem)
        if store.items.isEmpty {
            dismiss()
        } else {
            selection = store.items[min(index, store.items.count - 1)].id
        }
    }
}

private struct ZoomableImage: View {
    let store: SecureMediaStore
    let item: SecureItem

    @State private var image: UIImage?
    @State private var scale: CGFloat = 1
    @GestureState private var pinch: CGFloat = 1

    var body: some View {
        ZStack {
            if let image {
                Image(uiImage: image)
                    .resizable()
                    .scaledToFit()
                    .scaleEffect(scale * pinch)
                    .gesture(
                        MagnifyGesture()
                            .updating($pinch) { value, state, _ in
                                state = value.magnification
                            }
                            .onEnded { value in
                                scale = min(max(scale * value.magnification, 1), 4)
                            }
                    )
                    .onTapGesture(count: 2) {
                        withAnimation(.spring) { scale = scale > 1 ? 1 : 2.5 }
                    }
            } else {
                ProgressView()
                    .tint(.white)
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .task(id: item.id) {
            image = await store.fullImage(for: item)
        }
    }
}
