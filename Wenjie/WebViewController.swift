import UIKit
import WebKit
import QuartzCore
import Darwin

/// 保持业务页面、登录、聊天室、直播和实时开奖全部在既有 HTTPS 站点处理。
/// 原生壳只负责 iOS 生命周期、缓存、返回键和外部链接，避免复制一套易失真的业务逻辑。
final class WebViewController: UIViewController, WKNavigationDelegate, WKUIDelegate, UIGestureRecognizerDelegate, WKScriptMessageHandler {
    private static let homeURL = URL(string: "https://wjh5.atmyx.app/action.php?do=login&fn_inputfix=20260807b")!
    private let webView: WKWebView
    // WKWebView 的历史手势开关在某些 iOS 版本上会滞后一帧生效。
    // 房间号登录页是流程根页，额外用透明左缘触摸屏障硬拦截侧滑返回。
    private let roomDoorGestureShield = UIView()
    private let statusLabel = UILabel()
    private let retryButton = UIButton(type: .system)
    private let startupOverlay = UIView()
    private let startupImageView = UIImageView()
    private let startupProgressTrack = UIView()
    private let startupProgressBar = UIView()
    private let startupProgressGradient = CAGradientLayer()
    private var startupProgressWidthConstraint: NSLayoutConstraint!
    private let startupVersionLabel = UILabel()
    private let startupSpinner = UIActivityIndicatorView(style: .large)
    private let startupTitle = UILabel()
    private var startupLoadingVisible = true
    private let adOverlay = UIView()
    private let adImageView = UIImageView()
    private let adSkipButton = UIButton(type: .system)
    private var adTimer: Timer?
    private var adSeconds = 3
    private var edgeBackGesture: UIScreenEdgePanGestureRecognizer!
    private var edgeBackActive = false
    private var edgeBackFinishing = false
    private let edgeBackPreviewImageView = UIImageView()
    private var backPreviewImage: UIImage?
    // 只保存当前已稳定显示页的一张图。它不是历史截图栈，而是下一次返回所需的立即父页兜底。
    private var visiblePagePreview: UIImage?
    private let navigationLoadingOverlay = UIView()
    private let navigationSnapshotImage = UIImageView()
    private let navigationLoadingBox = UIView()
    private let navigationLoadingImage = UIImageView()
    private let navigationDots = UIStackView()
    private let navigationLoadingLabel = UILabel()
    // 与「加载动画/循环加载.html」一致：蓝色缺口圆环围绕百分比循环旋转。
    private let navigationCircleLoader = CAShapeLayer()
    private let navigationLoadingPercent = UILabel()
    private var navigationPercentTimer: Timer?
    private var navigationPercent = 0
    private var navigationReadyTimer: Timer?
    private var startupProgressTimer: Timer?
    private var startupProgress: CGFloat = 0
    // 页面在同一界面停留过久时，按重新打开 App 的路径回到入口加载，避免房间数据长期陈旧。
    private let idlePageRefreshInterval: TimeInterval = 30 * 60
    private var idlePageRefreshTimer: Timer?
    private var lastCompletedPageNavigationAt = Date()
    private var isIdlePageRefreshActive = false
    private var isInitialNavigation = true
    private var isPreparingNavigation = false
    private var pendingNavigationURL: URL?
    private var warmedPageKeys = Set<String>()

    private func isHallURL(_ url: URL?) -> Bool {
        guard let url else { return false }
        let value = (url.path + "?" + (url.query ?? "")).lowercased()
        return value.contains("do=gamelist") || value.contains("gamelist.php")
    }

    private func isRoomDoorURL(_ url: URL?) -> Bool {
        guard let url else { return false }
        let value = (url.path + "?" + (url.query ?? "")).lowercased()
        return value.contains("do=roomdoor") || value.contains("roomdoor.php")
    }

    private func isUserCenterURL(_ url: URL?) -> Bool {
        guard let url else { return false }
        let path = url.path.lowercased()
        return path.contains("/templates/user/")
    }

    private func isKefuURL(_ url: URL?) -> Bool {
#if DEBUG
        if ProcessInfo.processInfo.environment["WENJIE_TEST_KEFU_SURFACE"] == "1" {
            return true
        }
#endif
        guard let url else { return false }
        let value = (url.path + "?" + (url.query ?? "")).lowercased()
        return value.contains("do=kefu") || value.contains("kefu.php")
    }

    private lazy var kefuSurfaceColor: UIColor = {
        guard
            let path = Bundle.main.path(
                forResource: "kfbj",
                ofType: "jpg",
                inDirectory: "fn_static/Style/newimg"
            ),
            let source = UIImage(contentsOfFile: path),
            source.size.width > 0
        else {
            return .white
        }

        // 与 H5 的 background-size: 100% auto 保持一致，让键盘附件栏透出的
        // 原生承载层继续显示同一张在线客服背景，而不是纯色空白。
        let width = max(UIScreen.main.bounds.width, 1)
        let height = max(source.size.height * width / source.size.width, 1)
        let format = UIGraphicsImageRendererFormat.default()
        format.opaque = true
        let scaled = UIGraphicsImageRenderer(
            size: CGSize(width: width, height: height),
            format: format
        ).image { _ in
            UIColor.white.setFill()
            UIRectFill(CGRect(x: 0, y: 0, width: width, height: height))
            source.draw(in: CGRect(x: 0, y: 0, width: width, height: height))
        }
        return UIColor(patternImage: scaled)
    }()

    private func pageSurfaceColor(for url: URL?) -> UIColor {
        // iOS 26 的键盘附件栏会透出 WKWebView 下方的原生承载色。
        // 客服页使用与 H5 相同的背景纹理，避免输入时出现蓝色或纯白空白带。
        if isKefuURL(url) {
            return kefuSurfaceColor
        }
        if isUserCenterURL(url) {
            return UIColor(red: 242/255, green: 243/255, blue: 245/255, alpha: 1)
        }
        return UIColor(red: 20/255, green: 143/255, blue: 232/255, alpha: 1)
    }

