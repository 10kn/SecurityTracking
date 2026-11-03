# Review trang quản trị tin bảo mật dưới góc độ quản lý dự án

- **Ngày kiểm thử:** 29/10/2026, múi giờ Việt Nam (GMT+7).
- **Môi trường:** local, bắt đầu từ `/admin/incidents`.
- **Màn hình được kiểm tra:** Tổng quan, danh sách và chi tiết sự kiện bảo mật, Findings; đối chiếu service liên quan qua thông tin hiển thị.
- **Mục tiêu:** người quản lý nhận được tin kịp thời, hiểu ảnh hưởng tới dự án và theo dõi được công việc tới khi khắc phục.
- **Trạng thái tài liệu:** kết quả review và đề xuất; chưa triển khai các thay đổi dưới đây.

## 1. Nhận định chung

Hệ thống đã có nền tảng hữu ích: gom các tin liên quan thành sự kiện, lọc theo công nghệ và team, theo dõi bản vá, hiển thị CISA KEV, cảnh báo nguồn dữ liệu quá hạn và lỗi gửi Google Chat.

Tuy nhiên, giao diện hiện chưa trả lời đủ nhanh các câu hỏi của người quản lý:

1. Tin nào cần xử lý ngay hôm nay, và vì sao?
2. Project, service và môi trường nào bị ảnh hưởng? Điều gì đã xác nhận, điều gì còn thiếu dữ liệu?
3. Team đã nhận được thông báo thành công chưa?
4. Ai chịu trách nhiệm, cần làm gì và trước khi nào?
5. Hãng đã có bản vá hay dự án đã triển khai bản vá?

Rủi ro chính là các dấu hiệu như “Đã đọc”, “Đã có bản vá” hoặc số “Team đã báo” có thể khiến người quản lý nghĩ công việc đã tiến xa hơn thực tế. Cần làm rõ ý nghĩa và liên kết giữa việc nhận tin, đánh giá ảnh hưởng và khắc phục.

## 2. Phạm vi và giới hạn bằng chứng

- Đã kiểm thử đăng nhập, tìm kiếm, bộ lọc, sắp xếp, phân trang, xem chi tiết, mở rồi hủy xác nhận gộp; kiểm tra điều kiện bật/tắt nút đánh giá và gửi cập nhật.
- Đã quan sát giao diện desktop 1440px và điện thoại 390px. Bản frontend ban đầu cũ hơn mã nguồn; đã chạy `npm run build`, tải lại trang và kiểm tra tiếp trên bản build mới.
- Các nhóm kiểm thử tự động liên quan chạy đạt **209 tests, 1.105 assertions**, dùng database `psm_testing` riêng và giả lập Google Chat. Chưa chạy toàn bộ suite.
- Không xác nhận lưu đánh giá, không thực hiện gộp/tách dữ liệu và không gửi thông báo thử tới Google Chat thật.
- Số lượng tin, lỗi gửi, trạng thái service và ngày hạn dưới đây là ảnh chụp hiện trạng dữ liệu local trong phiên kiểm thử; không phải bằng chứng về production.
- Chưa đối chiếu độc lập toàn bộ 102 sự kiện với nội dung nguồn và chưa đo độ trễ từ lúc hãng đăng tin tới lúc người dùng nhận thông báo.
- Các đề xuất về người phụ trách, hạn đánh giá lại và vòng đời xử lý mở rộng hành vi sản phẩm hiện tại. Đây là thay đổi nghiệp vụ cần chốt trước khi triển khai, không phải kết luận rằng mọi hành vi hiện tại đều là lỗi lập trình.

## 3. Danh sách ưu tiên

P1/P2 trong tài liệu là **ưu tiên cải thiện sản phẩm**, không phải mức nghiêm trọng của lỗ hổng bảo mật.

