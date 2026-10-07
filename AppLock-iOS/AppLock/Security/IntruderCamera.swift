import AVFoundation
import Foundation

/// Takes a single photo with the front camera, used after wrong passcodes.
final class IntruderCamera: NSObject, AVCapturePhotoCaptureDelegate {
    static let shared = IntruderCamera()

    private let session = AVCaptureSession()
    private let output = AVCapturePhotoOutput()
    private let queue = DispatchQueue(label: "applock.intruder-camera")
    private var isConfigured = false
    private var completion: ((Data?) -> Void)?

    static var isAuthorized: Bool {
        AVCaptureDevice.authorizationStatus(for: .video) == .authorized
    }

    static func requestAccess() async -> Bool {
        switch AVCaptureDevice.authorizationStatus(for: .video) {
        case .authorized:
            return true
        case .notDetermined:
            return await AVCaptureDevice.requestAccess(for: .video)
        default:
            return false
        }
    }

    /// Calls `completion` on the main queue with JPEG/HEIC data, or nil.
    func capture(completion: @escaping (Data?) -> Void) {
        guard Self.isAuthorized else {
            completion(nil)
            return
        }
        queue.async { [self] in
            guard self.completion == nil else { return }
            if !isConfigured {
                isConfigured = configure()
            }
            guard isConfigured else {
                DispatchQueue.main.async { completion(nil) }
                return
            }
            self.completion = completion
            if !session.isRunning {
                session.startRunning()
            }
            // Give auto exposure a moment so the photo is not dark.
            queue.asyncAfter(deadline: .now() + 0.7) { [self] in
                output.capturePhoto(with: AVCapturePhotoSettings(), delegate: self)
            }
        }
    }

    private func configure() -> Bool {
        guard let device = AVCaptureDevice.default(.builtInWideAngleCamera, for: .video, position: .front),
              let input = try? AVCaptureDeviceInput(device: device) else { return false }

        session.beginConfiguration()
        session.sessionPreset = .photo
        if session.canAddInput(input) { session.addInput(input) }
        if session.canAddOutput(output) { session.addOutput(output) }
        session.commitConfiguration()
        return session.inputs.contains(input) && session.outputs.contains(output)
    }

    func photoOutput(_ output: AVCapturePhotoOutput, didFinishProcessingPhoto photo: AVCapturePhoto, error: Error?) {
        let data = error == nil ? photo.fileDataRepresentation() : nil
        queue.async { [self] in
            session.stopRunning()
            let handler = completion
            completion = nil
            DispatchQueue.main.async { handler?(data) }
        }
    }
}
