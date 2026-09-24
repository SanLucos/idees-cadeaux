import UIKit
import UniformTypeIdentifiers

/// Spec §5.6: "Partager" from Safari or another app. Reads the shared URL
/// (or text) and hands it to the app as "<app bundle id>://share?url=…&text=…&title=…",
/// the same link the Android intent produces (services/shareIntake.ts).
/// No UI of its own: the idea form lives in the app.
final class ShareViewController: UIViewController {
    override func viewDidAppear(_ animated: Bool) {
        super.viewDidAppear(animated)
        Task { await handOver() }
    }

    private func handOver() async {
        let items = extensionContext?.inputItems as? [NSExtensionItem] ?? []
        var url: URL?
        var text: String?
        let title = items.lazy.compactMap { $0.attributedContentText?.string }.first

        for provider in items.flatMap({ $0.attachments ?? [] }) {
            if url == nil, provider.hasItemConformingToTypeIdentifier(UTType.url.identifier) {
                url = try? await provider.loadItem(forTypeIdentifier: UTType.url.identifier) as? URL
            }
            if text == nil, provider.hasItemConformingToTypeIdentifier(UTType.plainText.identifier) {
                text = try? await provider.loadItem(forTypeIdentifier: UTType.plainText.identifier) as? String
            }
        }

        if let link = shareLink(url: url, text: text, title: title) {
            openHostApp(link)
        }
        extensionContext?.completeRequest(returningItems: nil)
    }

    private func shareLink(url: URL?, text: String?, title: String?) -> URL? {
        // The app's URL scheme is its bundle id (Info.plist), never hard-coded.
        guard let bundleId = Bundle.main.bundleIdentifier,
              let dot = bundleId.range(of: ".", options: .backwards) else { return nil }
        var components = URLComponents()
        components.scheme = String(bundleId[..<dot.lowerBound])
        components.host = "share"
        components.queryItems = [
            url.flatMap { ["http", "https"].contains($0.scheme?.lowercased()) ? URLQueryItem(name: "url", value: $0.absoluteString) : nil },
            text.map { URLQueryItem(name: "text", value: $0) },
            title.map { URLQueryItem(name: "title", value: $0) },
        ].compactMap { $0 }

        return components.queryItems?.isEmpty == false ? components.url : nil
    }

    /// Extensions can't call UIApplication.open directly: reach the
    /// application object through the responder chain.
    private func openHostApp(_ url: URL) {
        let selector = NSSelectorFromString("openURL:options:completionHandler:")
        var responder: UIResponder? = self
        while let current = responder {
            if let application = current as? UIApplication, application.responds(to: selector) {
                typealias OpenURL = @convention(c) (NSObject, Selector, NSURL, NSDictionary, (@convention(block) (Bool) -> Void)?) -> Void
                let open = unsafeBitCast(application.method(for: selector), to: OpenURL.self)
                open(application, selector, url as NSURL, NSDictionary(), nil)
                return
            }
            responder = current.next
        }
    }
}