| Mã | Ưu tiên | Loại | Nội dung cần sửa |
| --- | --- | --- | --- |
| REV-01 | P1 | Vận hành và hiển thị | Thể hiện rõ thông báo đã gửi thành công, đang chờ hay gửi lỗi |
| REV-02 | P1 | Nghiệp vụ | Theo dõi đánh giá “Chưa rõ” tới khi có kết luận |
| REV-03 | P1 | Nghiệp vụ và hiển thị | Tách bản vá của hãng khỏi trạng thái khắc phục của dự án |
| REV-04 | P1 | Lỗi chức năng | Tìm được sự kiện theo CVE/GHSA và tin thành viên |
| REV-05 | P2 | Khả năng hiểu và kiểm chứng | Hiển thị tóm tắt và căn cứ phân loại tin |
| REV-06 | P2 | Diễn đạt trạng thái | Phân biệt thiếu thông tin, chờ bản vá, hết hỗ trợ và tin lịch sử |
| REV-07 | P2 | Ưu tiên thông tin | Đưa ảnh hưởng và hành động lên trước thao tác quản lý tin |
| REV-08 | P2 | Lỗi giao diện | Xóa từ khóa hiển thị khi bấm “Bỏ lọc” |
| REV-09 | P2 | Giao diện responsive | Đọc được thông tin quyết định trên điện thoại mà không cần cuộn ngang |

## 4. Chi tiết các nội dung cần sửa

### REV-01 — Làm rõ trạng thái chuyển phát thông báo

**Bằng chứng quan sát**

- Dashboard báo 20 tin tới space admin chưa gửi được, kèm cảnh báo rằng thông tin nguồn lỗi chưa tới admin.
- Chi tiết sự kiện #6 hiển thị thread của WEC ở trạng thái “Gửi lỗi”.
- Danh sách sự kiện hiển thị số “Team đã báo”, nhưng không hiển thị tình trạng gửi của thread hoặc lần cập nhật tương ứng.

**Nguyên nhân và lý do cần sửa**

Các màn hình đang trình bày các khía cạnh khác nhau của việc gửi tin: số team từng được báo, trạng thái mở thread và thông báo vận hành bị lỗi. Một số đếm không cho biết mọi thông báo liên quan đã tới nơi thành công. Vì vậy, không thể dùng riêng “Team đã báo = 1” để kết luận team đã nhận đủ thông tin hiện tại.

Nguyên nhân thực tế của các lần gửi lỗi chưa được xác minh. Chưa có cơ sở kết luận lỗi do webhook, queue worker, quyền truy cập hay phía Google Chat.

**Nội dung cần sửa**

- Hiển thị trạng thái chuyển phát theo team và thông báo: thành công, đang chờ, gửi lỗi; kèm thời điểm lần gửi thành công gần nhất và lỗi chưa khắc phục.
- Đưa lỗi gửi vào danh sách cần chú ý, không buộc người quản lý mở từng sự kiện mới thấy.
- Cung cấp hướng dẫn xử lý hoặc thao tác thử lại có kiểm soát; giữ cơ chế chống gửi trùng.
- Diễn đạt “gửi thành công tới Google Chat” đúng với bằng chứng có được; không suy thành người nhận đã đọc tin.

**Tiêu chí nghiệm thu**

- Thread mở lỗi và cập nhật gửi lỗi đều có dấu hiệu nhận biết ngay từ danh sách.
- Một team có lịch sử nhận tin thành công nhưng cập nhật mới bị lỗi phải thể hiện được cả hai trạng thái.
- Sau khi gửi lại thành công, trạng thái và thời gian cập nhật đúng, không tạo thông báo trùng ngoài ý muốn.

**Mã liên quan:** [IncidentController](../app/Http/Controllers/Admin/IncidentController.php), [IncidentOverview](../app/Services/IncidentOverview.php), [SendChatNotification](../app/Jobs/SendChatNotification.php).

### REV-02 — Không để đánh giá “Chưa rõ” bị bỏ quên

**Bằng chứng quan sát và đối chiếu mã**

Giao diện cho phép chọn “Chưa rõ” rồi xác nhận “Đã đọc”. Cơ chế đánh giá coi đây là một đánh giá hợp lệ khi phạm vi ảnh hưởng không đổi; lời nhắc đánh giá hằng ngày dừng lại. Kiểm thử hiện có cũng xác nhận hành vi này.

