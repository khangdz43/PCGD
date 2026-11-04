# Hướng dẫn cập nhật SRS theo website hiện tại — PCGD-XMC

## Mục đích

Tài liệu này là brief để Gemini rà soát và chỉnh sửa `docs/SRS.docx` sao cho **toàn bộ SRS, đặc biệt tất cả use case và dữ liệu đầu vào, phản ánh đúng website PCGD-XMC hiện tại**. Hãy đính kèm cả file SRS và tài liệu này khi yêu cầu Gemini làm việc.

## Chỉ dẫn cho Gemini

1. Đọc SRS hiện có và làm theo phần **“Các nội dung cần sửa trong Word”** của tài liệu này. Không tự suy diễn các chức năng ngoài những ghi chú đã nêu.
2. Phạm vi là **toàn bộ 18 use case UC-001–UC-018**, các bảng dữ liệu đầu vào, tác nhân, điều kiện trước/sau, luồng chính, luồng thay thế, quyền truy cập và các mô tả tổng quan có liên quan. Không chỉ giới hạn UC-008–UC-011.
3. Rà soát từng use case, kể cả đăng nhập. Sửa các phần đang mô tả sai theo hướng dẫn cụ thể dưới đây.
4. Giữ nguyên ngôn ngữ, cấu trúc, mã use case, thứ tự và phong cách trình bày hiện có. Cập nhật danh sách use case, sơ đồ, số thứ tự bảng, danh mục bảng, mục lục và tham chiếu chéo nếu việc sửa đổi làm chúng thay đổi. Không xóa use case chỉ vì cần thêm thông tin.
5. Trong bảng đầu vào, dùng tên nghiệp vụ tiếng Việt dễ hiểu; có thể ghi thêm tên trường nội bộ. Phân biệt rõ dữ liệu người dùng nhập, dữ liệu chọn từ danh mục, dữ liệu do hệ thống sinh và thông tin chỉ có trong import.
6. Ghi chính xác trường bắt buộc/tùy chọn, kiểu/định dạng, miền giá trị, quy tắc liên kết và lỗi theo hướng dẫn trong tài liệu này. Không tự thêm trường, quy tắc, phân quyền hay quy trình chưa được triển khai.
7. Nếu quy tắc nghiệp vụ trong SRS cũ khác với hành vi đang chạy, sửa phần mô tả cho khớp hiện trạng. Nếu có vấn đề cần chủ nghiệp vụ quyết định, ghi thành mục “Cần xác nhận nghiệp vụ”; không tự chọn một quy tắc mới.
8. Chỉ chỉnh sửa nội dung tài liệu SRS, không biến đề xuất thành chức năng đã triển khai. Kết quả cần trả lại là file `SRS.docx` đã chỉnh sửa và danh sách ngắn các điểm đã sửa/cần xác nhận.

### Danh sách use case bắt buộc rà soát

- UC-001: Đăng nhập hệ thống
- UC-002: Thay đổi mật khẩu
- UC-003: Đặt lại / Thiết lập mật khẩu
- UC-004: Cập nhật thông tin cá nhân
- UC-005: Quản lý Nhóm người dùng (Role)
- UC-006: Quản trị Tài khoản & Phân quyền
- UC-007: Tìm kiếm & Khóa tài khoản
- UC-008: Quản lý danh mục Thôn/Xóm
- UC-009: Quản lý danh mục Trường học
- UC-010: Quản lý Phiếu điều tra Hộ gia đình
- UC-011: Import Phiếu điều tra từ Excel
- UC-012: Export Excel PCGD
- UC-013: Thực hiện Nghiệp vụ Gộp Thôn/Xóm
- UC-014: Thực hiện Nghiệp vụ Gộp Trường học
- UC-015: Tra cứu Lịch sử Gộp Thôn / Trường
- UC-016: Xem Tiện ích Dữ liệu rác
- UC-017: Khôi phục dữ liệu rác
- UC-018: Xóa vĩnh viễn dữ liệu rác

Với từng use case, kiểm tra tối thiểu: tác nhân thật sự được phép dùng; quyền và phạm vi địa bàn; tiền/hậu điều kiện; luồng giao diện thực tế; trường nhập và validation; trạng thái/kết quả; lỗi và luồng thay thế; thao tác xóa mềm/vĩnh viễn nếu có. Những nội dung SRS không có bằng chứng hiện hành thì không tự khẳng định; ghi rõ cần xác nhận hoặc không thuộc chức năng đang chạy.

### Lưu ý chính xác về CAPTCHA ở UC-001

- Trang đăng nhập hiện hiển thị CAPTCHA gồm 5 ký tự, có nút đổi mã; người dùng phải nhập tên đăng nhập, mật khẩu và mã xác nhận. So sánh mã không phân biệt chữ hoa/thường; thiếu trường hoặc sai CAPTCHA sẽ hiện lỗi trên giao diện và mã sai được làm mới.
- **Giới hạn quan trọng:** CAPTCHA hiện chỉ được kiểm tra trên giao diện; máy chủ chưa xác thực mã này khi đăng nhập. Máy chủ vẫn kiểm tra tên đăng nhập, mật khẩu, trạng thái tài khoản và giới hạn số lần thử.
- Do đó hãy mô tả CAPTCHA như bước xác nhận đang có trên giao diện, nhưng **không** ghi rằng hệ thống xác thực CAPTCHA khi xử lý đăng nhập hoặc CAPTCHA hiện là biện pháp chống bot phía máy chủ. Đưa việc xác minh CAPTCHA khi đăng nhập vào “Cần xác nhận nghiệp vụ / hạn chế hiện tại” nếu SRS cần nêu bảo mật.

