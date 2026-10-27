/*
 * Project Security Manager — Admin prototype v2: song ngữ Việt / Nhật.
 * HTML viết bằng tiếng Việt; khi chọn tiếng Nhật, text node và thuộc tính
 * được tra trong từ điển bên dưới (khớp nguyên câu, sau đó tới mẫu regex).
 * Khi dựng bằng Filament, từ điển này tương ứng với lang/ja.json.
 */

const I18N = (() => {
    const ja = {
        // Điều hướng & khung
        'Tổng quan': '概要',
        Findings: '検出結果',
        Runtimes: 'ランタイム',
        'Tin bảo mật': 'セキュリティ情報',
        'Danh mục': '管理対象',
        Projects: 'プロジェクト',
        Services: 'サービス',
        Assets: 'アセット',
        'Cài đặt': '設定',
        Teams: 'チーム',
        'Sức khỏe hệ thống': 'システム状態',
        Admin: '管理者',
        'Tìm kiếm': '検索',
        'Điều hướng': 'ナビゲーション',
        'Chưa có trong prototype': 'プロトタイプ未対応',
        'Đóng menu': 'メニューを閉じる',
        'Mở menu': 'メニューを開く',
        Breadcrumb: 'パンくずリスト',
        'Ngôn ngữ': '言語',
        'Tìm service, finding, team…': 'サービス・検出結果・チームを検索…',
        'Không có kết quả': '結果がありません',
        Trang: 'ページ',
        Team: 'チーム',
        'Project · Commerce': 'プロジェクト · Commerce',
        'Finding · Critical': '検出結果 · Critical',
        'Đã lưu lúc vừa xong': 'たった今保存しました',

        // Giao diện (theme)
        'Giao diện': '外観',
        'Trắng – đen, ít màu nhất': '白と黒・最小限の色',
        'Nhấn xanh dương': '青のアクセント',
        'Nhấn teal, nền ấm': 'Teal のアクセント・温かみのある背景',
        'Giao diện tối': 'ダークモード',

        // Bảng prototype
        Prototype: 'プロトタイプ',
        'Tone màu': '配色',
        'Trạng thái dữ liệu': 'データの状態',
        'Có dữ liệu': 'データあり',
        'Không còn việc': '対応なし',
        'Đang tải': '読み込み中',
        'Lỗi tải trang': '読み込みエラー',
        'Nguồn dữ liệu lỗi': 'データソース障害',
        'Webhook Google Chat': 'Google Chat Webhook',

        // Tổng quan
        'Tổng quan — Security Manager': '概要 — Security Manager',
        'Thứ Sáu, 23/10/2026 · cập nhật 09:42': '2026年10月23日（金）· 09:42 更新',
        'Gửi bản tổng hợp': 'ダイジェストを送信',
        'OSV API lỗi 26 giờ — lỗ hổng package mới đang không được phát hiện':
            'OSV API が 26 時間エラー中 — 新しいパッケージの脆弱性を検出できていません',
        'Ngưỡng cảnh báo là 6 giờ. Số liệu package dưới đây là của lần quét 07:40 ngày 22/10.':
            'アラートのしきい値は 6 時間です。以下のパッケージの数値は 10/22 07:40 のスキャン結果です。',
        'Chạy lại': '再実行',
        'php.net phát hành bản vá cho 2 lỗ hổng critical (CVSS 9.2)':
            'php.net が Critical 脆弱性 2 件（CVSS 9.2）の修正版をリリース',
        '21/10/2026, 21:00 giờ VN · 3 service PHP chưa lên bản vá · legacy-cms chạy PHP 8.1 đã hết hỗ trợ':
            '2026/10/21 21:00（ベトナム時間）· 未適用の PHP サービス 3 件 · legacy-cms はサポート終了の PHP 8.1',
        'Xem 4 service →': '4 サービスを表示 →',
        'Số liệu': '指標',
        'Finding đang mở': '未解決の検出結果',
        'Quá SLA': 'SLA 超過',
        'Lâu nhất: quá 2 ngày': '最長: 2 日超過',
        'Runtime hết hỗ trợ': 'サポート終了のランタイム',
        'Asset mất liên lạc': '応答のないアセット',
        'Không có': 'なし',
        'Asset thiếu dữ liệu': 'データ不足のアセット',
        'Không được tính là Đạt': '「合格」とは見なしません',
        'Chế độ xem': 'ビュー',
        'Cần xử lý': '要対応',
        'Team của tôi': '自分のチーム',
        'j / k để di chuyển': 'j / k で移動',
        Mức: '重大度',
        'Vấn đề': '問題',
        Service: 'サービス',
        Hạn: '期限',
        'Trạng thái': 'ステータス',
        'Đã nhận': '確認済み',
        Mới: '新規',
        'PHP thiếu bản vá 21/10/2026': 'PHP 2026/10/21 の修正版が未適用',
        'Runtime thiếu bản vá bảo mật': 'ランタイムのセキュリティ修正が未適用',
        'Node.js 20 hết hỗ trợ': 'Node.js 20 サポート終了',
        'PHP 8.1 hết hỗ trợ': 'PHP 8.1 サポート終了',
        'Không còn việc cần xử lý': '対応が必要な項目はありません',
        'Lần quét gần nhất 12 phút trước.': '最終スキャン: 12 分前',
        '6 trên 14 finding': '14 件中 6 件',
        'Xem tất cả': 'すべて表示',
        'Nguồn dữ liệu': 'データソース',
        'Chi tiết': '詳細',
        'Lỗi 26 giờ': '26 時間エラー',
        'Theo team': 'チーム別',
        'Đang mở': '未解決',
        'Không team nào có finding đang mở.': '未解決の検出結果があるチームはありません。',
        'Không tải được trang tổng quan': '概要ページを読み込めませんでした',
        'Máy chủ trả lỗi lúc 09:42. Cảnh báo Google Chat vẫn được gửi bình thường.':
            '09:42 にサーバーエラーが発生しました。Google Chat への通知は通常どおり送信されています。',
        'Thử lại': '再試行',
        'Đã gửi bản tổng hợp tới 3 team.': '3 チームにダイジェストを送信しました。',
        'OSV API vẫn trả lỗi 503.': 'OSV API は引き続き 503 エラーを返しています。',
        'Mở Findings lọc theo tin php.net.': 'php.net の情報で絞り込んだ検出結果を開きます。',
        'Mở Findings: đang mở.': '検出結果を開きます（未解決）。',
        'Mở Findings: quá SLA.': '検出結果を開きます（SLA 超過）。',
        'Mở Runtimes: hết hỗ trợ.': 'ランタイムを開きます（サポート終了）。',
        'Mở Assets: mất liên lạc.': 'アセットを開きます（応答なし）。',
        'Mở Assets: thiếu dữ liệu.': 'アセットを開きます（データ不足）。',
        'Mở chi tiết finding PHP thiếu bản vá.': 'PHP 修正未適用の詳細を開きます。',
        'Mở chi tiết finding Node.js 20.': 'Node.js 20 の詳細を開きます。',
        'Mở chi tiết finding PHP 8.1.': 'PHP 8.1 の詳細を開きます。',
        'Mở Findings.': '検出結果を開きます。',
        'Mở Sức khỏe hệ thống.': 'システム状態を開きます。',
        'Đã tải lại.': '再読み込みしました。',

        // Tổng quan (bản thu gọn, overview.html)
        'Lỗi một phần': '一部エラー',
        'Không kiểm tra được giám sát': '監視状態を確認できない',
        'Thứ Sáu, 23/10/2026 · kiểm tra lúc 09:42': '2026年10月23日（金）· 09:42 に確認',
        'Sự cố giám sát': '監視の異常',
        'OSV thành công lần cuối 26 giờ trước (ngưỡng 12 giờ)': 'OSV の最終成功は 26 時間前（しきい値 12 時間）',
        'Có thể bỏ sót lỗ hổng mới của package npm. Finding đang mở vẫn giữ nguyên.':
            'npm パッケージの新しい脆弱性を見逃す可能性があります。未解決の検出結果はそのまま残ります。',
        'Xem lỗi': 'エラーを表示',
        'HTTP 503 Service Unavailable · lần thử gần nhất 09:30': 'HTTP 503 Service Unavailable · 最終試行 09:30',
        'Tin Google Chat chưa gửi được': '未送信の Google Chat メッセージ',
        'Space admin: 2 tin lỗi từ 08:15 (HTTP 403, #318 và #319) — cảnh báo nguồn lỗi chưa tới admin':
            '管理者スペース: 08:15 から 2 件失敗（HTTP 403、#318 と #319）— データソース障害の通知が管理者に届いていません',
        'Cấu hình:': '設定:',
        'Gửi lại sau khi sửa:': '修正後の再送:',
        'Booking: 3 tin chưa gửi từ 22/10 14:05 (HTTP 404)': 'Booking: 10/22 14:05 から 3 件未送信（HTTP 404）',
        'Sửa team': 'チームを編集',
        'Internal Tools: chưa có webhook': 'Internal Tools: Webhook 未設定',
        '3 service chưa được đánh giá đầy đủ': '評価が不完全なサービス 3 件',
        'Runtime chưa rõ trạng thái hoặc lockfile không đọc được. Không tính là Đạt.':
            'ランタイムの状態が不明、または lockfile を読み取れません。「合格」とは見なしません。',
        'Xem 3 service →': '3 サービスを表示 →',
        'Finding ưu tiên': '優先対応の検出結果',
        'CISA KEV, quá hạn, hoặc Critical / High chưa đọc': 'CISA KEV・期限超過・未読の Critical / High',
        'Không tải được': '読み込めませんでした',
        'Đã đọc': '既読',
        'Chưa đọc': '未読',
        'Không có finding ưu tiên': '優先対応の検出結果はありません',
        'Finding đang mở khác vẫn có ở màn Findings.': 'その他の未解決の検出結果は検出結果画面で確認できます。',
        'Mở Findings': '検出結果を開く',
        'Không tải được finding ưu tiên': '優先対応の検出結果を読み込めませんでした',
        'Số đếm không được coi là 0. Các phần tải được vẫn hiển thị.':
            '件数は 0 とは見なしません。読み込めた項目は引き続き表示しています。',
        'Incident cần xem': '確認が必要なインシデント',
        'Cần đánh giá hoặc đánh giá lại ảnh hưởng, hoặc có gợi ý gộp đang chờ': '影響の評価・再評価、または統合提案の確認が必要',
        'nodejs.org báo trước bản vá bảo mật': 'nodejs.org がセキュリティ修正版を事前告知',
        'Không có incident cần xem': '確認が必要なインシデントはありません',
        'Incident khác vẫn có ở màn Incidents.': 'その他のインシデントはインシデント画面で確認できます。',
        'Mở Incidents': 'インシデントを開く',
        'Kiểm tra lúc 09:42': '09:42 に確認',
        'Không có nguồn đã ghi nhận nào quá ngưỡng': '記録済みのデータソースにしきい値超過はありません',
        'Không đọc được thời điểm cập nhật của nguồn': 'データソースの更新時刻を読み込めません',
        'Không đọc được lịch sử gửi Google Chat': 'Google Chat の送信履歴を読み込めません',
        'Không kiểm tra được sự cố giám sát': '監視の異常を確認できませんでした',
        'Chưa biết nguồn dữ liệu và việc gửi Google Chat có đang lỗi hay không.': 'データソースや Google Chat の送信に障害があるかどうか不明です。',
        '1 nguồn quá ngưỡng': 'しきい値超過のデータソース 1 件',
        'Ghi nhận gửi Google Chat gần nhất: 23/10, 08:00': '記録上の Google Chat 最終送信: 10/23 08:00',
        '12 nguồn đã ghi nhận': '記録済みのデータソース 12 件',
        'GitHub (lockfile)': 'GitHub（lockfile）',
        'Máy chủ trả lỗi lúc 09:42. Chưa xác minh được việc gửi tin Google Chat.':
            '09:42 にサーバーエラーが発生しました。Google Chat への送信状況は確認できていません。',

        // Teams
        'Teams — Security Manager': 'チーム — Security Manager',
        'Finding được gửi tới team sở hữu project bị ảnh hưởng.':
            '検出結果は、影響を受けるプロジェクトの担当チームに通知されます。',
        'Tạo team': 'チームを作成',
        'Thành viên': 'メンバー',
        'Finding mở': '未解決',
        'Hoạt động': '正常',
        Sửa: '編集',
        'Lỗi gửi': '送信エラー',
        'Gửi lỗi': '送信エラー',
        '3 lần liên tiếp · đang dùng email': '3 回連続 · メールで代替中',
        'Chưa cấu hình': '未設定',
        'Thành viên và webhook thêm sau khi tạo.': 'メンバーと Webhook は作成後に追加できます。',
        'Tên team': 'チーム名',
        'Nhập tên team.': 'チーム名を入力してください。',
        'Email leo thang': 'エスカレーション先メール',
        'Email không hợp lệ.': 'メールアドレスが正しくありません。',
        Hủy: 'キャンセル',
        'Tìm team': 'チームを検索',
        'Prototype chỉ dựng chi tiết Commerce. Trạng thái webhook lỗi / chưa cấu hình xem ở bảng Prototype trong trang Commerce.':
            'プロトタイプでは Commerce の詳細のみ作成しています。Webhook のエラー／未設定の状態は Commerce ページのプロトタイプパネルで確認できます。',
        'Đã tạo team.': 'チームを作成しました。',

        // Team
        '6 thành viên · 2 project · 6 finding đang mở': 'メンバー 6 名 · プロジェクト 2 件 · 未解決 6 件',
        'Gửi tin thử': 'テスト送信',
        'Thông tin chung': '基本情報',
        'Slug dùng trong URL. Đổi slug làm hỏng link cũ trong tin Chat.':
            'スラッグは URL に使われます。変更すると Chat の過去のリンクが無効になります。',
        Slug: 'スラッグ',
        'Chỉ dùng chữ thường, số và dấu gạch ngang.': '半角英小文字・数字・ハイフンのみ使用できます。',
        'Kênh cảnh báo chính. Một lỗ hổng ảnh hưởng nhiều service chỉ gửi một tin.':
            'メインの通知チャネルです。複数サービスに影響する脆弱性も 1 件のメッセージにまとめます。',
        'Gửi thử': 'テスト送信',
        Thay: '変更',
        '· tin gần nhất 08:00 hôm nay': '· 最終送信: 本日 08:00',
        'Gửi lỗi 3 lần liên tiếp (HTTP 404)': '3 回連続で送信失敗（HTTP 404）',
        '· cảnh báo đang gửi qua email leo thang': '· エスカレーション先メールで通知中',
        'Chưa có webhook. Team chỉ nhận cảnh báo qua email leo thang.':
            'Webhook が未設定です。通知はエスカレーション先メールにのみ送信されます。',
        'Thêm webhook': 'Webhook を追加',
        'Khi nào gửi': '送信タイミング',
        'Medium và Low luôn có trong bản tổng hợp hằng ngày.': 'Medium と Low は毎日のダイジェストに必ず含まれます。',
        'Gửi ngay khi có Critical hoặc High': 'Critical / High は即時送信',
        'Kể cả ngoài giờ làm việc': '業務時間外も含む',
        'Bản tổng hợp lúc 08:00': '08:00 のダイジェスト',
        'Thứ Hai – Thứ Sáu': '月曜〜金曜',
        'Thông báo sớm từ hãng': 'ベンダーからの事前告知',
        'Ví dụ: php.net báo trước ngày phát hành bản vá': '例: php.net による修正版リリース日の事前告知',
        'Dùng khi webhook lỗi, hoặc finding Critical quá SLA mà chưa ai nhận.':
            'Webhook の障害時や、Critical が SLA を超過しても誰も確認していない場合に使用します。',
        Email: 'メールアドレス',
        'Nhập email hợp lệ.': '正しいメールアドレスを入力してください。',
        'Nên dùng email nhóm thay vì email cá nhân.': '個人ではなくグループのアドレスを推奨します。',
        'Lưu thay đổi': '変更を保存',
        'Xóa team': 'チームを削除',
        'Chỉ xóa được khi team không còn project.': 'プロジェクトがないチームのみ削除できます。',
        'Team còn 2 project. Chuyển project sang team khác trước.':
            'プロジェクトが 2 件残っています。先に別のチームへ移してください。',
        'Đăng nhập bằng tài khoản Google Workspace': 'Google Workspace アカウントでログイン',
        'Thêm thành viên': 'メンバーを追加',
        Tên: '名前',
        'Vai trò': '役割',
        'Nhận cảnh báo': '通知',
        'Tech lead': 'テックリード',
        Bạn: 'あなた',
        Developer: '開発者',
        Xóa: '削除',
        'Chỉ xem': '閲覧のみ',
        'quan.do@example.com · chưa đăng nhập': 'quan.do@example.com · 未ログイン',
        Project: 'プロジェクト',
        Repository: 'リポジトリ',
        'Báo cáo gần nhất': '最終レポート',
        'Chỉ thêm được tài khoản @example.com.': '@example.com のアカウントのみ追加できます。',
        'Email phải thuộc tên miền @example.com.': '@example.com ドメインのメールアドレスを入力してください。',
        Thêm: '追加',
        'Xóa khỏi team?': 'チームから削除しますか？',
        'Người này sẽ không còn thấy finding của Commerce. Lịch sử xử lý vẫn được giữ.':
            'このメンバーは Commerce の検出結果を閲覧できなくなります。対応履歴は保持されます。',
        'Trong Google Chat: Space → Apps & integrations → Webhooks.':
            'Google Chat で「スペース → アプリと統合 → Webhook」を開きます。',
        'URL phải bắt đầu bằng https://chat.googleapis.com/v1/spaces/':
            'URL は https://chat.googleapis.com/v1/spaces/ で始まる必要があります',
        'Hệ thống gửi một tin thử trước khi lưu. Sau đó URL chỉ hiện một phần.':
            '保存前にテストメッセージを送信します。保存後は URL の一部のみ表示されます。',
        'Gửi thử & lưu': 'テスト送信して保存',
        'Đã gửi tin thử tới space commerce-security.': 'commerce-security スペースにテスト送信しました。',
        'Đã lưu cài đặt team Commerce.': 'Commerce チームの設定を保存しました。',
        'Gửi thử thành công.': 'テスト送信に成功しました。',
        'Prototype chỉ dựng Shop Platform.': 'プロトタイプでは Shop Platform のみ作成しています。',
        'Đã thêm thành viên.': 'メンバーを追加しました。',
        'Đã xóa thành viên khỏi team.': 'メンバーをチームから削除しました。',
        'Đã lưu webhook và gửi thử thành công.': 'Webhook を保存し、テスト送信に成功しました。',

        // Project
        '· 3 service · monorepo': '· 3 サービス · モノレポ',
        '· 2 Critical · 1 quá SLA': '· Critical 2 件 · SLA 超過 1 件',
        'Thêm service': 'サービスを追加',
        'Team sở hữu': '担当チーム',
        'Kênh cảnh báo': '通知チャネル',
        'Google Chat của team': 'チームの Google Chat',
        'Tạo lúc': '作成日時',
        Deployments: 'デプロイ',
        Runtime: 'ランタイム',
        Packages: 'パッケージ',
        'Báo cáo': 'レポート',
        'ECS · Public ·': 'ECS · 公開 ·',
        'Server · Nội bộ ·': 'サーバー · 社内 ·',
        'Thiếu bản vá bảo mật': 'セキュリティ修正未適用',
        Đạt: '合格',
        'Hết hỗ trợ': 'サポート終了',
        'Thiếu dữ liệu': 'データ不足',
        'Không tìm thấy composer.lock': 'composer.lock が見つかりません',
        '1 host': 'ホスト 1 台',
        'Môi trường': '環境',
        Commit: 'コミット',
        Release: 'リリース',
        'Deploy lúc': 'デプロイ日時',
        Instance: 'インスタンス',
        'Instance: số asset đã báo cáo trong 10 phút / tổng số asset của môi trường.':
            'インスタンス: 直近 10 分以内にレポートしたアセット数 / 環境内のアセット総数',
        'PHP 8.3.12 thiếu bản vá 21/10': 'PHP 8.3.12 · 10/21 の修正が未適用',
        'Sửa project': 'プロジェクトを編集',
        'Nhập tên project.': 'プロジェクト名を入力してください。',
        'Đổi team: 4 finding đang mở chuyển sang team mới.':
            'チームを変更すると、未解決の検出結果 4 件も新しいチームに移ります。',
        'Repository URL': 'リポジトリ URL',
        'Dùng URL https:// hoặc git@host:org/repo.': 'https:// または git@host:org/repo 形式で入力してください。',
        'Dùng để đọc lockfile tại commit đang deploy. Chỉ cần quyền đọc.':
            'デプロイ中のコミットの lockfile 読み取りに使用します。読み取り権限のみ必要です。',
        Lưu: '保存',
        'Danh sách Projects thuộc vòng sau.': 'プロジェクト一覧は次のラウンドで作成します。',
        'Wizard Thêm service thuộc vòng sau.': 'サービス追加ウィザードは次のラウンドで作成します。',
        'Trang service thuộc vòng sau.': 'サービス詳細ページは次のラウンドで作成します。',
        'Đã lưu thông tin project.': 'プロジェクト情報を保存しました。',

        // So sánh tone
        'So sánh tone màu — Security Manager': '配色の比較 — Security Manager',
        'Chọn tone màu': '配色を選ぶ',
        'Cùng một màn Tổng quan với 4 tone. Bấm một tone để mở prototype với tone đó.':
            '同じ概要画面を 4 つの配色で表示しています。クリックすると、その配色でプロトタイプを開きます。',
        'Trắng – đen, kiểu Vercel / Linear. Ít màu nhất. Filament: primary Zinc, gray Zinc.':
            '白と黒の Vercel / Linear 風。最も色数が少ない配色です。Filament: primary Zinc, gray Zinc。',
        'Xanh dương trên nền slate, kiểu GitHub / Stripe. Filament: primary Blue, gray Slate.':
            'Slate 背景に青のアクセント。GitHub / Stripe 風。Filament: primary Blue, gray Slate。',
        'Teal trên nền stone ấm, kiểu Supabase. Filament: primary Teal, gray Stone.':
            '温かみのある Stone 背景に Teal。Supabase 風。Filament: primary Teal, gray Stone。',
        'Dark mode, nhấn indigo, kiểu Linear. Filament: dark mode + primary Indigo.':
            'ダークモードに Indigo のアクセント。Linear 風。Filament: ダークモード + primary Indigo。',
    };

    /** Mẫu cho chuỗi có số / tên thay đổi. Hàm nhận các nhóm khớp. */
    const jaPatterns = [
        [/^(\d+) phút trước$/, (m, n) => `${n} 分前`],
        [/^(\d+) giờ trước$/, (m, n) => `${n} 時間前`],
        [/^Còn (\d+) giờ$/, (m, n) => `残り ${n} 時間`],
        [/^Còn (\d+) ngày$/, (m, n) => `残り ${n} 日`],
        [/^Quá (\d+) giờ$/, (m, n) => `${n} 時間超過`],
        [/^Quá (\d+) ngày$/, (m, n) => `${n} 日超過`],
        [/^(\d+) thành viên$/, (m, n) => `メンバー ${n} 名`],
        [/^(\d+) team$/, (m, n) => `${n} チーム`],
        [/^(\d+) service$/, (m, n) => `${n} サービス`],
        [/^(\d+) ECS task$/, (m, n) => `ECS タスク ${n} 件`],
        [/^(.+) · (\d+) giờ$/, (m, name, n) => `${name} · ${n} 時間`],
        [/^Đang chạy (.+)$/, (m, version) => `実行中: ${version}`],
        [/^(.+) · Package có lỗ hổng$/, (m, id) => `${id} · パッケージの脆弱性`],
        [/^(.+) nhận cảnh báo$/, (m, name) => `${name} の通知`],
        [/^Mở chi tiết (DEMO-[A-Z]+-\d+)\.$/, (m, id) => `${id} の詳細を開きます。`],
        [/^Service · (.+)$/, (m, rest) => `サービス · ${rest}`],
        [/^\+(\d+) finding khác$/, (m, n) => `ほか ${n} 件`],
        [/^(\d+) team cần đánh giá ảnh hưởng$/, (m, n) => `影響評価が必要なチーム ${n} 件`],
        [/^(\d+) gợi ý gộp đang chờ$/, (m, n) => `保留中の統合提案 ${n} 件`],
        [/^(\d+) (phút|giờ) trước · ngưỡng (\d+) giờ$/, (m, n, unit, limit) => `${n} ${unit === 'phút' ? '分' : '時間'}前 · しきい値 ${limit} 時間`],
        [/^Danh mục (.+)$/, (m, name) => `${name} カタログ`],
        [/^Tin báo trước (.+)$/, (m, name) => `${name} 事前告知`],
        [/^Mở (\/admin\/.*)$/, (m, url) => `${url} を開きます。`],
        [/^(\d{2})\/(\d{2}) (\d{2}:\d{2})$/, (m, day, month, time) => `${month}/${day} ${time}`],
        [/^(\d{2})\/(\d{2})\/(\d{4})$/, (m, day, month, year) => `${year}/${month}/${day}`],
        [/^(.+) thuộc vòng prototype sau\.$/, (m, name) => `${t(name)} は次のラウンドで作成します。`],
        [/^(.+) thuộc màn hình của vòng sau\.$/, (m, name) => `${t(name)} の画面は次のラウンドで作成します。`],
    ];

    const attributes = ['placeholder', 'aria-label', 'title'];
    const originalText = new WeakMap();
    const originalAttributes = new WeakMap();
    let lang = 'vi';

    function storedLang() {
        try {
            return localStorage.getItem('psm-lang');
        } catch {
            return null;
        }
    }

    function t(text) {
        if (lang !== 'ja' || !text) {
            return text;
        }

        const key = text.replace(/\s+/g, ' ').trim();

        if (Object.hasOwn(ja, key)) {
            return ja[key];
        }

        for (const [pattern, replace] of jaPatterns) {
            if (pattern.test(key)) {
                return key.replace(pattern, replace);
            }
        }

        return text;
    }

    function translateTextNode(node) {
        if (!originalText.has(node)) {
            originalText.set(node, node.textContent);
        }

        const source = originalText.get(node);
        const trimmed = source.trim();

        if (!trimmed) {
            return;
        }

        const translated = t(trimmed);
        const [lead] = source.match(/^\s*/);
        const [trail] = source.match(/\s*$/);
        node.textContent = translated === trimmed ? source : `${lead}${translated}${trail}`;
    }

    function translateAttributes(element) {
        if (!originalAttributes.has(element)) {
            originalAttributes.set(
                element,
                Object.fromEntries(attributes.filter((name) => element.hasAttribute(name)).map((name) => [name, element.getAttribute(name)])),
            );
        }

        Object.entries(originalAttributes.get(element)).forEach(([name, value]) => element.setAttribute(name, t(value)));
    }

    /** Dịch (hoặc trả về tiếng Việt) toàn bộ nội dung đang có trong trang. */
    function apply(scope = document.body) {
        const walker = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT, {
            acceptNode: (node) =>
                node.parentElement.closest('script, style, [data-no-i18n]') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT,
        });

        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            translateTextNode(node);
        }

        scope.querySelectorAll(attributes.map((name) => `[${name}]`).join(',')).forEach(translateAttributes);

        document.title = document.documentElement.dataset.titleVi
            ? t(document.documentElement.dataset.titleVi)
            : document.title;
    }

    /** Font tiếng Nhật chỉ tải khi cần (Google Fonts tự chia theo unicode-range). */
    function loadJapaneseFont() {
        if (document.querySelector('link[data-font-ja]')) {
            return;
        }

        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400..700&display=swap';
        link.dataset.fontJa = '';
        document.head.append(link);
    }

    function setLang(next, persist = true) {
        lang = next === 'ja' ? 'ja' : 'vi';
        document.documentElement.lang = lang;

        if (lang === 'ja') {
            loadJapaneseFont();
        }

        if (persist) {
            try {
                localStorage.setItem('psm-lang', lang);
            } catch {
                /* Không lưu được thì chỉ đổi trong phiên hiện tại. */
            }
        }

        apply();
        document.querySelectorAll('[data-lang-option]').forEach((button) => {
            button.setAttribute('aria-pressed', String(button.dataset.langOption === lang));
        });
        document.dispatchEvent(new CustomEvent('i18n:change', { detail: lang }));
    }

    /** Nút chuyển ngôn ngữ dạng hai lựa chọn: VI / 日本語. */
    function switchMarkup() {
        return `<div class="lang-switch" role="group" aria-label="Ngôn ngữ">
            <button type="button" data-lang-option="vi" lang="vi">VI</button>
            <button type="button" data-lang-option="ja" lang="ja">日本語</button>
        </div>`;
    }

    function bindSwitches() {
        document.querySelectorAll('[data-lang-option]').forEach((button) => {
            button.addEventListener('click', () => setLang(button.dataset.langOption));
        });
    }

    function init() {
        document.documentElement.dataset.titleVi ||= document.title;
        const params = new URLSearchParams(location.search);
        const requested = params.get('lang');
        setLang(requested || storedLang() || 'vi', Boolean(requested) && !params.has('embed'));
    }

    return {
        t,
        apply,
        init,
        setLang,
        switchMarkup,
        bindSwitches,
        get lang() {
            return lang;
        },
    };
})();

const t = I18N.t;