**Nguyên nhân và lý do cần sửa**

“Đã đọc” đang đồng thời mang ý nghĩa xác nhận đã đánh giá và điều khiển việc dừng nhắc. Nhưng “chưa rõ ảnh hưởng” vẫn là một công việc chưa hoàn tất. Người quản lý cần biết ai đang xác minh và lúc nào phải có kết luận, kể cả khi không có tin mới từ hãng.

Đây là khoảng trống nghiệp vụ so với nhu cầu theo dõi của người quản lý; hành vi dừng nhắc hiện tại được triển khai có chủ ý. Dừng nhắc đánh giá không đồng nghĩa xóa sự kiện khỏi mọi bản tổng hợp.

**Nội dung cần sửa**

- Tách xác nhận đã đọc khỏi trạng thái hoàn thành đánh giá.
- Khi chọn “Chưa rõ”, cho ghi nhận điều còn thiếu, người phụ trách và hạn đánh giá lại.
- Có nhóm “Chờ xác minh” và cơ chế nhắc khi đến hạn; tránh lặp lại nguyên văn cảnh báo mỗi ngày nếu chưa tới hạn.
- Hiển thị rõ tác dụng của nút trước khi người dùng xác nhận.

**Tiêu chí nghiệm thu**

- Đã đọc nhưng chưa rõ ảnh hưởng vẫn xuất hiện trong công việc cần theo dõi.
- Đến hạn đánh giá lại thì có nhắc việc, ngay cả khi nội dung tin và phạm vi ảnh hưởng không đổi.
- Thay đổi bằng chứng hoặc phạm vi ảnh hưởng vẫn kích hoạt đánh giá lại theo cơ chế hiện có.

**Mã liên quan:** [IncidentTeam](../app/Models/IncidentTeam.php), [IncidentOverview](../app/Services/IncidentOverview.php), [SendDailyDigest](../app/Actions/SendDailyDigest.php), [IncidentAssessmentTest](../tests/Feature/Actions/IncidentAssessmentTest.php).

### REV-03 — Tách “hãng có bản vá” và “dự án đã khắc phục”

**Bằng chứng quan sát**

- Sự kiện #6 hiển thị “Đã có bản vá”, trong đó PHP 8.5 được chỉ tới 8.5.12.
- Findings vẫn có cảnh báo CMS / WECSY thiếu bản vá PHP 8.5.12 và chưa đọc.
- Cột “Cần xử lý” của sự kiện #6 chỉ thể hiện các gợi ý gộp; không diễn đạt công việc nâng cấp service còn mở.

**Nguyên nhân và lý do cần sửa**

Trạng thái bản vá của sự kiện được tính từ thông tin bản vá đã xác nhận của hãng. Trong khi đó, tiêu chí “Cần admin xử lý” tập trung vào gợi ý gộp và nhu cầu đánh giá của team. Hai yếu tố này không đại diện cho việc service đã triển khai bản vá.

Vì vậy, nhãn màu tích cực “Đã có bản vá” có thể tạo cảm giác vấn đề đã được giải quyết. Hệ thống vẫn giữ finding mở, nhưng người quản lý phải tự nối thông tin giữa hai màn hình.

**Nội dung cần sửa**

- Đổi nhãn thành “Hãng đã phát hành bản vá”, hoặc cách diễn đạt tương đương.
- Hiển thị riêng phiên bản đang ghi nhận của service, phiên bản cần nâng, thời điểm dữ liệu được cập nhật và trạng thái finding liên quan.
- Có liên kết trực tiếp từ sự kiện tới các finding/service cần khắc phục.
- Tách hàng đợi đánh giá/gộp tin khỏi hàng đợi khắc phục rủi ro. Một sự kiện hết chờ đánh giá vẫn có thể còn công việc khắc phục.
- Chỉ hiển thị đã khắc phục khi có bằng chứng inventory/đánh giá tương ứng; không lấy việc đọc tin làm bằng chứng đã vá.