## Các nội dung cần sửa trong Word

Các ghi chú dưới đây chỉ rõ SRS đang mô tả gì chưa đúng và cần sửa thành nội dung nào. Chỉ mô tả là chức năng hiện có khi người dùng thực sự thao tác được trên website; chức năng đang bị khóa hoặc chưa có giao diện thao tác cần ghi rõ là chưa khả dụng.

### UC-001 — Đăng nhập

- **SRS hiện ghi:** chỉ nhập tên đăng nhập và mật khẩu; tác nhân liệt kê Trung ương/Tỉnh/Xã; đăng nhập sai quá 5 lần thì khóa tài khoản 15 phút.
- **Website hiện tại:** form còn yêu cầu mã xác nhận CAPTCHA 5 ký tự, có nút tạo mã mới; không phân biệt chữ hoa/thường. CAPTCHA chỉ được kiểm tra trên giao diện, không gửi đáp án để máy chủ xác thực. Có thêm tài khoản phạm vi Thôn/Xóm. Đăng nhập sai bị giới hạn thử lại theo tên đăng nhập và địa chỉ truy cập, khoảng 60 giây; đây không phải khóa tài khoản 15 phút.
- **Gemini sửa:** bổ sung CAPTCHA trong luồng giao diện và trường đầu vào; ghi rõ đây chưa phải xác minh CAPTCHA phía máy chủ. Cập nhật tác nhân và mô tả giới hạn đăng nhập theo thực tế, không gọi là khóa tài khoản 15 phút.

### UC-002 — Thay đổi mật khẩu

- **SRS hiện ghi:** mật khẩu mới tối thiểu 8 ký tự; tên trường đầu vào là `old_password`, `new_password`, `confirm_password`; chưa nói mật khẩu mới phải khác mật khẩu cũ.
- **Website hiện tại:** yêu cầu mật khẩu hiện tại; mật khẩu mới ít nhất **12 ký tự**, phải xác nhận khớp và phải khác mật khẩu hiện tại. Tên trường gửi là `current_password`, `password`, `password_confirmation`.
- **Gemini sửa:** thay mức tối thiểu 8 thành 12, bổ sung điều kiện mật khẩu mới khác mật khẩu hiện tại, cập nhật tên trường/nhãn tương ứng.

### UC-003 — Đặt lại / Thiết lập mật khẩu

- **SRS hiện ghi:** Admin sinh mật khẩu ngẫu nhiên, mã hóa và hiển thị mật khẩu mới.
- **Website hiện tại:** thao tác đặt lại mật khẩu tài khoản về một mật khẩu tạm thời dùng chung đã cấu hình; kết quả hiển thị mật khẩu tạm cho người có quyền đặt lại. Không có bước sinh mật khẩu ngẫu nhiên trong thao tác này.
- **Gemini sửa:** bỏ mô tả “sinh mật khẩu ngẫu nhiên”; ghi đúng là đặt lại về mật khẩu tạm theo cấu hình và hiển thị/trả kết quả cho Admin được phép. Không ghi giá trị mật khẩu tạm cụ thể vào SRS.
- **Lưu ý nghiệp vụ/bảo mật:** xác nhận có yêu cầu bắt buộc đổi mật khẩu sau reset hay không; không mô tả là đã có nếu chưa được xác minh.

### UC-004 — Cập nhật thông tin cá nhân

- **SRS hiện ghi:** người dùng sửa họ tên và số điện thoại; hệ thống kiểm tra định dạng số điện thoại.
- **Website hiện tại:** trang tài khoản cho xem thông tin và địa bàn/quyền; chưa có chức năng cập nhật tên hoặc số điện thoại. Tài khoản có chức năng đổi mật khẩu riêng.
- **Gemini sửa:** không mô tả việc sửa tên/SĐT và kiểm tra định dạng như chức năng đang chạy. Ghi đúng trang hồ sơ hiện cung cấp thông tin xem được và tách UC-002 đổi mật khẩu. Nếu chủ dự án xác nhận cần sửa thông tin cá nhân thì ghi là chức năng cần bổ sung, không phải hiện trạng.

### UC-005 — Quản lý Nhóm người dùng

