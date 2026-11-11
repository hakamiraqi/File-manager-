import Flutter
import PDFKit
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  // «قارئ PDF» inside the app (2026-11-11): PDFKit draws the pages, one document at a time.
  private var document: PDFDocument?
  private let pdfQueue = DispatchQueue(label: "filenest.pdf")

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
    guard let registrar = engineBridge.pluginRegistry.registrar(forPlugin: "FileNestPdf") else { return }
    let channel = FlutterMethodChannel(name: "filenest/pdf", binaryMessenger: registrar.messenger())
    channel.setMethodCallHandler { [weak self] call, result in
      guard let self = self else { return }
      let args = call.arguments as? [String: Any] ?? [:]
      let method = call.method
      self.pdfQueue.async {
        let answer = self.handle(method, args)
        DispatchQueue.main.async { result(answer) }
      }
    }
  }

  private func handle(_ method: String, _ args: [String: Any]) -> Any? {
    switch method {
    case "open":
      guard let path = args["path"] as? String, let doc = PDFDocument(url: URL(fileURLWithPath: path)) else {
        return FlutterError(code: "pdf", message: "تعذّر فتح الملف.", details: nil)
      }
      if doc.isLocked {
        return FlutterError(code: "password", message: "الملف محمي بكلمة سر.", details: nil)
      }
      document = doc
      var sizes: [[Int]] = []
      for i in 0..<doc.pageCount {
        let b = doc.page(at: i)?.bounds(for: .mediaBox) ?? .zero
        sizes.append([Int(b.width), Int(b.height)])
      }
      return ["count": doc.pageCount, "sizes": sizes]
    case "render":
      guard let doc = document, let index = args["page"] as? Int, let width = args["width"] as? Int,
            let page = doc.page(at: index) else {
        return FlutterError(code: "pdf", message: "no document", details: nil)
      }
      let b = page.bounds(for: .mediaBox)
      let w = CGFloat(max(200, min(width, 2400)))
      let size = CGSize(width: w, height: max(1, w * b.height / max(b.width, 1)))
      let image = page.thumbnail(of: size, for: .mediaBox)
      guard let data = image.jpegData(compressionQuality: 0.88) else {
        return FlutterError(code: "pdf", message: "render failed", details: nil)
      }
      return FlutterStandardTypedData(bytes: data)
    case "close":
      document = nil
      return nil
    default:
      return FlutterMethodNotImplemented
    }
  }
}