**Tiêu chí nghiệm thu**

- Trên cùng màn hình có thể nhận ra “hãng có bản vá, service chưa nâng cấp”.
- Service còn finding mở không được trình bày như đã xử lý xong.
- Khi dữ liệu phiên bản cũ hoặc chưa xác minh, giao diện nêu rõ giới hạn đó.

**Mã liên quan:** [IncidentController](../app/Http/Controllers/Admin/IncidentController.php), [IncidentOverview](../app/Services/IncidentOverview.php), [FixStatus](../resources/js/components/admin/incidents/fix-status.tsx), [Findings](../resources/js/pages/findings/index.tsx).

### REV-04 — Sửa tìm kiếm theo CVE/GHSA

**Cách tái hiện**

1. Mở sự kiện #6 và thấy `CVE-2026-109618` trong chi tiết.
2. Trở về danh sách sự kiện.
3. Nhập `CVE-2026-109618` vào ô “Tìm sự kiện”.
4. Kết quả quan sát: 0 sự kiện.

**Nguyên nhân và lý do cần sửa**

Truy vấn danh sách hiện chỉ tìm trên `incidents.title`. Mã CVE nằm trong dữ liệu của tin thành viên/bằng chứng KEV sẽ không được tìm thấy nếu tiêu đề sự kiện không chứa mã đó. Tương tự, tiêu đề một tin thuộc sự kiện có thể khác tiêu đề được lưu của sự kiện.

Người quản lý thường nhận một mã CVE từ khách hàng, đối tác hoặc đội kỹ thuật để tra cứu. Không tìm thấy mã đã có trong hệ thống dễ bị hiểu nhầm là hệ thống chưa biết hoặc dự án không bị ảnh hưởng.

**Nội dung cần sửa**

- Tìm trên tiêu đề sự kiện, tiêu đề các tin thành viên và mã CVE/GHSA liên quan.
- Không phân biệt hoa/thường đối với mã định danh; xử lý khoảng trắng đầu/cuối.
- Giữ đúng bộ lọc, phân trang và tránh lặp sự kiện khi nhiều tin cùng khớp.

**Tiêu chí nghiệm thu**

- Mã trên trả về sự kiện #6 trên bộ dữ liệu đã kiểm thử.
- Tìm CVE/GHSA chỉ có trong tin thành viên vẫn tìm được sự kiện chứa tin đó.
- Từ khóa không khớp cho trạng thái rỗng rõ ràng; bộ lọc team/công nghệ vẫn được áp dụng.

**Mã liên quan:** phương thức `index()` trong [IncidentController](../app/Http/Controllers/Admin/IncidentController.php).

### REV-05 — Hiển thị tóm tắt và căn cứ phân loại

**Bằng chứng quan sát**

- Nhiều tin có mức “Chưa rõ”.
- Sự kiện #7 “PHP 8.6.0 RC4 available for testing” mang mức Critical, nhưng trang không có đoạn giải thích ngắn giúp người quản lý hiểu mối liên hệ với lỗi bảo mật.
- Các tiêu đề chính vẫn là tiếng Anh trong giao diện tiếng Việt.
- Dữ liệu xử lý tin có `summary`, `localized_summary`, `severity_basis` và thông tin classifier, nhưng trang chi tiết chưa trình bày đầy đủ bối cảnh này.

**Nguyên nhân và lý do cần sửa**

Giao diện chủ yếu trình bày kết quả cuối: tiêu đề, mức, nhánh và bản vá. Thiếu phần giải thích nên người đọc khó phân biệt mức do hãng công bố, CVSS, KEV hay AI suy ra từ nội dung.

Không nên kết luận tin RC bị phân loại sai chỉ từ tiêu đề: tin phát hành RC có thể nói về việc sửa lỗ hổng Critical. Cũng không nên tự nâng mức “Chưa rõ” khi nguồn chưa có bằng chứng. Vấn đề được xác nhận ở đây là thiếu thông tin để người dùng hiểu và kiểm chứng, chưa phải kết luận về độ chính xác toàn bộ classifier.