- **SRS hiện ghi:** Admin Trung ương/Tỉnh/Xã tạo, sửa, xóa nhóm và gán permissions; mô tả tên nhóm không trùng nhưng không nêu phạm vi sở hữu, quyền có thể gán hay điều kiện xóa.
- **Website hiện tại:** nhóm có cấp phạm vi `global/province/commune`; người quản lý chỉ thao tác nhóm trong phạm vi được giao/tạo, và chỉ gán quyền phù hợp cấp cùng quyền mà tài khoản quản lý được phép phân giao. Tên nhóm duy nhất trong phạm vi người tạo (không phải duy nhất toàn hệ thống). Không xóa được nhóm hệ thống hoặc nhóm còn tài khoản đang hoạt động; nhóm cùng cấp không được sao chép toàn bộ quyền của người tạo.
- **Gemini sửa:** ghi rõ giới hạn cấp/phạm vi và quyền được phân giao; sửa quy tắc duy nhất thành theo chủ sở hữu; nêu điều kiện chặn xóa. Không mô tả một danh mục role toàn cục mà mọi Admin đều dùng chung.

### UC-006 — Quản trị Tài khoản & Phân quyền địa bàn

- **SRS hiện ghi:** địa bàn có thể là Tỉnh/Xã/Thôn/Trường; người quản trị nhập mật khẩu; bảng dữ liệu chỉ nêu username/password/full_name/role_id/ma_don_vi.
- **Website hiện tại:** tài khoản gán cấp `global/province/commune`; **không có phạm vi tài khoản theo trường hoặc cấp thôn**. Tài khoản xã có thể được giới hạn vào nhiều thôn qua `user_villages`; không gán thôn nghĩa là toàn xã. Cấp tạo/gán tài khoản bị giới hạn theo phân cấp trực tiếp và quyền được cấp; role phải khớp cấp. Biểu mẫu có tên, username, phone, mật khẩu (tối thiểu 12 ký tự khi tạo), role, scope, địa bàn và trạng thái. Ràng buộc một Admin chính đang hoạt động mỗi tỉnh/xã chỉ áp dụng cho role quản trị chính tương ứng, không phải mọi tài khoản trong địa bàn.
- **Gemini sửa:** bỏ Trường khỏi danh sách phạm vi tài khoản; thay `ma_don_vi` bằng các trường cấp/phạm vi và mã tỉnh/xã/danh sách mã thôn phù hợp. Cập nhật độ dài và quy tắc mật khẩu. Mô tả đúng việc chỉ một tài khoản quản trị chính đang hoạt động ở mỗi tỉnh/xã; không khái quát thành chỉ một tài khoản bất kỳ.

### UC-007 — Tìm kiếm & Khóa tài khoản

- **SRS hiện ghi:** tìm theo tên đăng nhập/họ tên; khóa đặt `is_active=false` và ngắt phiên ngay lập tức.
- **Website hiện tại:** tìm theo tên đăng nhập/họ tên, có thêm lọc vai trò và địa bàn; hành động là bật/tắt quyền đăng nhập, không phải khóa có thời hạn. Không cho người quản trị tự thu hồi tài khoản đang đăng nhập. Phiên đăng nhập bị chấm dứt khi tài khoản đã bị vô hiệu hóa gửi yêu cầu tiếp theo; không bị ngắt ngay tại thời điểm đổi trạng thái.
- **Gemini sửa:** có thể giữ tìm tên/username nhưng bổ sung bộ lọc nếu bảng đầu vào mô tả thông tin tìm kiếm; gọi là thu hồi/kích hoạt quyền đăng nhập; bỏ khẳng định ngắt phiên lập tức. Nêu phạm vi người quản lý chỉ thay đổi tài khoản được giao.

### UC-008 — Danh mục Thôn/Xóm

- **SRS hiện ghi:** người dùng nhập mã thôn, tên thôn và ghi chú; mã thôn duy nhất trong xã.
- **Website hiện tại:** chỉ nhập tên thôn và chọn xã/tỉnh; mã thôn được hệ thống sinh; không có trường ghi chú. Tên phải duy nhất trong một xã. Không xóa được thôn đang có phiếu hộ.
- **Gemini sửa:** áp dụng đầy đủ quy tắc UC-008 trong phần “Quy tắc nghiệp vụ cần thể hiện” bên dưới; bỏ mã nhập tay và ghi chú.

### UC-009 — Danh mục Trường học

- **SRS hiện ghi:** người dùng nhập mã trường, địa chỉ; cấp học bắt buộc với `MAM_NON/TIEU_HOC/THCS/THPT/GDTX`; xã không được tạo THPT/GDTX; tác nhân gồm cấp Tỉnh và Xã.
- **Website hiện tại:** mã trường tự sinh, không có trường địa chỉ; tên bắt buộc; cấp học tùy chọn, nếu nhập thì `MN/TH/THCS/THPT`; thông tin tỉnh/xã có thể để trống theo validation. Quyền `manage-schools` được cấp qua nhóm quyền cho tài khoản tỉnh (Mầm non đến THPT) và tài khoản xã (Mầm non đến THCS); không áp dụng cho tài khoản thôn/xóm. Không xóa được trường đang được hồ sơ học tập sử dụng.
- **Gemini sửa:** bỏ mã trường/địa chỉ nhập tay; thay giá trị enum; nêu cấp học tùy chọn, quy tắc trùng tên theo địa bàn+cấp học, mã tự sinh và điều kiện xóa. Sửa tác nhân/quyền theo người được cấp `manage-schools`; không ghi luồng tạo trường cấp xã như chức năng được cấp quyền thông thường.
										


