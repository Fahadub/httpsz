import SwiftUI

struct PinPad: View {
    let title: String
    var showFaceID = false
    var onComplete: (String) -> Bool   // يرجع false إذا الرمز خطأ
    @EnvironmentObject var store: LockStore
    @State private var code = ""
    @State private var error = false
    private let length = 4

    var body: some View {
        VStack(spacing: 28) {
            Image(systemName: "lock.fill").font(.system(size: 50)).foregroundStyle(.tint)
            Text(title).font(.title2.bold())
            HStack(spacing: 16) {
                ForEach(0..<length, id: \.self) { i in
                    Circle().fill(i < code.count ? Color.primary : .clear)
                        .overlay(Circle().stroke(Color.primary)).frame(width: 16, height: 16)
                }
            }
            .offset(x: error ? 10 : 0)
            .animation(.default.repeatCount(3, autoreverses: true), value: error)

            LazyVGrid(columns: Array(repeating: GridItem(.fixed(80)), count: 3), spacing: 16) {
                ForEach(1...9, id: \.self) { n in key("\(n)") }
                Button { store.faceID() } label: { Image(systemName: "faceid").font(.title) }
                    .frame(width: 72, height: 72).opacity(showFaceID ? 1 : 0).disabled(!showFaceID)
                key("0")
                Button { if !code.isEmpty { code.removeLast() } } label: {
                    Image(systemName: "delete.left").font(.title)
                }.frame(width: 72, height: 72)
            }
            .environment(\.layoutDirection, .leftToRight)
        }
        .padding()
        .onAppear { if showFaceID { store.faceID() } }
    }

    private func key(_ d: String) -> some View {
        Button {
            guard code.count < length else { return }
            code += d
            if code.count == length {
                if !onComplete(code) { error.toggle() }
                code = ""
            }
        } label: {
            Text(d).font(.title).frame(width: 72, height: 72)
                .background(Circle().fill(Color.gray.opacity(0.2)))
        }.foregroundStyle(.primary)
    }
}

struct SetPasscodeView: View {
    @EnvironmentObject var store: LockStore
    @State private var first: String?

    var body: some View {
        PinPad(title: first == nil ? "أنشئ رمزاً من 4 أرقام" : "أعد إدخال الرمز") { c in
            if first == nil { first = c; return true }
            if c == first { store.setPasscode(c); return true }
            first = nil
            return false
        }
    }
}

struct UnlockView: View {
    @EnvironmentObject var store: LockStore
    var body: some View {
        PinPad(title: store.pendingApp.map { "افتح \($0)" } ?? "أدخل الرمز", showFaceID: true) {
            store.check($0)
        }
    }
}

struct AppsListView: View {
    @EnvironmentObject var store: LockStore
    @State private var name = ""
    @State private var scheme = ""

    var body: some View {
        NavigationStack {
            List {
                Section("التطبيقات المقفلة (اضغط للفتح)") {
                    ForEach(store.apps) { app in
                        Button { store.open(app) } label: {
                            Label(app.name, systemImage: "lock.app.dashed")
                        }
                    }
                    .onDelete { store.apps.remove(atOffsets: $0) }
                }
                Section("إضافة تطبيق") {
                    TextField("الاسم (مثل WhatsApp)", text: $name)
                    TextField("الرابط (مثل whatsapp://)", text: $scheme)
                        .textInputAutocapitalization(.never).autocorrectionDisabled()
                    Button("إضافة") {
                        store.apps.append(LockedApp(name: name, scheme: scheme))
                        name = ""; scheme = ""
                    }.disabled(name.isEmpty || scheme.isEmpty)
                }
                Section("القفل الحقيقي") {
                    Text("لقفل التطبيق فعلياً: تطبيق الاختصارات ← أتمتة ← التطبيق ← اختر WhatsApp ← «عند الفتح» ← تشغيل فوري ← إجراء «فتح عناوين URL» بالقيمة:\napplock://open?app=WhatsApp")
                        .font(.footnote).textSelection(.enabled)
                }
            }
            .navigationTitle("قفل التطبيقات")
            .toolbar { Button { store.isLocked = true } label: { Image(systemName: "lock") } }
        }
    }
}