**Nội dung cần sửa**

- Thêm tóm tắt tiếng Việt: vấn đề là gì, ảnh hưởng được xác nhận tới đâu và cần làm gì tiếp.
- Hiển thị căn cứ mức độ và liên kết nguồn; phân biệt nội dung trích từ hãng với tóm tắt do AI tạo.
- Nêu rõ khi AI chưa chạy, chạy lỗi hoặc nguồn chưa cung cấp đủ dữ liệu.
- Với “Chưa rõ”, chỉ ra thiếu mức nghiêm trọng hay thiếu phạm vi ảnh hưởng; đây là hai câu hỏi khác nhau.
- Không hiển thị điểm tin cậy dạng số nếu hệ thống chưa có cách đo và hiệu chuẩn điểm đó.

**Tiêu chí nghiệm thu**

- Người quản lý hiểu được lý do một tin được xếp Critical mà không buộc phải mở bài gốc.
- Có thể kiểm chứng căn cứ qua nguồn; thông tin không có bằng chứng được ghi là chưa xác định.
- Kiểm thử các tình huống: hãng không nêu mức, AI lỗi, tin không phải bảo mật, tin RC chứa bản sửa lỗi và tin được KEV bổ sung bằng chứng.

**Mã liên quan:** [IncidentController](../app/Http/Controllers/Admin/IncidentController.php), [trang chi tiết sự kiện](../resources/js/pages/incidents/show.tsx), [SyncNoticeFeed](../app/Actions/SyncNoticeFeed.php), [ClaudeNoticeClassifier](../app/Services/Notices/ClaudeNoticeClassifier.php).

### REV-06 — Sửa nhãn bản vá và cách trình bày tin lịch sử

**Bằng chứng quan sát**

- Khi lọc PHP, tin phát hành từ năm 2019 vẫn hiển thị “Chưa có bản vá — 8.5 chờ”.
- Các tin Node.js cũ hiển thị “16 chờ”, trong khi Findings có cảnh báo Node.js 16 hết hỗ trợ.
- Tin không có thông tin bản vá để hiển thị cũng có thể nhận nhãn “Chưa có bản vá”.

**Nguyên nhân và lý do cần sửa**

Hệ thống có chủ ý thận trọng: nhánh không được hãng loại trừ vẫn có thể ở phạm vi chưa xác định. Các runtime hiện tại chưa có bản vá khớp được đưa vào danh sách chờ. Đồng thời, phần tóm tắt dùng cùng nhãn “Chưa có bản vá” khi danh sách bản vá trống hoặc chưa có bản vá xác nhận.

“Chưa xác nhận được bản vá phù hợp” không đủ để kết luận “hãng chưa phát hành bản vá”. Gắn nhánh hiện tại vào một tin lịch sử mà thiếu giải thích cũng khiến người đọc tưởng tin đó đã xác nhận ảnh hưởng tới nhánh hiện tại. Với nhánh hết hỗ trợ, lời hướng dẫn chỉ “chờ” dễ dẫn tới trì hoãn việc lập kế hoạch nâng cấp.

**Nội dung cần sửa**

- Tách nhãn: “Chưa xác định ảnh hưởng”, “Chưa có thông tin bản vá xác nhận”, “Hãng dự kiến phát hành”, “Hãng đã có bản vá” và “Nhánh hết hỗ trợ”.
- Đánh dấu tin lịch sử và cung cấp bộ lọc thời gian; ưu tiên tin mới hoặc có thay đổi cần hành động.
- Với nhánh hết hỗ trợ, hướng tới xác minh ảnh hưởng và kế hoạch chuyển sang nhánh được hỗ trợ.
- Giữ nguyên nguyên tắc thận trọng: không tự coi nhánh là an toàn chỉ vì hãng không nhắc tới.

**Tiêu chí nghiệm thu**