### UC-010 — Phiếu điều tra Hộ gia đình & Nhân khẩu

- **SRS hiện ghi:** phiếu có số phiếu người dùng nhập, mã thôn, họ tên chủ hộ/thành viên, ngày sinh và một trường trình độ học vấn; số phiếu trùng “trong địa bàn”; đầu vào có `is_deleted`.
- **Website hiện tại:** phiếu thủ công tự sinh mã phiếu, năm học là căn cứ xác định tính duy nhất; hộ, từng nhân khẩu, học tập theo năm và khuyết tật/hoàn cảnh là các nhóm dữ liệu riêng. Biểu mẫu có nhiều trường hơn bảng SRS; chủ hộ là hồ sơ nhân khẩu riêng, phải tạo trước thành viên. Xóa là thao tác xóa mềm, không phải dữ liệu nhập vào. CCCD không còn là trường dữ liệu hiện hành.
- **Gemini sửa:** thay toàn bộ bảng đầu vào UC-010 bằng các nhóm dữ liệu và validation ở phần “Quy tắc nghiệp vụ cần thể hiện”; bỏ mô tả mã phiếu thủ công, duy nhất theo địa bàn, `is_deleted` và CCCD; giữ riêng trường mã phiếu bắt buộc đối với import UC-011.

### UC-011 — Import Phiếu điều tra từ Excel

- **SRS hiện ghi:** chỉ nhận `.xlsx`; mô tả lỗi thành danh sách dòng, chưa phân biệt điều kiện chọn địa bàn/năm học và kết quả theo lô.
- **Website hiện tại:** nhận `.xls` và `.xlsx`, tối đa 20 MB; import yêu cầu chọn tỉnh/xã/thôn và chỉ năm học hiện tại. Mẫu dữ liệu bắt đầu từ dòng 5; mỗi dòng là một nhân khẩu, các dòng được nhóm theo mã phiếu. Tệp chạy qua queue; theo dõi trạng thái/tiến độ/lịch sử. Import lỗi bị từ chối theo lô; file gốc có thể tải lại, có luồng tải file lỗi/ghi chú lỗi.
- **Gemini sửa:** bỏ ràng buộc chỉ `.xlsx`; bổ sung 20 MB, năm học hiện tại, địa bàn, cấu trúc file/dòng bắt đầu, queue/trạng thái và nguyên tắc không lưu một phần lô khi validation thất bại. Không hứa màn hình luôn hiển thị danh sách lỗi chi tiết nếu UI cung cấp file lỗi thay vì bảng lỗi.

### UC-012 — Export Excel PCGD

- **SRS hiện ghi:** xuất các báo cáo thống kê chuẩn Bộ GD&ĐT, file Excel có VBA macro; người dùng chọn năm học, cấp học, địa bàn; lỗi nếu không có dữ liệu.
- **Website hiện tại:** chức năng đang chạy là xuất dữ liệu hộ/nhân khẩu theo mẫu `file-mau.XLS` thành file `.xls`, giới hạn theo địa bàn và quyền của người dùng; có thể lọc theo năm học, mã trường, mã hộ và địa bàn. Giao diện xuất hiện tại là tải Excel, không có luồng chọn cấp học hay tạo báo cáo thống kê/VBA.
- **Gemini sửa:** thay mô tả báo cáo thống kê/VBA bằng xuất dữ liệu phiếu điều tra theo mẫu; sửa phần mở rộng sang `.xls`; chỉ ghi các bộ lọc có trên giao diện hiện tại và nêu phạm vi dữ liệu bị giới hạn theo quyền. Nếu báo cáo Bộ GD&ĐT/VBA là yêu cầu tương lai, chuyển sang mục chưa triển khai/cần xác nhận.

### UC-013 — Gộp Thôn/Xóm

- **SRS hiện ghi:** gộp sang “thôn đích mới”; hệ thống tạm khóa nhập liệu; có `ly_do_gop`; ghi log lịch sử và mở khóa.
- **Website hiện tại:** gộp một hoặc nhiều thôn nguồn vào **một thôn đích đã tồn tại** trong cùng xã; thôn đích không được nằm trong danh sách nguồn. Chuyển hộ kể cả hộ đã xóa mềm và cập nhật thôn được giao cho tài khoản; xóa thôn nguồn. Biểu mẫu chỉ chọn thôn nguồn và thôn đích, không có lý do gộp. Thao tác hiện chưa lưu lịch sử gộp thôn.
- **Gemini sửa:** bỏ chữ “thôn đích mới”, bỏ trường `ly_do_gop`, mô tả đúng dữ liệu được chuyển và điều kiện cùng xã. Không khẳng định có bước khóa nhập liệu nếu website không có hành động đó. Không khẳng định đã lưu lịch sử gộp thôn: hiện trạng chưa ghi lịch sử; đánh dấu là thiếu sót/chức năng cần bổ sung nếu nghiệp vụ yêu cầu.

### UC-014 — Gộp Trường học

