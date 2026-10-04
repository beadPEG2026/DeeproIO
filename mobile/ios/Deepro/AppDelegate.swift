import UIKit
import WebKit

@main
class AppDelegate: UIResponder, UIApplicationDelegate {
    var window: UIWindow?
    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?) -> Bool {
        let window = UIWindow(frame: UIScreen.main.bounds)
        window.rootViewController = BrowserController()
        window.makeKeyAndVisible()
        self.window = window
        return true
    }
}

final class BrowserController: UIViewController, WKNavigationDelegate {
    private let home = URL(string: "https://deepro.io/")!
    private var web: WKWebView!
    private var lastPage: URL!
    private let errorPanel = UIStackView()
    private let back = UIButton(type: .system)
    private let externalSchemes: Set<String> = ["https", "mailto", "tel", "wc", "metamask", "trust", "tronlink", "phantom"]
    private func internalURL(_ url: URL) -> Bool {
        return url.scheme?.lowercased() == "https" && ["deepro.io", "www.deepro.io"].contains(url.host?.lowercased() ?? "") && url.user == nil && url.password == nil && (url.port == nil || url.port == 443)
    }
    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = .systemBackground
        lastPage = home
        let configuration = WKWebViewConfiguration()
        configuration.websiteDataStore = .default()
        configuration.applicationNameForUserAgent = "DeeproIOS/1.0.0"
        web = WKWebView(frame: .zero, configuration: configuration)
        web.navigationDelegate = self
        web.allowsBackForwardNavigationGestures = true
        web.translatesAutoresizingMaskIntoConstraints = false
        view.addSubview(web)
        back.setTitle("‹ 返回", for: .normal)
        back.addTarget(self, action: #selector(goBack), for: .touchUpInside)
        back.translatesAutoresizingMaskIntoConstraints = false
        view.addSubview(back)
        NSLayoutConstraint.activate([
            back.topAnchor.constraint(equalTo: view.safeAreaLayoutGuide.topAnchor),
            back.leadingAnchor.constraint(equalTo: view.leadingAnchor, constant: 16),
            back.heightAnchor.constraint(equalToConstant: 32),
            web.topAnchor.constraint(equalTo: back.bottomAnchor),
            web.bottomAnchor.constraint(equalTo: view.safeAreaLayoutGuide.bottomAnchor),
            web.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            web.trailingAnchor.constraint(equalTo: view.trailingAnchor)
        ])
        errorPanel.axis = .vertical; errorPanel.spacing = 18; errorPanel.alignment = .center
        errorPanel.backgroundColor = .systemBackground; errorPanel.isHidden = true
        errorPanel.translatesAutoresizingMaskIntoConstraints = false; view.addSubview(errorPanel)
        let label = UILabel(); label.text = "暂时无法连接 Deepro"; label.font = .boldSystemFont(ofSize: 21)
        let note = UILabel(); note.text = "请检查网络后重试，交易操作不会自动重发。"; note.font = .systemFont(ofSize: 12)
        let retry = UIButton(type: .system); retry.setTitle("重新连接", for: .normal); retry.addTarget(self, action: #selector(reconnect), for: .touchUpInside)
        [label, note, retry].forEach { errorPanel.addArrangedSubview($0) }
        NSLayoutConstraint.activate([errorPanel.centerXAnchor.constraint(equalTo: view.centerXAnchor), errorPanel.centerYAnchor.constraint(equalTo: view.centerYAnchor)])
        web.load(URLRequest(url: home))
    }
    @objc private func goBack() { if web.canGoBack { web.goBack() } else { web.load(URLRequest(url: home)) } }
    @objc private func reconnect() { errorPanel.isHidden = true; web.isHidden = false; web.load(URLRequest(url: lastPage)) }
    func webView(_ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction, decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
        guard let url = navigationAction.request.url else { decisionHandler(.cancel); return }
        if internalURL(url) {
            if navigationAction.targetFrame == nil { web.load(navigationAction.request); decisionHandler(.cancel) } else { decisionHandler(.allow) }
            return
        }
        decisionHandler(.cancel)
        guard navigationAction.navigationType == .linkActivated, externalSchemes.contains(url.scheme?.lowercased() ?? ""), url.user == nil, url.password == nil else { return }
        let alert = UIAlertController(title: "打开外部应用", message: "即将离开 Deepro，继续打开此链接？", preferredStyle: .alert)
        alert.addAction(UIAlertAction(title: "取消", style: .cancel))
        alert.addAction(UIAlertAction(title: "继续", style: .default) { _ in UIApplication.shared.open(url) })
        present(alert, animated: true)
    }
    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) { if let url = web.url, internalURL(url) { lastPage = url }; back.isEnabled = web.canGoBack }
    func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) { showError(error) }
    func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) { showError(error) }
    private func showError(_ error: Error) { guard (error as NSError).code != NSURLErrorCancelled else { return }; web.isHidden = true; errorPanel.isHidden = false }
    func webViewWebContentProcessDidTerminate(_ webView: WKWebView) { web.isHidden = true; errorPanel.isHidden = false }
}