- Tin lịch sử không được diễn đạt như đã xác nhận ảnh hưởng tới runtime hiện tại khi chưa có bằng chứng.
- Thiếu dữ liệu không bị trình bày như kết luận chắc chắn rằng chưa có bản vá.
- Nhánh hết hỗ trợ có thông điệp hành động phù hợp, không chỉ hiện “chờ”.

**Mã liên quan:** `scopeFor()` trong [Incident](../app/Models/Incident.php), [IncidentTeamExposure](../app/Services/IncidentTeamExposure.php), [IncidentOverview](../app/Services/IncidentOverview.php), [IncidentController](../app/Http/Controllers/Admin/IncidentController.php).

### REV-07 — Ưu tiên ảnh hưởng và hành động trước quản lý tin

**Bằng chứng quan sát**

- Trang chi tiết sự kiện #6 đưa bảng 5 gợi ý gộp lên trước phần team và đánh giá ảnh hưởng.
- Có các gợi ý cùng hướng chuyển nhưng khác lý do, làm tăng số dòng cần đọc.
- Chi tiết có KEV và ngày hạn, nhưng danh sách sự kiện chưa làm nổi bật các tín hiệu này.
- Danh sách mặc định sắp theo thời điểm đăng đầu tiên, chưa cung cấp cách xếp theo công việc quan trọng nhất đối với dự án.

**Nguyên nhân và lý do cần sửa**

Bố cục hiện dành nhiều diện tích cho quản trị dữ liệu tin. Người quản lý phải đọc qua thao tác gộp/tách trước khi biết team/service nào cần hành động. Số gợi ý gộp cũng đang cùng nằm dưới tên “Cần xử lý” với nhu cầu đánh giá ảnh hưởng, dù hai việc có ý nghĩa khác nhau.

Mức nghiêm trọng của lỗ hổng không tự quyết định mức ưu tiên của dự án. Cần kết hợp bằng chứng khai thác, phạm vi áp dụng, hạn xử lý và tình trạng khắc phục.

**Nội dung cần sửa**

- Đầu trang hiển thị tóm tắt hành động, project/service liên quan, mức xác nhận ảnh hưởng, hạn xử lý và người phụ trách nếu có.
- Chuyển gợi ý gộp/tách xuống sau hoặc vào khu vực thu gọn; có thể gom các bằng chứng cho cùng một hướng chuyển vào một gợi ý.
- Bổ sung chế độ xem theo ưu tiên hành động, với lý do xếp hạng dễ hiểu.
- Làm nổi bật KEV, quá hạn, lỗi chuyển phát và cập nhật quan trọng. Giữ riêng ngày đăng đầu tiên và thời điểm thay đổi cần chú ý, không coi mọi chỉnh sửa nội dung là cảnh báo mới.

**Tiêu chí nghiệm thu**

- Phần đầu trang trả lời được: dự án nào, ảnh hưởng gì, việc tiếp theo và hạn xử lý.
- Người dùng phân biệt được “cần đánh giá/khắc phục” và “cần gộp tin”.
- Cùng một mức Critical nhưng khác ảnh hưởng hoặc hạn xử lý có thể có ưu tiên khác nhau, kèm lý do.

**Mã liên quan:** [danh sách sự kiện](../resources/js/pages/incidents/index.tsx), [chi tiết sự kiện](../resources/js/pages/incidents/show.tsx), [IncidentOverview](../app/Services/IncidentOverview.php).

### REV-08 — Đồng bộ ô tìm kiếm khi “Bỏ lọc”

**Cách tái hiện**

1. Nhập `CVE-2026-109618` và chờ danh sách trả về 0 kết quả.
2. Bấm “Bỏ lọc”.
3. Danh sách trở lại 102 sự kiện, nhưng ô tìm kiếm vẫn hiển thị mã CVE vừa nhập.

**Nguyên nhân và lý do cần sửa**

`TableSearch` khởi tạo state nội bộ bằng `useState(value)` nhưng không đồng bộ khi prop `value` đổi. Truy vấn dùng `preserveState: true`, nên thao tác bỏ lọc cập nhật kết quả và URL nhưng giữ lại giá trị input cũ.