- **SRS hiện ghi:** Admin tỉnh/xã có thể thực hiện gộp; hệ thống đổi lớp trùng, chuyển học sinh/lớp, xóa trường nguồn và ghi lịch sử.
- **Website hiện tại:** giao diện UC-014 ghi rõ “Chức năng chưa khả dụng”; nút tiến hành bị vô hiệu hóa và hiện chưa thể thực hiện gộp trường.
- **Gemini sửa:** không mô tả thao tác gộp trường như tính năng đang dùng được. Đánh dấu UC-014 chưa khả dụng/chưa triển khai trên website; các quy tắc tương thích, xử lý lớp và chuyển dữ liệu trong SRS chỉ nên ghi là yêu cầu dự kiến nếu chủ dự án muốn giữ.

### UC-015 — Tra cứu Lịch sử Gộp Thôn / Trường

- **SRS hiện ghi:** có menu lịch sử, xem loại gộp/khoảng ngày, chi tiết số lượng dân cư/học sinh chuyển giao.
- **Website hiện tại:** chưa có màn hình tra cứu lịch sử gộp. Chưa có bộ lọc loại/ngày hoặc thông tin số lượng chuyển giao; thao tác gộp thôn cũng chưa lưu lịch sử.
- **Gemini sửa:** đánh dấu tra cứu lịch sử gộp chưa khả dụng trên website; bỏ các trường lọc và nội dung chi tiết chưa có bằng chứng chạy. Nếu giữ trong SRS thì phân biệt rõ yêu cầu dự kiến với hành vi hiện tại.

### UC-016 — Xem Tiện ích Dữ liệu rác

- **SRS hiện ghi:** thùng rác gồm phiếu, nhân khẩu, thôn/xóm, trường học; dùng cờ `is_deleted`; người dùng nhập mã đơn vị để lọc.
- **Website hiện tại:** chỉ liệt kê hộ gia đình và nhân khẩu đã xóa mềm; lọc loại dữ liệu, từ khóa, khoảng ngày xóa, địa bàn (tỉnh/xã/thôn), phân trang. Thời điểm xóa được lưu lại; không dùng cờ `is_deleted`. Chỉ người được cấp quyền xóa phiếu mới được sử dụng và vẫn bị giới hạn theo địa bàn được giao.
- **Gemini sửa:** bỏ thôn/trường và `is_deleted`; mô tả hai loại dữ liệu, `deleted_at`, các bộ lọc thực tế và điều kiện quyền/phạm vi.

### UC-017 — Khôi phục dữ liệu rác

- **SRS hiện ghi:** khôi phục mọi loại bản ghi rác và ghi log khôi phục.
- **Website hiện tại:** chỉ có thao tác khôi phục hộ hoặc nhân khẩu, riêng lẻ hoặc chọn nhiều; không có khôi phục danh mục thôn/trường và không có nhật ký khôi phục riêng. Khôi phục hộ chỉ bỏ trạng thái xóa mềm của hộ; hồ sơ nhân khẩu đã xóa mềm là mục riêng và không tự được khôi phục cùng hộ.
- **Gemini sửa:** giới hạn loại dữ liệu; bỏ khẳng định ghi log; giải thích khôi phục hộ và khôi phục nhân khẩu là hai thao tác riêng. Có thể nêu cần chọn/khôi phục nhân khẩu riêng nếu bản thân chúng đang ở thùng rác.

### UC-018 — Xóa vĩnh viễn dữ liệu rác

- **SRS hiện ghi:** xóa mọi loại dữ liệu; gửi `record_id`, `table_name`, `confirm_flag`; hệ thống ghi log xóa cứng.
- **Website hiện tại:** chỉ xóa vĩnh viễn hộ/nhân khẩu. Giao diện có hộp thoại xác nhận; dữ liệu cần chọn là hộ hoặc nhân khẩu, riêng lẻ hoặc theo danh sách khi thao tác hàng loạt. Không yêu cầu người dùng nhập tên bảng hay cờ xác nhận. Xóa hộ sẽ xóa vĩnh viễn các nhân khẩu liên quan; xóa riêng nhân khẩu sẽ xóa hồ sơ đó và dữ liệu liên quan. Không có nhật ký nghiệp vụ riêng cho thao tác xóa vĩnh viễn.
- **Gemini sửa:** giới hạn loại dữ liệu; cập nhật dữ liệu đầu vào theo hộ/nhân khẩu được chọn và bỏ các trường tên bảng/cờ xác nhận; mô tả bước xác nhận trên giao diện, tác động xóa liên quan và bỏ khẳng định có nhật ký riêng.

## Quy tắc nghiệp vụ cần thể hiện

### UC-008 — Danh mục thôn/xóm

- Người dùng nhập **tên thôn/xóm**; tên bắt buộc, tối đa 255 ký tự.
- Người dùng chọn địa bàn tỉnh/xã theo phạm vi được phân quyền; xã bắt buộc và phải thuộc tỉnh đã chọn.
- **Mã thôn/xóm do hệ thống tự sinh**, không phải trường người dùng tự nhập. Mã gắn với xã.
- Tên thôn/xóm không được trùng trong cùng một xã.
- Ứng dụng không có trường ghi chú thôn/xóm trong biểu mẫu hiện tại.
- Không thể xóa thôn/xóm khi vẫn còn phiếu hộ gia đình đang thuộc địa bàn đó.