    private func updatePagePresentation(for url: URL?) {
        let surface = pageSurfaceColor(for: url)
        let isRoomDoor = isRoomDoorURL(url)
        view.backgroundColor = surface
        webView.backgroundColor = surface
        webView.scrollView.backgroundColor = surface
        if #available(iOS 15.0, *) {
            webView.underPageBackgroundColor = surface
        }
        // 房间号登录页是一个流程根页，不能通过 WebKit 历史手势拖回游戏大厅。
        // 其它页面仍保留系统原生左缘返回手势。
        webView.allowsBackForwardNavigationGestures = !isRoomDoor
        roomDoorGestureShield.isHidden = !isRoomDoor
    }

    private func startNavigationPercentAnimation() {
        navigationPercentTimer?.invalidate()
        navigationPercentTimer = nil
        let timer = Timer(timeInterval: 0.06, repeats: true) { [weak self] timer in
            guard let self, !self.navigationLoadingOverlay.isHidden else { timer.invalidate(); return }
            self.navigationPercent = (self.navigationPercent + 1) % 101
            self.navigationLoadingPercent.text = "\(self.navigationPercent)%"
        }
        // 默认模式会在手势跟踪期间暂停，进度数字会停在任意百分比；common 模式持续刷新。
        RunLoop.main.add(timer, forMode: .common)
        navigationPercentTimer = timer
    }

    init() {
        let configuration = WKWebViewConfiguration()
        configuration.applicationNameForUserAgent = "WenjieApp/1.0 DeviceModel/\(Self.nativeModelIdentifier())"
        configuration.websiteDataStore = .default()
        configuration.allowsInlineMediaPlayback = true
        configuration.mediaTypesRequiringUserActionForPlayback = []
        configuration.preferences.javaScriptCanOpenWindowsAutomatically = true
        let nativeFlags = WKUserScript(
            source: """
            window.__FN_NATIVE_PAGE_TRANSITION__=true;window.__FN_NATIVE_LOADING__=true;
            function fnPrepareNativeBackPreview(event) {
              var node = event.target;
              if (!node || !node.closest) return;
              node = node.closest('a[href],button,input[type="submit"],input[type="button"],[data-url],[onclick],[ontouchend]');
              if (!node) return;
              var href = String(node.getAttribute('href') || '');
              if (href && (href.charAt(0) === '#' || /^javascript:/i.test(href))) return;
              try { window.webkit.messageHandlers.fnPrepareBackPreview.postMessage(1); } catch (e) {}
            }
            document.addEventListener('touchstart', fnPrepareNativeBackPreview, true);
            document.addEventListener('mousedown', fnPrepareNativeBackPreview, true);
            """,
            injectionTime: .atDocumentStart,
            forMainFrameOnly: true
        )
        configuration.userContentController.addUserScript(nativeFlags)
        webView = WKWebView(frame: .zero, configuration: configuration)
        super.init(nibName: nil, bundle: nil)
        // 在 init 里就显示启动图，覆盖 WKWebView 冷启动编译期间的白屏
        showStartupCover()
    }

    private func showStartupCover() {
        guard let window = UIApplication.shared.windows.first ?? (UIApplication.shared.value(forKey: "statusBarWindow") as? UIWindow)?.window ?? nil else { return }
        let cover = UIView(frame: window.bounds)
        cover.tag = 9999
        cover.backgroundColor = UIColor(red: 20/255, green: 143/255, blue: 232/255, alpha: 1)
        if let imgPath = Bundle.main.path(forResource: "startup_loading", ofType: "jpg", inDirectory: "fn_static"),
           let img = UIImage(contentsOfFile: imgPath) {
            let iv = UIImageView(frame: cover.bounds)
            iv.image = img
            iv.contentMode = .scaleAspectFill
            iv.clipsToBounds = true
            cover.addSubview(iv)
        }
        window.addSubview(cover)
    }

    private static func nativeModelIdentifier() -> String {
        var systemInfo = utsname()
        uname(&systemInfo)
        let identifier = withUnsafeBytes(of: &systemInfo.machine) { rawBuffer -> String in
            let bytes = rawBuffer.bindMemory(to: CChar.self)
            return String(cString: bytes.baseAddress!)
        }
        let names: [String: String] = [
            "iPhone14,5": "iPhone 13", "iPhone14,2": "iPhone 13 Pro", "iPhone14,3": "iPhone 13 Pro Max", "iPhone14,4": "iPhone 13 mini",
            "iPhone15,4": "iPhone 15", "iPhone15,5": "iPhone 15 Plus", "iPhone15,2": "iPhone 14 Pro", "iPhone15,3": "iPhone 14 Pro Max",
            "iPhone16,1": "iPhone 15 Pro", "iPhone16,2": "iPhone 15 Pro Max", "iPhone16,one": "iPhone 16", "iPhone17,1": "iPhone 16 Pro",
            "iPhone17,2": "iPhone 16 Pro Max", "iPhone17,3": "iPhone 16", "iPhone17,4": "iPhone 16 Plus",
            "iPhone18,1": "iPhone 17", "iPhone18,2": "iPhone 17 Pro", "iPhone18,3": "iPhone 17 Pro Max", "iPhone18,4": "iPhone 17 Air"
        ]
        return (names[identifier] ?? identifier).replacingOccurrences(of: " ", with: "_")
    }

    required init?(coder: NSCoder) { fatalError("init(coder:) has not been implemented") }

    override func viewDidLoad() {
        super.viewDidLoad()
        // 只在 App 启动阶段显示一次加载页；后续页面跳转不再显示新的加载层。
        view.backgroundColor = pageSurfaceColor(for: Self.homeURL)

        edgeBackPreviewImageView.translatesAutoresizingMaskIntoConstraints = false
        edgeBackPreviewImageView.contentMode = .scaleToFill
        edgeBackPreviewImageView.backgroundColor = UIColor(red: 20/255, green: 143/255, blue: 232/255, alpha: 1)
        edgeBackPreviewImageView.isHidden = true
        view.addSubview(edgeBackPreviewImageView)
        NSLayoutConstraint.activate([
            edgeBackPreviewImageView.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            edgeBackPreviewImageView.trailingAnchor.constraint(equalTo: view.trailingAnchor),
            edgeBackPreviewImageView.topAnchor.constraint(equalTo: view.topAnchor),
            edgeBackPreviewImageView.bottomAnchor.constraint(equalTo: view.bottomAnchor)
        ])

        webView.translatesAutoresizingMaskIntoConstraints = false
        webView.navigationDelegate = self
        webView.uiDelegate = self
        webView.configuration.userContentController.add(self, name: "fnPrepareBackPreview")
        // 使用不透明且与当前页面相符的承载色，避免 WebKit 返回换帧时透出根视图黑底。
        webView.isOpaque = true
        webView.backgroundColor = pageSurfaceColor(for: Self.homeURL)
        webView.scrollView.backgroundColor = pageSurfaceColor(for: Self.homeURL)
        if #available(iOS 15.0, *) {
            webView.underPageBackgroundColor = pageSurfaceColor(for: Self.homeURL)
        }
        webView.scrollView.contentInsetAdjustmentBehavior = .never
        // 全局禁止页面越界回弹：内容仍可正常滚动，但拖到顶部/底部时
        // 不再把整页和背景一起拉离屏幕，也不会露出 WebKit 承载底色。
        webView.scrollView.bounces = false
        webView.scrollView.alwaysBounceVertical = false
        webView.scrollView.alwaysBounceHorizontal = false
        // 使用 WKWebView 原生维护的历史手势。自定义截图预览是异步生成的，
        // 在连续跳页时可能与真正的 back item 错配，造成左侧露出随机页面。
        // WebKit 自己持有真实历史页面，因此不会出现跨页面拼接。
        webView.allowsBackForwardNavigationGestures = true
        view.addSubview(webView)
        NSLayoutConstraint.activate([
            webView.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            webView.trailingAnchor.constraint(equalTo: view.trailingAnchor),
            webView.topAnchor.constraint(equalTo: view.topAnchor),
            webView.bottomAnchor.constraint(equalTo: view.bottomAnchor)
        ])
        // 不再叠加自定义 UIScreenEdgePanGestureRecognizer：它的截图只能代表
        // 某次前进前的页面，无法可靠代表 WebKit 当前真正的上一历史页。

        roomDoorGestureShield.translatesAutoresizingMaskIntoConstraints = false
        roomDoorGestureShield.backgroundColor = .clear
        roomDoorGestureShield.isUserInteractionEnabled = true
        roomDoorGestureShield.isAccessibilityElement = false
        roomDoorGestureShield.accessibilityElementsHidden = true
        roomDoorGestureShield.isHidden = true
        let roomDoorEdgePanBlocker = UIPanGestureRecognizer(target: self, action: #selector(blockRoomDoorEdgePan(_:)))
        roomDoorEdgePanBlocker.cancelsTouchesInView = true
        roomDoorGestureShield.addGestureRecognizer(roomDoorEdgePanBlocker)
        view.addSubview(roomDoorGestureShield)
        NSLayoutConstraint.activate([
            roomDoorGestureShield.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            roomDoorGestureShield.topAnchor.constraint(equalTo: view.topAnchor),
            roomDoorGestureShield.bottomAnchor.constraint(equalTo: view.bottomAnchor),
            roomDoorGestureShield.widthAnchor.constraint(equalToConstant: 44)
        ])

        statusLabel.translatesAutoresizingMaskIntoConstraints = false
        statusLabel.textColor = .white
        statusLabel.font = .systemFont(ofSize: 15)
        statusLabel.textAlignment = .center
        view.addSubview(statusLabel)
        NSLayoutConstraint.activate([
            statusLabel.centerXAnchor.constraint(equalTo: view.centerXAnchor),
            statusLabel.centerYAnchor.constraint(equalTo: view.centerYAnchor, constant: -24)
        ])

        retryButton.translatesAutoresizingMaskIntoConstraints = false
        retryButton.setTitle("重新连接", for: .normal)
        retryButton.setTitleColor(.white, for: .normal)
        retryButton.titleLabel?.font = .systemFont(ofSize: 16, weight: .medium)
        retryButton.backgroundColor = UIColor.white.withAlphaComponent(0.22)
        retryButton.layer.cornerRadius = 18
        statusLabel.isHidden = true
        retryButton.isHidden = true
        retryButton.addTarget(self, action: #selector(retryLoad), for: .touchUpInside)
        view.addSubview(retryButton)
        NSLayoutConstraint.activate([
            retryButton.centerXAnchor.constraint(equalTo: view.centerXAnchor),
            retryButton.topAnchor.constraint(equalTo: statusLabel.bottomAnchor, constant: 14),
            retryButton.widthAnchor.constraint(equalToConstant: 120),
            retryButton.heightAnchor.constraint(equalToConstant: 36)
        ])
        setupStartupLoadingView()
        setupNavigationLoadingView()
        updatePagePresentation(for: Self.homeURL)
        warmedPageKeys = Set(UserDefaults.standard.stringArray(forKey: "fnWarmedPageKeys") ?? [])
        NotificationCenter.default.addObserver(self, selector: #selector(handleAppWillEnterForeground), name: UIApplication.willEnterForegroundNotification, object: nil)
        startStartupProgress()
        preloadBundledFrontendThenLoadHome()
    }

    override func viewDidLayoutSubviews() {
        super.viewDidLayoutSubviews()
        startupProgressGradient.frame = startupProgressBar.bounds
        startupProgressGradient.cornerRadius = startupProgressBar.bounds.height / 2
        startupProgressTrack.layer.shadowPath = UIBezierPath(roundedRect: startupProgressTrack.bounds, cornerRadius: startupProgressTrack.bounds.height / 2).cgPath
        layoutNavigationCircleLoader()
    }

    private func setupNavigationLoadingView() {
        navigationLoadingOverlay.translatesAutoresizingMaskIntoConstraints = false
        navigationLoadingOverlay.backgroundColor = .clear
        navigationLoadingOverlay.isHidden = true
        navigationLoadingOverlay.isUserInteractionEnabled = true
        navigationLoadingBox.translatesAutoresizingMaskIntoConstraints = false
        navigationLoadingBox.backgroundColor = UIColor(red: 54/255, green: 82/255, blue: 99/255, alpha: 1)
        navigationLoadingBox.layer.cornerRadius = 16
        navigationSnapshotImage.translatesAutoresizingMaskIntoConstraints = false
        navigationSnapshotImage.contentMode = .scaleToFill
        navigationLoadingImage.translatesAutoresizingMaskIntoConstraints = false
        navigationLoadingImage.contentMode = .scaleAspectFit
        navigationLoadingLabel.translatesAutoresizingMaskIntoConstraints = false
        navigationLoadingLabel.textColor = .white
        navigationLoadingLabel.font = .systemFont(ofSize: 17, weight: .light)
        navigationLoadingLabel.textAlignment = .center
        navigationLoadingPercent.translatesAutoresizingMaskIntoConstraints = false
        navigationLoadingPercent.textColor = .white
        // 参考稿红框中的百分比小于原尺寸：缩小约 30%。
        navigationLoadingPercent.font = .systemFont(ofSize: 17, weight: .regular)
        navigationLoadingPercent.textAlignment = .center
        view.addSubview(navigationLoadingOverlay)
        navigationLoadingOverlay.addSubview(navigationSnapshotImage)
        navigationLoadingOverlay.addSubview(navigationLoadingBox)
        navigationLoadingBox.addSubview(navigationLoadingImage)
        navigationDots.translatesAutoresizingMaskIntoConstraints = false
        navigationDots.axis = .horizontal
        navigationDots.alignment = .center
        navigationDots.distribution = .equalSpacing
        navigationDots.spacing = 7
        for _ in 0..<3 {
            let dot = UIView()
            dot.backgroundColor = UIColor(red: 226/255, green: 231/255, blue: 241/255, alpha: 1)
            dot.layer.cornerRadius = 5.5
            dot.translatesAutoresizingMaskIntoConstraints = false
            NSLayoutConstraint.activate([dot.widthAnchor.constraint(equalToConstant: 11), dot.heightAnchor.constraint(equalToConstant: 14)])
            navigationDots.addArrangedSubview(dot)
        }
        navigationLoadingBox.addSubview(navigationDots)
        navigationLoadingBox.addSubview(navigationLoadingLabel)
        navigationLoadingBox.addSubview(navigationLoadingPercent)
        navigationCircleLoader.fillColor = UIColor.clear.cgColor
        navigationCircleLoader.strokeColor = UIColor(red: 66/255, green: 168/255, blue: 224/255, alpha: 1).cgColor
        navigationCircleLoader.lineWidth = 3.9
        navigationCircleLoader.lineCap = .butt
        navigationLoadingBox.layer.addSublayer(navigationCircleLoader)
        NSLayoutConstraint.activate([
            navigationLoadingOverlay.leadingAnchor.constraint(equalTo: view.leadingAnchor), navigationLoadingOverlay.trailingAnchor.constraint(equalTo: view.trailingAnchor),
            navigationLoadingOverlay.topAnchor.constraint(equalTo: view.topAnchor), navigationLoadingOverlay.bottomAnchor.constraint(equalTo: view.bottomAnchor),
            navigationSnapshotImage.leadingAnchor.constraint(equalTo: navigationLoadingOverlay.leadingAnchor), navigationSnapshotImage.trailingAnchor.constraint(equalTo: navigationLoadingOverlay.trailingAnchor),
            navigationSnapshotImage.topAnchor.constraint(equalTo: navigationLoadingOverlay.topAnchor), navigationSnapshotImage.bottomAnchor.constraint(equalTo: navigationLoadingOverlay.bottomAnchor),
            navigationLoadingBox.centerXAnchor.constraint(equalTo: navigationLoadingOverlay.centerXAnchor), navigationLoadingBox.centerYAnchor.constraint(equalTo: navigationLoadingOverlay.centerYAnchor),
            // 整个圆环加载组件缩为原来的约 70%，不只缩中间百分比。
            navigationLoadingBox.widthAnchor.constraint(equalToConstant: 78), navigationLoadingBox.heightAnchor.constraint(equalToConstant: 78),
            navigationLoadingImage.centerXAnchor.constraint(equalTo: navigationLoadingBox.centerXAnchor), navigationLoadingImage.topAnchor.constraint(equalTo: navigationLoadingBox.topAnchor, constant: 8),
            navigationLoadingImage.widthAnchor.constraint(equalToConstant: 29), navigationLoadingImage.heightAnchor.constraint(equalToConstant: 29),
            navigationDots.centerXAnchor.constraint(equalTo: navigationLoadingBox.centerXAnchor), navigationDots.centerYAnchor.constraint(equalTo: navigationLoadingBox.centerYAnchor, constant: -8),
            navigationLoadingPercent.centerXAnchor.constraint(equalTo: navigationLoadingBox.centerXAnchor), navigationLoadingPercent.centerYAnchor.constraint(equalTo: navigationLoadingBox.centerYAnchor),
            navigationLoadingLabel.leadingAnchor.constraint(equalTo: navigationLoadingBox.leadingAnchor, constant: 4), navigationLoadingLabel.trailingAnchor.constraint(equalTo: navigationLoadingBox.trailingAnchor, constant: -4),
            navigationLoadingLabel.bottomAnchor.constraint(equalTo: navigationLoadingBox.bottomAnchor, constant: -22)
        ])
    }

    private func layoutNavigationCircleLoader() {
        // CAShapeLayer 默认 bounds 为零；若直接旋转 path，会围绕左上原点公转。
        // 先让图层与加载框同尺寸，旋转轴才固定在加载框正中心。
        navigationCircleLoader.frame = navigationLoadingBox.bounds
        let circleBounds = CGRect(x: 5, y: 5, width: 68, height: 68)
        let center = CGPoint(x: circleBounds.midX, y: circleBounds.midY)
        // 底部保留缺口，等同 HTML 中只绘制 top/right/left 三段 border。
        navigationCircleLoader.path = UIBezierPath(arcCenter: center, radius: 32, startAngle: .pi * 0.72, endAngle: .pi * 2.28, clockwise: true).cgPath
    }

    private func showNavigationLoading(for url: URL?, snapshot: UIImage? = nil) {
        guard !startupLoadingVisible, adOverlay.isHidden else { return }
        let target = url?.absoluteString ?? ""
        let isRoomLoginToHall = isRoomDoorURL(webView.url) && isHallURL(url)
        let isGameRoom = target.range(of: "[?&]do=room(?:&|$)", options: .regularExpression) != nil
        navigationLoadingLabel.text = isGameRoom ? "正在加入" : "正在加载"
        pendingNavigationURL = url
        navigationSnapshotImage.image = snapshot
        navigationLoadingImage.isHidden = true
        navigationDots.isHidden = isRoomLoginToHall
        navigationLoadingLabel.isHidden = isRoomLoginToHall
        navigationLoadingPercent.isHidden = !isRoomLoginToHall
        navigationLoadingOverlay.isHidden = false
        view.bringSubviewToFront(navigationLoadingOverlay)
        if isRoomLoginToHall {
            navigationPercent = 0
            navigationLoadingPercent.text = "0%"
            navigationCircleLoader.isHidden = false
            let rotation = CABasicAnimation(keyPath: "transform.rotation")
            rotation.fromValue = 0
            rotation.toValue = Double.pi * 2
            rotation.duration = 1
            rotation.repeatCount = .infinity
            navigationCircleLoader.add(rotation, forKey: "fnCircleLoadingRotation")
            startNavigationPercentAnimation()
        } else {
            navigationLoadingImage.stopAnimating()
            navigationCircleLoader.isHidden = true
            navigationCircleLoader.removeAllAnimations()
            navigationPercentTimer?.invalidate()
            navigationDots.arrangedSubviews.enumerated().forEach { index, dot in
                let animation = CABasicAnimation(keyPath: "transform.translation.y")
                animation.fromValue = 3
                animation.toValue = -3
                animation.duration = 0.7
                animation.autoreverses = true
                animation.repeatCount = .infinity
                animation.beginTime = CACurrentMediaTime() + Double(index) * 0.14
                dot.layer.add(animation, forKey: "fnDotBounce")
            }
        }
    }

    private func prepareNavigationLoading(for url: URL, completion: @escaping () -> Void) {
        guard !isPreparingNavigation else { completion(); return }
        isPreparingNavigation = true
        // 即使 WebKit 本次 takeSnapshot 失败，也先使用该页完成渲染时保存的单张预览。
        // 这样所有常规前进导航都有返回底图，不会在左滑时露出根视图黑色背景。
        let stablePreview = visiblePagePreview
        if let stablePreview { backPreviewImage = stablePreview }
        let config = WKSnapshotConfiguration()
        config.afterScreenUpdates = false
        webView.takeSnapshot(with: config) { [weak self] image, _ in
            guard let self else { completion(); return }
            // 只保留立即父页这一张预览，不建立截图栈。
            // 左缘返回拖动时 WebView 下方因此是真实上一页，不再露出黑色根视图。
            let parentPreview = image ?? stablePreview
            if let parentPreview { self.backPreviewImage = parentPreview }
            self.showNavigationLoading(for: url, snapshot: parentPreview)
            self.isPreparingNavigation = false
            completion()
        }
    }

    private func armBackPreviewFromCurrentPage() {
        if let visiblePagePreview { backPreviewImage = visiblePagePreview }
        let sourceIdentifier = webView.url?.absoluteString
        let config = WKSnapshotConfiguration()
        config.afterScreenUpdates = false
        webView.takeSnapshot(with: config) { [weak self] image, _ in
            guard let self,
                  self.webView.url?.absoluteString == sourceIdentifier,
                  let image else { return }
            self.backPreviewImage = image
        }
    }

    private func captureVisiblePagePreview() {
        guard !startupLoadingVisible, !edgeBackFinishing, let pageURL = webView.url else { return }
        let pageIdentifier = pageURL.absoluteString
        let config = WKSnapshotConfiguration()
        config.afterScreenUpdates = false
        webView.takeSnapshot(with: config) { [weak self] image, _ in
            guard let self,
                  !self.edgeBackFinishing,
                  self.webView.url?.absoluteString == pageIdentifier,
                  let image else { return }
            self.visiblePagePreview = image
        }
    }

    private func resetEdgeBackPresentation() {
        webView.transform = .identity
        edgeBackPreviewImageView.isHidden = true
        edgeBackPreviewImageView.image = nil
        edgeBackActive = false
        edgeBackFinishing = false
    }

    private func hideNavigationLoading() {
        navigationReadyTimer?.invalidate()
        navigationReadyTimer = nil
        navigationLoadingImage.stopAnimating()
        navigationCircleLoader.removeAllAnimations()
        navigationCircleLoader.isHidden = true
        navigationPercentTimer?.invalidate()
        navigationPercentTimer = nil
        navigationDots.arrangedSubviews.forEach { $0.layer.removeAllAnimations() }
        navigationSnapshotImage.image = nil
        navigationLoadingOverlay.isHidden = true
        pendingNavigationURL = nil
    }

    private func pageKey(for url: URL?) -> String {
        guard let url else { return "" }
        let query = URLComponents(url: url, resolvingAgainstBaseURL: false)?.queryItems ?? []
        if query.contains(where: { $0.name == "do" && $0.value == "gamelist" }) || url.path.lowercased().contains("gamelist.php") { return "gamelist" }
        if query.contains(where: { $0.name == "do" && $0.value == "roomdoor" }) || url.path.lowercased().contains("roomdoor.php") { return "roomdoor" }
        if query.contains(where: { $0.name == "do" && $0.value == "room" }) {
            return "room:" + (query.first(where: { $0.name == "game" })?.value ?? "default")
        }
        return url.path
    }

    private func pollPageReady() {
        navigationReadyTimer?.invalidate()
        let started = Date()
        let key = pageKey(for: pendingNavigationURL ?? webView.url)
        let fastPath = warmedPageKeys.contains(key)
        navigationReadyTimer = Timer.scheduledTimer(withTimeInterval: 0.12, repeats: true) { [weak self] timer in
            guard let self else { timer.invalidate(); return }
            let script = """
            (function(){
              var p=location.pathname+location.search;
              function ok(i){return !i||i.complete;}
              if(/(?:do=gamelist|gamelist\\.php)/i.test(p)) {
                var hero=document.querySelector('#homeBanner img');
                return window.__fnHallBannerReady===true&&window.__fnHallBannerImageReady===true&&window.__fnHallBannerRealImageReady===true&&window.__fnHallDataReady===true&&ok(hero)&&document.querySelectorAll('.game-box').length>0;
              }
              if(/(?:do=roomdoor|roomdoor\\.php)/i.test(p)) {
                var user=document.querySelector('.userinfo img');
                var history=document.querySelector('.roomrow .room-avatar img');
                return !!document.querySelector('#numinput')&&ok(user)&&ok(history);
              }
              if(/[?&]do=room(?:&|$)/i.test(p)) {
                return document.readyState!=='loading'&&!!document.querySelector('#chat_list')&&typeof window.ROOM_BOOT_CHAT!=='undefined';
              }
              return document.readyState!=='loading'&&!!document.body;
            })();
            """
            self.webView.evaluateJavaScript(script) { result, _ in
                let elapsed = Date().timeIntervalSince(started)
                let ready = (result as? Bool) == true
                let isGameRoom = key.hasPrefix("room:")
                let isHall = key == "gamelist"
                let timeout = isGameRoom ? (fastPath ? 0.75 : 2.2) : (fastPath ? 1.2 : 5.0)
                // 大厅遮罩只在首张真实轮播图完成解码后撤掉。占位图可防止页面区域空白，
                // 但不能作为大厅已完成的信号；也不能由计时器提前放行。
                if ready || (!isHall && elapsed > timeout) {
                    if ready && !key.isEmpty {
                        self.warmedPageKeys.insert(key)
                        UserDefaults.standard.set(Array(self.warmedPageKeys), forKey: "fnWarmedPageKeys")
                    }
                    self.hideNavigationLoading()
                }
            }
        }
    }

    private func startStartupProgress() {
        startupProgress = 0
        updateStartupProgress(0)
        startupProgressTimer?.invalidate()
        startupProgressTimer = Timer.scheduledTimer(withTimeInterval: 0.05, repeats: true) { [weak self] timer in
            guard let self, self.startupLoadingVisible else { timer.invalidate(); return }
            let ceiling: CGFloat = self.webView.estimatedProgress > 0 ? 0.94 : 0.38
            let desired = min(ceiling, CGFloat(self.webView.estimatedProgress) * 0.82 + 0.10)
            // HTML 版是 35ms 连续前进；这里同样保证每帧在走，同时不超过 WebKit 真实进度上限。
            let continuouslyAdvancing = self.startupProgress + 0.003
            let target = min(ceiling, min(max(desired, continuouslyAdvancing), self.startupProgress + 0.012))
            self.startupProgress = target
            self.updateStartupProgress(target)
        }
    }

    private func setupStartupLoadingView() {
        startupOverlay.translatesAutoresizingMaskIntoConstraints = false
        startupOverlay.backgroundColor = .systemBlue
        startupOverlay.isUserInteractionEnabled = true
        view.addSubview(startupOverlay)
        NSLayoutConstraint.activate([
            startupOverlay.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            startupOverlay.trailingAnchor.constraint(equalTo: view.trailingAnchor),
            startupOverlay.topAnchor.constraint(equalTo: view.topAnchor),
            startupOverlay.bottomAnchor.constraint(equalTo: view.bottomAnchor)
        ])

        startupImageView.image = bundledImage(named: "startup_loading.jpg")
        startupImageView.contentMode = .scaleAspectFill
        startupImageView.translatesAutoresizingMaskIntoConstraints = false
        startupOverlay.addSubview(startupImageView)
        NSLayoutConstraint.activate([
            startupImageView.leadingAnchor.constraint(equalTo: startupOverlay.leadingAnchor), startupImageView.trailingAnchor.constraint(equalTo: startupOverlay.trailingAnchor),
            startupImageView.topAnchor.constraint(equalTo: startupOverlay.topAnchor), startupImageView.bottomAnchor.constraint(equalTo: startupOverlay.bottomAnchor)
        ])
        startupProgressTrack.translatesAutoresizingMaskIntoConstraints = false
        // 正式版首屏：深蓝半透明轨道配细蓝青渐变条。
        startupProgressTrack.backgroundColor = UIColor(red: 16/255, green: 71/255, blue: 150/255, alpha: 0.86)
        startupProgressTrack.layer.cornerRadius = 4
        startupProgressTrack.layer.shadowColor = UIColor.black.cgColor
        startupProgressTrack.layer.shadowOpacity = 0.12
        startupProgressTrack.layer.shadowRadius = 2
        startupProgressTrack.layer.shadowOffset = CGSize(width: 0, height: 1)
        startupProgressTrack.layer.masksToBounds = false
        startupOverlay.addSubview(startupProgressTrack)
        startupProgressBar.translatesAutoresizingMaskIntoConstraints = false
        startupProgressBar.backgroundColor = .clear
        startupProgressBar.layer.cornerRadius = 4
        startupProgressBar.layer.masksToBounds = true
        startupProgressGradient.colors = [
            UIColor(red: 17/255, green: 72/255, blue: 166/255, alpha: 1).cgColor,
            UIColor(red: 10/255, green: 197/255, blue: 210/255, alpha: 1).cgColor,
            UIColor(red: 17/255, green: 213/255, blue: 201/255, alpha: 1).cgColor
        ]
        startupProgressGradient.locations = [0, 0.5, 1]
        startupProgressGradient.startPoint = CGPoint(x: 0, y: 0.5)
        startupProgressGradient.endPoint = CGPoint(x: 1, y: 0.5)
        startupProgressGradient.shadowColor = UIColor.white.cgColor
        startupProgressGradient.shadowOpacity = 0.4
        startupProgressGradient.shadowRadius = 4
        startupProgressBar.layer.insertSublayer(startupProgressGradient, at: 0)
        let shimmer = CABasicAnimation(keyPath: "locations")
        shimmer.fromValue = [-0.5, 0.0, 0.5]
        shimmer.toValue = [0.5, 1.0, 1.5]
        shimmer.duration = 1.2
        shimmer.repeatCount = .infinity
        startupProgressGradient.add(shimmer, forKey: "fnProgressLight")
        startupProgressTrack.addSubview(startupProgressBar)
        startupProgressWidthConstraint = startupProgressBar.widthAnchor.constraint(equalToConstant: 0)
        NSLayoutConstraint.activate([
            startupProgressTrack.leadingAnchor.constraint(equalTo: startupOverlay.leadingAnchor, constant: 42), startupProgressTrack.trailingAnchor.constraint(equalTo: startupOverlay.trailingAnchor, constant: -42),
            startupProgressTrack.bottomAnchor.constraint(equalTo: startupOverlay.safeAreaLayoutGuide.bottomAnchor, constant: -50), startupProgressTrack.heightAnchor.constraint(equalToConstant: 8),
            startupProgressBar.leadingAnchor.constraint(equalTo: startupProgressTrack.leadingAnchor), startupProgressBar.topAnchor.constraint(equalTo: startupProgressTrack.topAnchor), startupProgressBar.bottomAnchor.constraint(equalTo: startupProgressTrack.bottomAnchor), startupProgressWidthConstraint
        ])
        startupVersionLabel.translatesAutoresizingMaskIntoConstraints = false
        let appVersion = Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? ""
        let buildNumber = Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String ?? ""
        startupVersionLabel.text = buildNumber.isEmpty ? "v\(appVersion)" : "v\(appVersion)（Build \(buildNumber)）"
        startupVersionLabel.textColor = UIColor.white.withAlphaComponent(0.94)
        startupVersionLabel.font = .systemFont(ofSize: 15, weight: .regular)
        startupVersionLabel.textAlignment = .center
        startupOverlay.addSubview(startupVersionLabel)
        NSLayoutConstraint.activate([
            startupVersionLabel.centerXAnchor.constraint(equalTo: startupOverlay.centerXAnchor),
            startupVersionLabel.topAnchor.constraint(equalTo: startupProgressTrack.bottomAnchor, constant: 8)
        ])
        startupSpinner.isHidden = true

        startupTitle.translatesAutoresizingMaskIntoConstraints = false
        startupTitle.text = "正在加载问界"
        startupTitle.isHidden = true
        startupTitle.textColor = .white
        startupTitle.font = .systemFont(ofSize: 16, weight: .medium)
        startupTitle.textAlignment = .center
        startupOverlay.addSubview(startupTitle)
        NSLayoutConstraint.activate([
            startupTitle.centerXAnchor.constraint(equalTo: startupOverlay.centerXAnchor),
            startupTitle.bottomAnchor.constraint(equalTo: startupProgressTrack.topAnchor, constant: -16)
        ])

        adOverlay.translatesAutoresizingMaskIntoConstraints = false
        adOverlay.backgroundColor = .black
        adImageView.translatesAutoresizingMaskIntoConstraints = false
        adImageView.image = bundledImage(named: "startup_ad.jpg")
        adImageView.contentMode = .scaleAspectFill
        adOverlay.addSubview(adImageView)
        adSkipButton.translatesAutoresizingMaskIntoConstraints = false
        adSkipButton.setTitle("跳过3", for: .normal)
        adSkipButton.setTitleColor(.white, for: .normal)
        adSkipButton.backgroundColor = UIColor.white.withAlphaComponent(0.32)
        adSkipButton.layer.cornerRadius = 17
        adSkipButton.layer.masksToBounds = true
        adSkipButton.titleLabel?.font = .systemFont(ofSize: 14, weight: .regular)
        adSkipButton.addTarget(self, action: #selector(skipAd), for: .touchUpInside)
        adOverlay.addSubview(adSkipButton)
        view.addSubview(adOverlay)
        NSLayoutConstraint.activate([
            adOverlay.leadingAnchor.constraint(equalTo: view.leadingAnchor), adOverlay.trailingAnchor.constraint(equalTo: view.trailingAnchor),
            adOverlay.topAnchor.constraint(equalTo: view.topAnchor), adOverlay.bottomAnchor.constraint(equalTo: view.bottomAnchor),
            adImageView.leadingAnchor.constraint(equalTo: adOverlay.leadingAnchor), adImageView.trailingAnchor.constraint(equalTo: adOverlay.trailingAnchor),
            adImageView.topAnchor.constraint(equalTo: adOverlay.topAnchor), adImageView.bottomAnchor.constraint(equalTo: adOverlay.bottomAnchor),
            adSkipButton.topAnchor.constraint(equalTo: adOverlay.safeAreaLayoutGuide.topAnchor, constant: 6), adSkipButton.trailingAnchor.constraint(equalTo: adOverlay.trailingAnchor, constant: -6),
            adSkipButton.widthAnchor.constraint(equalToConstant: 72), adSkipButton.heightAnchor.constraint(equalToConstant: 34)
        ])
        adOverlay.isHidden = true
    }

    private func bundledImage(named name: String) -> UIImage? {
        guard let root = Bundle.main.url(forResource: "fn_static", withExtension: nil) else { return nil }
        return UIImage(contentsOfFile: root.appendingPathComponent(name).path)
    }

    private func preloadBundledFrontendThenLoadHome() {
        // 首次启动 WKWebView 需要编译 WebKit 框架，直接加载远程页面会白屏。
        // 先加载本地 shell 让 WebView 有内容渲染，编译完成后再切换到远程页面。
        loadBundledShellThenHome()
    }

    private func loadBundledShellThenHome() {
        if let shell = Bundle.main.url(forResource: "offline-shell", withExtension: "html") {
            webView.loadFileURL(shell, allowingReadAccessTo: Bundle.main.bundleURL)
        }
        // 先让包内固定框架绘制出来，再切换到动态业务页；不再展示纯蓝“正在连接”遮罩。
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.12) { [weak self] in
            self?.loadHome()
        }
    }

    private func loadHome(cachePolicy: URLRequest.CachePolicy = .useProtocolCachePolicy) {
        var request = URLRequest(url: Self.homeURL)
        request.cachePolicy = cachePolicy
        request.timeoutInterval = 20
        statusLabel.isHidden = true
        retryButton.isHidden = true
        webView.load(request)
    }

    private func updateStartupProgress(_ value: CGFloat) {
        let clamped = max(0, min(1, value))
        startupProgressWidthConstraint.constant = max(0, (view.bounds.width - 104) * clamped)
        UIView.animate(withDuration: 0.05, delay: 0, options: [.curveLinear, .beginFromCurrentState]) {
            self.startupOverlay.layoutIfNeeded()
            self.startupProgressGradient.frame = self.startupProgressBar.bounds
        }
    }

    private func hideStartupLoading() {
        guard startupLoadingVisible else { return }
        startupLoadingVisible = false
        startupProgressTimer?.invalidate()
        startupProgressGradient.removeAnimation(forKey: "fnProgressLight")
        // 移除 init 阶段添加的冷启动覆盖层
        if let window = UIApplication.shared.windows.first ?? (UIApplication.shared.value(forKey: "statusBarWindow") as? UIWindow)?.window ?? nil {
            window.viewWithTag(9999)?.removeFromSuperview()
        }
        // WebKit 完成导航不等于用户已经看见进度条走完。先从当前可见进度连续补到
        // 100%，动画完成后才切到下一页，避免进度条半途消失。
        let remaining = max(0, 1 - startupProgress)
        let finishDuration = max(0.55, min(1.0, 0.32 + remaining * 0.72))
        startupProgress = 1
        startupProgressWidthConstraint.constant = max(1, (view.bounds.width - 104))
        UIView.animate(withDuration: finishDuration, delay: 0, options: [.curveEaseOut, .beginFromCurrentState]) {
            self.startupOverlay.layoutIfNeeded()
            self.startupProgressGradient.frame = self.startupProgressBar.bounds
        } completion: { _ in
            self.startupSpinner.stopAnimating()
            self.startupOverlay.isHidden = true
            self.startupOverlay.alpha = 1
            // 无论是冷启动还是长时间停留后重新进入，都保持同一条启动链路：
            // 首屏加载完成 -> 广告页 -> 业务页面。旧分支会在自动重新进入时跳过广告。
            self.isIdlePageRefreshActive = false
            self.showAdOverlay()
        }
    }

    /// 只以“完成一次页面导航”为计时起点，不会因聊天、下注等同页操作不断延期。
    private func armIdlePageRefreshTimer() {
        idlePageRefreshTimer?.invalidate()
        guard !startupLoadingVisible, !isIdlePageRefreshActive else { return }
        let elapsed = Date().timeIntervalSince(lastCompletedPageNavigationAt)
        let delay = max(0.1, idlePageRefreshInterval - elapsed)
        let timer = Timer(timeInterval: delay, repeats: false) { [weak self] _ in
            self?.beginIdlePageRefresh()
        }
        RunLoop.main.add(timer, forMode: .common)
        idlePageRefreshTimer = timer
    }

    @objc private func handleAppWillEnterForeground() {
        guard !startupLoadingVisible, !isIdlePageRefreshActive else { return }
        if Date().timeIntervalSince(lastCompletedPageNavigationAt) >= idlePageRefreshInterval {
            beginIdlePageRefresh()
        } else {
            armIdlePageRefreshTimer()
        }
    }

    private func beginIdlePageRefresh() {
        guard !startupLoadingVisible, !isIdlePageRefreshActive, adOverlay.isHidden else { return }
        isIdlePageRefreshActive = true
        idlePageRefreshTimer?.invalidate()
        startupProgressTimer?.invalidate()
        startupProgress = 0
        updateStartupProgress(0)
        startupOverlay.alpha = 1
        startupOverlay.isHidden = false
        startupLoadingVisible = true
        startStartupProgress()
        // 模拟用户重新打开 App：不保留当前房间 URL，而是从应用入口重新请求。
        // Cookie/登录态仍由 WebKit 保存，因此会遵循入口页原有的登录或自动进入逻辑。
        isInitialNavigation = true
        navigationLoadingOverlay.isHidden = true
        pendingNavigationURL = Self.homeURL
        visiblePagePreview = nil
        backPreviewImage = nil
        loadHome(cachePolicy: .reloadIgnoringLocalCacheData)
    }

    private func showAdOverlay() {
        guard adOverlay.superview != nil else { return }
        adSeconds = 3; adOverlay.isHidden = false
        adTimer?.invalidate(); adTimer = Timer.scheduledTimer(withTimeInterval: 1, repeats: true) { [weak self] timer in
            guard let self else { timer.invalidate(); return }; self.adSeconds -= 1; self.adSkipButton.setTitle("跳过\(self.adSeconds)", for: .normal); if self.adSeconds <= 0 { timer.invalidate(); self.skipAd() }
        }
    }
    @objc private func skipAd() {
        adTimer?.invalidate()
        adOverlay.isHidden = true
        armIdlePageRefreshTimer()
    }

    func gestureRecognizerShouldBegin(_ gestureRecognizer: UIGestureRecognizer) -> Bool {
        // 预览缺失时禁用手势返回而不是拖出黑色根视图；页面自身的返回按钮仍然可用。
        guard gestureRecognizer === edgeBackGesture,
              !edgeBackActive, !edgeBackFinishing,
              !startupLoadingVisible, adOverlay.isHidden, navigationLoadingOverlay.isHidden,
              webView.canGoBack, backPreviewImage != nil else { return false }
        return true
    }

    @objc private func handleEdgeBack(_ gesture: UIScreenEdgePanGestureRecognizer) {
        let distance = max(0, gesture.translation(in: view).x)
        switch gesture.state {
        case .began:
            guard let preview = backPreviewImage else {
                gesture.isEnabled = false
                gesture.isEnabled = true
                return
            }
            edgeBackActive = true
            webView.layer.removeAllAnimations()
            edgeBackPreviewImageView.image = preview
            edgeBackPreviewImageView.isHidden = false
        case .changed:
            webView.transform = CGAffineTransform(translationX: distance, y: 0)
        case .ended:
            let commit = distance / max(view.bounds.width, 1) > 0.24
            edgeBackActive = false
            if commit {
                edgeBackFinishing = true
                UIView.animate(withDuration: 0.18, animations: {
                    self.webView.transform = CGAffineTransform(translationX: self.view.bounds.width, y: 0)
                }, completion: { _ in
                    self.webView.goBack()
                })
            } else {
                UIView.animate(withDuration: 0.2, animations: { self.webView.transform = .identity }) { _ in
                    self.edgeBackPreviewImageView.isHidden = true
                }
            }
        default:
            edgeBackActive = false
            UIView.animate(withDuration: 0.2, animations: { self.webView.transform = .identity }) { _ in
                self.edgeBackPreviewImageView.isHidden = true
            }
        }
    }

    @objc private func retryLoad() { loadHome() }

    /// 房间号登录页不属于可返回页面。透明左缘屏障命中后由这个手势完整吞掉拖动，
    /// 防止 WKWebView 已经缓存的 back/forward 手势在开关切换的边界帧继续接管。
    @objc private func blockRoomDoorEdgePan(_ gesture: UIPanGestureRecognizer) {
        if gesture.state == .began || gesture.state == .changed {
            webView.transform = .identity
        }
    }

    override func viewWillDisappear(_ animated: Bool) {
        super.viewWillDisappear(animated)
        // 页面离开或系统返回时，先通知房间页销毁直播 iframe，避免声音继续留在后台。
        webView.evaluateJavaScript("try{window.__fnStopRoomLive&&window.__fnStopRoomLive();}catch(e){}", completionHandler: nil)
    }

    override func viewDidDisappear(_ animated: Bool) {
        super.viewDidDisappear(animated)
        if isBeingDismissed { webView.stopLoading() }
    }

    func webView(_ webView: WKWebView, didStartProvisionalNavigation navigation: WKNavigation!) {
        statusLabel.isHidden = true
        retryButton.isHidden = true
        // 覆盖 target=_self、脚本跳转等未经过 prepareNavigationLoading 的前进路径。
        if !isInitialNavigation, !edgeBackFinishing, let visiblePagePreview {
            backPreviewImage = visiblePagePreview
        }
    }

    /// didFinish 会等待图片、脚本等所有子资源；其中任何一个慢或长连接都会让旧代码一直转圈。
    /// 主 HTML 一旦提交即可让用户操作页面，后续资源继续由 WebKit 缓存加载。
    func webView(_ webView: WKWebView, didCommit navigation: WKNavigation!) {
        statusLabel.isHidden = true
        retryButton.isHidden = true
        updatePagePresentation(for: webView.url)
        if !isInitialNavigation && !navigationLoadingOverlay.isHidden {
            // 不再等待 didFinish（它会被慢图片/长连接拖住），HTML 提交后立即检查目标页关键内容。
            pollPageReady()
        }
    }

    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        if edgeBackFinishing {
            // 上一页真实文档完成后再移除预览，避免返回途中露出黑/白帧。
            resetEdgeBackPresentation()
            backPreviewImage = nil
        }
        hideStartupLoading()
        if isInitialNavigation { isInitialNavigation = false }
        else if !navigationLoadingOverlay.isHidden && navigationReadyTimer == nil { pollPageReady() }
        lastCompletedPageNavigationAt = Date()
        statusLabel.isHidden = true
        retryButton.isHidden = true
        updatePagePresentation(for: webView.url)
        captureVisiblePagePreview()
        if !startupLoadingVisible && !isIdlePageRefreshActive { armIdlePageRefreshTimer() }
    }

    func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) {
        if edgeBackFinishing { resetEdgeBackPresentation() }
        hideStartupLoading()
        showLoadFailure()
    }

    func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) {
        if edgeBackFinishing { resetEdgeBackPresentation() }
        hideStartupLoading()
        showLoadFailure()
    }

    private func showLoadFailure() {
        statusLabel.isHidden = false
        statusLabel.text = "暂时无法连接服务器"
        retryButton.isHidden = false
    }

    func webView(
        _ webView: WKWebView,
        decidePolicyFor navigationAction: WKNavigationAction,
        decisionHandler: @escaping (WKNavigationActionPolicy) -> Void
    ) {
        guard let url = navigationAction.request.url else {
            decisionHandler(.cancel)
            return
        }
        if ["http", "https"].contains(url.scheme?.lowercased() ?? "") {
            let isMainFrame = navigationAction.targetFrame?.isMainFrame ?? true
            let isBackForward = navigationAction.navigationType == .backForward
            if isMainFrame && !isBackForward {
                updatePagePresentation(for: url)
            }
            if !isInitialNavigation && !isIdlePageRefreshActive && isMainFrame && !isBackForward && navigationLoadingOverlay.isHidden {
                prepareNavigationLoading(for: url) { decisionHandler(.allow) }
            } else {
                decisionHandler(.allow)
            }
            return
        }
        if url.scheme?.lowercased() == "googleauthenticator" {
            UIApplication.shared.open(url, options: [:]) { opened in
                guard !opened, let storeURL = URL(string: "https://apps.apple.com/app/google-authenticator/id388497605") else { return }
                UIApplication.shared.open(storeURL)
            }
            decisionHandler(.cancel)
            return
        }
        if UIApplication.shared.canOpenURL(url) {
            UIApplication.shared.open(url)
        }
        decisionHandler(.cancel)
    }

    func webView(
        _ webView: WKWebView,
        createWebViewWith configuration: WKWebViewConfiguration,
        for navigationAction: WKNavigationAction,
        windowFeatures: WKWindowFeatures
    ) -> WKWebView? {
        if let url = navigationAction.request.url { webView.load(URLRequest(url: url)) }
        return nil
    }

    func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
        guard message.name == "fnPrepareBackPreview", !edgeBackFinishing else { return }
        armBackPreviewFromCurrentPage()
    }

    deinit {
        idlePageRefreshTimer?.invalidate()
        NotificationCenter.default.removeObserver(self)
        webView.configuration.userContentController.removeScriptMessageHandler(forName: "fnPrepareBackPreview")
    }
}