Người dùng thấy từ khóa còn nguyên sẽ nghĩ danh sách vẫn được lọc. Khi đó, các kết quả không liên quan làm giảm độ tin cậy của chức năng tìm kiếm.

**Nội dung cần sửa**

- Đồng bộ giá trị input khi trạng thái tìm kiếm từ bên ngoài thay đổi.
- Xử lý callback debounce đang chờ để từ khóa cũ không được gửi lại sau khi bỏ lọc.
- Rà các màn hình cùng sử dụng `TableSearch`, vì đây là component dùng chung.

**Tiêu chí nghiệm thu**

- Bỏ lọc làm input, URL, bộ lọc và danh sách nhất quán.
- Thử cả trường hợp đã chờ tìm kiếm hoàn tất và bấm bỏ lọc ngay khi debounce còn chờ.
- Gõ tìm kiếm liên tục vẫn hoạt động đúng, không phát sinh vòng lặp request.

**Mã liên quan:** [TableSearch](../resources/js/components/admin/data-table.tsx), [useTableQuery](../resources/js/hooks/use-table-query.ts).

### REV-09 — Bố cục đọc nhanh trên điện thoại

**Bằng chứng quan sát**

Ở độ rộng 390px, người dùng chỉ thấy các cột đầu của bảng; phải cuộn ngang mới tới bản vá và việc cần xử lý. Ở desktop 1440px, các cột hiển thị đầy đủ hơn, nhưng tiêu đề dài vẫn tạo dòng cao và giảm khả năng quét nhanh nhiều tin.

**Nguyên nhân và lý do cần sửa**

Bảng danh sách có độ rộng tối thiểu 1000px. Cuộn ngang giữ được dữ liệu nhưng tách thông tin quyết định khỏi tiêu đề tin. Đây là trở ngại khi người quản lý mở trang từ thông báo trên điện thoại để quyết định có cần gọi đội kỹ thuật ngay hay không.

**Nội dung cần sửa**

- Dùng bố cục thẻ hoặc hàng rút gọn cho màn hình nhỏ.
- Trong vùng nhìn đầu tiên của mỗi tin, hiển thị mức ưu tiên, ảnh hưởng tới dự án, hành động, hạn và trạng thái chuyển phát quan trọng.
- Thu gọn metadata ít quan trọng; vẫn cho mở nội dung đầy đủ và nguồn gốc.
- Kiểm tra tiêu đề dài, nhiều phiên bản vá và nhiều team; không chỉ kiểm tra dữ liệu ngắn.

**Tiêu chí nghiệm thu**

- Ở 390px, không cần cuộn ngang để biết tin liên quan tới mình và việc cần làm tiếp theo.
- Thao tác lọc, mở chi tiết và đọc trạng thái vẫn thuận tiện.
- Desktop giữ được khả năng so sánh nhiều sự kiện trên cùng danh sách.

**Mã liên quan:** [danh sách sự kiện](../resources/js/pages/incidents/index.tsx), [DataTable](../resources/js/components/admin/data-table.tsx).

## 5. Nguyên tắc phân loại đề xuất

Không nên dồn mọi khái niệm vào một nhãn “mức độ” hoặc “cần xử lý”. Nên thể hiện các chiều độc lập:

| Chiều thông tin | Câu hỏi cần trả lời | Ví dụ |
| --- | --- | --- |
| Loại tin | Đây là thông báo gì? | Báo trước, công bố lỗ hổng, phát hành bản vá, cập nhật, không phải tin bảo mật |
| Mức nghiêm trọng | Lỗ hổng nguy hiểm tới đâu theo bằng chứng? | Critical / High / Medium / Low / Chưa rõ; kèm căn cứ |
| Phạm vi ảnh hưởng | Tin áp dụng thế nào với dự án này? | Đã xác nhận ảnh hưởng / Không ảnh hưởng / Chưa xác định |
| Bằng chứng khai thác | Có thông tin khai thác trong thực tế không? | KEV hoặc bằng chứng từ hãng; không suy ra chỉ từ nhãn Critical |
| Ưu tiên công việc | Việc gì cần làm trước? | Khẩn cấp / Cần xử lý / Theo dõi; kèm lý do |
| Tiến độ | Công việc đang tới đâu? | Chờ đánh giá / Chờ xác minh / Chờ hãng / Đang khắc phục / Đã xác minh khắc phục |
| Chuyển phát | Thông tin đã tới kênh thông báo chưa? | Chưa gửi / Đang chờ / Gửi thành công / Gửi lỗi |

Các quy tắc cần giữ:

- “Chưa rõ” không có nghĩa là mức thấp hay an toàn.
- KEV không chứng minh hệ thống của dự án đã bị tấn công.
- Không có trong KEV không chứng minh lỗ hổng chưa bị khai thác.
- Có bản vá không chứng minh service đã triển khai bản vá.
- Google Chat chấp nhận thông báo không chứng minh con người đã đọc.
- Đã đọc không chứng minh đã xác minh ảnh hưởng hoặc đã khắc phục.

## 6. Thứ tự thực hiện và kiểm chứng

1. **Sửa lỗi tra cứu và trạng thái input:** REV-04, REV-08. Phạm vi rõ, có thể kiểm chứng bằng các bước tái hiện trực tiếp.
2. **Làm rõ khả năng nhận thông báo:** REV-01; xác minh nguyên nhân các lần gửi lỗi trên môi trường vận hành dự kiến.
3. **Chốt nghiệp vụ theo dõi tới khi xử lý xong:** REV-02, REV-03. Quyết định ý nghĩa của “Đã đọc”, hạn đánh giá lại và liên kết với finding trước khi thay đổi cơ chế nhắc.
4. **Cải thiện chất lượng diễn giải và ưu tiên:** REV-05, REV-06, REV-07.
5. **Kiểm thử lại bố cục điện thoại:** REV-09, dựa trên thứ tự thông tin vừa chốt.

Để đánh giá mục tiêu “kịp thời”, cần thử một luồng có kiểm soát trên kênh test được cho phép và ghi các mốc: hãng công bố → hệ thống thu thập → phân loại → tạo thông báo → Google Chat chấp nhận. Tần suất cấu hình scheduler và test giả lập không đủ chứng minh độ trễ thực tế.

Để đánh giá chất lượng phân loại, cần chọn mẫu tin có nội dung nguồn đối chiếu, bao gồm tin không phải bảo mật, tin lịch sử, tin không nêu mức, tin RC có bản sửa lỗi và tin có bản vá. Ghi riêng trường hợp bỏ sót, cảnh báo thừa và thiếu dữ liệu; không dùng số test đã đạt làm tỷ lệ chính xác của classifier trên tin thật.

Sau khi sửa, chạy kiểm thử hẹp tương ứng từng mục, kiểm thử trình duyệt cho các hành vi input/responsive, rồi chạy toàn bộ suite bằng `php artisan test --compact` trước khi phát hành.

## 7. Kết quả kiểm thử tự động trong phiên review

| Nhóm | Kết quả |
| --- | --- |
| IncidentController, OverviewController, NoticeSeverity, NewsFeedParser, ClaudeNoticeClassifier, SendPreNoticeAlerts, CheckSourceHeartbeats | 148 tests đạt, 920 assertions |
| IncidentAssessment, SendDailyDigest | 61 tests đạt, 185 assertions |
| **Tổng** | **209 tests đạt, 1.105 assertions** |

Kết quả trên xác nhận các hợp đồng đã được test trong phạm vi đó. Chúng không chứng nhận đầy đủ trải nghiệm người quản lý, khả năng chuyển phát tới Google Chat thật hay độ chính xác của toàn bộ tin đang lưu. Những lỗi giao diện và khoảng trống nghiệp vụ nêu trong review vẫn cần xử lý dù các test hiện có đạt.