Vì vậy, sửa luồng UC-008 và bảng đầu vào cũ: bỏ mô tả người dùng nhập mã thôn và ghi chú; mô tả mã là dữ liệu hệ thống sinh. Không mô tả tính duy nhất của mã là quy tắc người dùng nhập.

### UC-009 — Danh mục trường học

- **Tên trường** bắt buộc, tối đa 255 ký tự.
- **Cấp học** có thể để trống; nếu nhập/chọn thì giá trị hiện được chấp nhận là `MN`, `TH`, `THCS`, `THPT`.
- **Mã trường do hệ thống tự sinh**, tối đa 50 ký tự; không phải trường người dùng tự nhập. Mã là duy nhất trong danh mục.
- Có thông tin địa bàn tỉnh/xã liên kết trường. Có thể để trống mã địa bàn; nếu nhập thì mã phải khớp danh mục và phạm vi quản lý của tài khoản.
- Tên trường không được trùng trong cùng tổ hợp tỉnh, xã và cấp học.
- Không có trường địa chỉ trong biểu mẫu hiện tại.
- Không thể xóa trường đang được hồ sơ học tập của nhân khẩu sử dụng.
- Quyền quản lý danh mục trường được giới hạn theo cấp và địa bàn; không mô tả cấp thôn như một cấp tài khoản riêng.

Vì vậy, trong UC-009, bỏ yêu cầu người dùng nhập mã trường; thay miền giá trị cấp học cũ bằng giá trị hiện tại; bỏ địa chỉ nếu SRS đang mô tả như dữ liệu nhập; phân biệt quy tắc sinh mã với dữ liệu nhập.

### UC-010 — Phiếu hộ gia đình và nhân khẩu

SRS cần tách dữ liệu của **phiếu hộ gia đình**, **nhân khẩu**, **thông tin học tập theo năm học** và **khuyết tật/hoàn cảnh**. Đây là các nhóm dữ liệu liên quan nhưng không phải một trường đơn “trình độ học vấn”.

#### Dữ liệu phiếu hộ gia đình

| Dữ liệu nghiệp vụ | Tên trường hệ thống | Quy tắc hiện tại |
|---|---|---|
| Mã phiếu | `household_code` | Hệ thống tự sinh khi tạo phiếu thủ công; tối đa 50 ký tự. Duy nhất trong một năm học. Trong file Excel, đây là cột bắt buộc để gom các dòng nhân khẩu thuộc cùng phiếu. |
| Năm học | `school_year` | Bắt buộc; định dạng `YYYY-YYYY`. Màn hình phiếu cho chọn các năm từ 2020 đến năm học hiện tại. Riêng import chỉ nhận năm học hiện tại. |
| Họ chủ hộ | `head_last_name` | Bắt buộc, tối đa 50 ký tự. |
| Tên chủ hộ | `head_first_name` | Bắt buộc, tối đa 100 ký tự. |
| Tỉnh/thành phố | `province_code` | Bắt buộc; chọn từ danh mục và theo địa bàn tài khoản. |
| Xã/phường | `commune_code` | Bắt buộc; phải thuộc tỉnh đã chọn và địa bàn tài khoản. |
| Thôn/xóm | `village_code` | Có thể để trống khi lập/sửa phiếu thủ công; nếu có phải thuộc xã. Khi import, phải chọn thôn/xóm làm thông tin của cả đợt nhập. |
| Địa chỉ | `address` | Tùy chọn, tối đa 1.000 ký tự. |
| Loại cư trú | `residence_type` | Bắt buộc; một trong `THUONG_TRU`, `TAM_TRU`, `KHAC`. |
| Tình trạng cư trú | `residence_status` | Tùy chọn, tối đa 100 ký tự. |

Không mô tả mã phiếu là dữ liệu người dùng nhập khi tạo phiếu trên web. Không mô tả tính duy nhất theo địa bàn: ràng buộc hiện tại là duy nhất theo **năm học + mã phiếu**. Mã phiếu trong file Excel vẫn là dữ liệu bắt buộc của từng dòng nhập.

#### Dữ liệu nhân khẩu

| Dữ liệu nghiệp vụ | Tên trường hệ thống | Quy tắc hiện tại |
|---|---|---|
| Họ và tên đệm | `last_name` | Bắt buộc, tối đa 50 ký tự. |
| Tên | `first_name` | Bắt buộc, tối đa 100 ký tự. |
| Ngày sinh | `dob` | Bắt buộc, ngày hợp lệ và không lớn hơn ngày hiện tại. |
| Giới tính | `gender` | Bắt buộc; `NAM` hoặc `NU`. |
| Dân tộc | `ethnicity_code` | Bắt buộc; chọn một dân tộc đã có trong danh mục. |
| Quan hệ với chủ hộ | `relationship_with_head` | Thành viên bắt buộc chọn quan hệ; không được chọn “Chủ hộ”. Hồ sơ chủ hộ được tạo riêng trong luồng hộ gia đình. |
| Tôn giáo | `religion` | Tùy chọn, tối đa 50 ký tự. |
| Diện ưu tiên | `priority_type` | Tùy chọn, tối đa 100 ký tự. |
| Họ tên cha/mẹ | `parent_name` | Tùy chọn, tối đa 150 ký tự. |
| Số điện thoại | `phone` | Tùy chọn, tối đa 20 ký tự. |
| Ghi chú | `note` | Tùy chọn, tối đa 2.000 ký tự. |

Không đưa CCCD/CMND vào danh sách trường hiện hành: thông tin này không có trong biểu mẫu hiện tại. `dob_str` là giá trị ngày sinh nguyên bản phục vụ nhập Excel khi không đọc được ngày sinh theo định dạng chuẩn, không phải trường người dùng nhập trên biểu mẫu thủ công.

Luồng web yêu cầu tạo hồ sơ chủ hộ trước khi thêm thành viên khác. Hệ thống chặn thêm hồ sơ chủ hộ thứ hai và không cho xóa chủ hộ khi hộ còn thành viên. Cơ sở dữ liệu bảo đảm không có nhiều hơn một chủ hộ đang hoạt động trong một hộ.

#### Thông tin học tập theo năm học

Mỗi hồ sơ nhân khẩu có thể có thông tin học tập theo năm học; một nhân khẩu không có hai bản ghi học tập cùng năm. Năm học là bắt buộc khi lưu form học tập. Các thông tin sau tùy chọn, trừ khi có quy tắc cụ thể:

- `school_code`: mã trường đã có trong danh mục và thuộc địa bàn được phép chọn.
- `academic_block`, `current_class`: khối/lớp học hiện tại.
- `graduation_level`: trình độ phổ cập; giá trị được form chấp nhận: `MN`, `TH`, `THCS`, `THPT`.
- `is_complementary`: học bổ túc, kiểu Boolean.
- `graduation_year`: năm tốt nghiệp; không cho nhập năm tương lai.
- `vocational_grad_level`, `vocational_grad_year`: trình độ và năm tốt nghiệp nghề; năm không được ở tương lai.
- `finished_class`, `finished_year`: lớp/năm học xong; năm không được ở tương lai.
- `dropped_class`, `dropped_year`: lớp/năm bỏ học; năm không được ở tương lai.
- `literacy_current_class`, `literacy_completed_class`: lớp đang học và lớp xóa mù chữ đã hoàn thành.
- `literacy_relapse_level`: mức tái mù chữ dạng số nguyên 1–2.
- `learning_capacity`: thông tin khả năng học tập, tối đa 50 ký tự ở form.

Không gộp các thuộc tính này thành một trường “trình độ học vấn” duy nhất trong bảng dữ liệu.

#### Khuyết tật và hoàn cảnh

Các trường dưới đây thuộc thông tin riêng của nhân khẩu; nhóm checkbox là Boolean, mặc định không đánh dấu:

- `mobility_disability` — khuyết tật vận động
- `hearing_speech_disability` — khuyết tật nghe/nói
- `visual_disability` — khuyết tật thị giác
- `mental_disability` — khuyết tật tâm thần
- `intellectual_disability` — khuyết tật trí tuệ
- `learning_disability` — khuyết tật học tập
- `autism` — tự kỷ
- `other_disability` — khuyết tật khác
- `has_disability_cert` — có giấy xác nhận khuyết tật
- `can_study` — khả năng học tập

`special_circumstance` hiện nhận `Chuyển đến` hoặc `Chuyển đi`; `special_circumstance_detail` là nội dung chi tiết tùy chọn, tối đa 2.000 ký tự. Không đưa `is_deleted` vào biểu mẫu thêm/sửa: xóa là thao tác nghiệp vụ mềm, không phải dữ liệu đầu vào của hồ sơ.

### UC-011 — Import phiếu điều tra từ Excel

Import là luồng tạo phiếu và nhân khẩu theo lô, không phải chỉ import một bản ghi nhân khẩu độc lập:

- Người dùng tải tệp `.xls` hoặc `.xlsx`, dung lượng tối đa 20 MB.
- Người dùng chọn năm học, tỉnh, xã và thôn/xóm cho đợt import. Các mã địa bàn phải hợp lệ và phù hợp phạm vi được phân quyền. Năm học import chỉ được là năm học hiện tại.
- Tệp phải theo mẫu `file-mau.XLS`; tiêu đề nằm ở dòng 2 và dữ liệu bắt đầu từ dòng 5. Mỗi dòng dữ liệu mô tả một nhân khẩu; các dòng cùng `Số phiếu` thuộc cùng một hộ.
- Không diễn giải cột trống trong mẫu thành trường bắt buộc. Dữ liệu cốt lõi mà import hiện bắt buộc gồm số phiếu, họ đệm, tên, ngày/tháng/năm sinh hợp lệ và dân tộc có trong danh mục.
- Ngày sinh được ghép từ cột Ngày/Tháng/Năm; không ở tương lai. Mã trường nếu có phải tồn tại và thuộc xã đã chọn. Các năm tốt nghiệp/học xong/bỏ học không được lớn hơn năm hiện tại.
- Cột đánh dấu trong mẫu được đọc là có khi chứa `X`, `Có`, `1`, `Yes` hoặc `True` (không phân biệt hoa thường, dấu); giới tính nữ lấy từ cột “Nữ”, không đánh dấu được hiểu là nam.
- Mã phiếu đã tồn tại trong cùng năm học làm import thất bại để tránh ghi đè/trùng dữ liệu.
- Tệp được xử lý nền; hệ thống lưu trạng thái, số dòng xử lý và báo lỗi theo dòng. Khi có lỗi kiểm tra dữ liệu, không lưu một phần lô đó.

#### Ánh xạ cột mẫu đang dùng

| Cột Excel | Nội dung |
|---|---|
| B | Thứ tự (TT) |
| C–D | Họ đệm, tên |
| E–G | Ngày, tháng, năm sinh |
| H–I | Nữ, dân tộc |
| J–K | Tôn giáo, diện ưu tiên |
| L–M | Họ và tên chủ hộ |
| N–Q | Địa chỉ, số phiếu, diện cư trú, tình trạng cư trú |
| R, S, V | Khối học, lớp học, mã trường |
| W–Y | Bậc tốt nghiệp, bổ túc, năm tốt nghiệp |
| Z, AB | Bậc tốt nghiệp nghề, năm tốt nghiệp nghề |
| AC–AF | Học xong/lớp, năm; bỏ học/lớp, năm |
| AG–AI | Lớp xóa mù chữ đang học, lớp hoàn thành, mức tái mù chữ |
| AJ–AS | Vận động, nghe/nói, nhìn, tâm thần, trí tuệ, học tập, tự kỷ, khuyết tật khác, giấy xác nhận, khả năng học tập |
| AT–AU | Hoàn cảnh đặc biệt, chi tiết hoàn cảnh |
| AV–AY | Quan hệ với chủ hộ, họ tên cha/mẹ, điện thoại, ghi chú |

Cột không được liệt kê (ví dụ T, U, AA) để trống trong mẫu hiện tại; không tự gán nghĩa cho các cột đó. Tên hiển thị chính xác của từng cột cần được kiểm tra trực tiếp trong `file-mau.XLS` nếu cần đưa nguyên văn vào SRS.

## Các điểm cần xác nhận — không tự suy diễn

1. SRS cũ nói mã phiếu duy nhất “trong địa bàn”; triển khai hiện tại áp dụng duy nhất theo năm học trên toàn bộ bảng hộ. Hãy sửa theo triển khai, hoặc đưa quy tắc nghiệp vụ mong muốn ra mục cần xác nhận nếu chủ nghiệp vụ muốn đổi.
2. Form nhập phiếu thủ công cho phép thôn/xóm trống, nhưng form import bắt buộc chọn thôn/xóm. Hãy mô tả riêng hai luồng, không dùng một quy tắc chung cho cả hai.
3. Import kiểm tra và báo lỗi khi xác định được nhiều chủ hộ trong một phiếu, nhưng không có bước kiểm tra riêng bắt buộc phải tìm thấy ít nhất một chủ hộ cho mọi nhóm mã phiếu. Không khẳng định import luôn bảo đảm đúng một chủ hộ cho mỗi hộ nếu chưa xác nhận và cập nhật nghiệp vụ.
4. Một số trường học được lưu tùy chọn trong form, trong khi tài liệu cũ yêu cầu bắt buộc. Hãy ghi theo validation hiện tại; nếu nghiệp vụ yêu cầu bắt buộc thì nêu riêng là đề xuất/chờ xác nhận, không trình bày như chức năng đang chạy.
5. Mẫu Excel có một số trường không xuất hiện trong form web và ngược lại. Ghi rõ trường nào thuộc nhập thủ công, trường nào thuộc import; không khẳng định hai biểu mẫu có bộ trường hoàn toàn giống nhau.

## Checklist rà soát trước khi trả file

- [ ] Đã rà soát đủ UC-001 đến UC-018 và sửa các sai lệch đã liệt kê trong tài liệu này.
- [ ] Đã sửa bảng đầu vào UC-008 đến UC-011 và các luồng/mô tả tổng quan có liên quan.
- [ ] Đã phân biệt dữ liệu nhập tay, dữ liệu chọn danh mục, dữ liệu hệ thống sinh, dữ liệu import.
- [ ] Không còn khai báo CCCD, `is_deleted`, mã thôn/mã trường nhập tay, địa chỉ trường hoặc ghi chú thôn như trường nhập hiện hành.
- [ ] Đã mô tả hộ, nhân khẩu, học tập và khuyết tật/hoàn cảnh thành các nhóm dữ liệu riêng.
- [ ] Đã nêu đúng điều kiện năm học, địa bàn, trường, dân tộc, mã phiếu và xử lý file Excel.
- [ ] Đã cập nhật mục lục/danh mục bảng và giữ nguyên các phần SRS không bị ảnh hưởng.
- [ ] Đã liệt kê rõ mọi điểm chưa đủ căn cứ để chốt nghiệp vụ; không tự tạo yêu cầu mới.
