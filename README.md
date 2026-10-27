# PCGD-XMC

Ứng dụng web quản lý dữ liệu Phổ cập giáo dục - Xóa mù chữ, xây dựng trên Laravel 12.

## Cấu trúc ứng dụng

- `app/Http/Controllers/Geography`: tỉnh, xã và thôn/xóm.
- `app/Http/Controllers/Education`: trường học.
- `app/Http/Controllers/Households`: hộ gia đình, nhân khẩu và nhập/xuất dữ liệu.
- `app/Http/Controllers/Admin/AccessControl`: nhóm người dùng/phòng ban và quản lý tài khoản theo địa bàn.
- `app/Http/Controllers/Api`: session, xác thực và endpoint JSON.
- `app/Models`: Eloquent models, giữ namespace ổn định `App\Models`.
- `app/Support`: định nghĩa quyền và giới hạn truy cập dữ liệu theo địa bàn.
- `app/Http/Requests`: xác thực và authorize dữ liệu đầu vào theo từng nghiệp vụ.
- `app/Services`: xử lý nghiệp vụ, truy vấn và cập nhật dữ liệu; tổ chức service theo miền.
- `app/Jobs`: tác vụ nền dài như xử lý nhập Excel; job gọi service, không gọi controller.
- `routes/api.php`: các endpoint JSON phiên bản `/api/v1`; backend không đăng ký route giao diện Blade.
- `frontend`: ứng dụng Vue 3 + TypeScript riêng, chạy Vite và gọi JSON API.

Controller được nhóm theo miền nghiệp vụ và chỉ điều phối request/response. Validation đặt trong Form Request; nghiệp vụ dùng chung, truy vấn và cập nhật dữ liệu đặt trong service tương ứng. Models giữ tại `App\Models` để các quan hệ và phần tích hợp hiện tại không bị phá vỡ.

## Frontend

Frontend được tổ chức độc lập trong thư mục `frontend`, build và chạy riêng với Laravel. Frontend gọi JSON API qua proxy Vite; backend giữ session đăng nhập, kiểm tra quyền và nghiệp vụ hiện có. Giao diện Blade và các route web đã được gỡ khỏi backend.

```bash
# Terminal 1: backend API
php artisan serve --host=127.0.0.1 --port=8000

# Terminal 2: frontend Vue
cd frontend
npm install
npm run dev
```

Nếu dùng nhập Excel qua queue, chạy thêm `php artisan queue:work` trong terminal riêng. Mở `http://127.0.0.1:5173`. Vite proxy các request `/api` sang Laravel ở cổng `8000`. Các lệnh `npm run typecheck` và `npm run build` chạy trong thư mục `frontend`; `composer dev` chỉ khởi chạy backend API.

## Cài đặt và tạo quản trị viên đầu tiên

Sau khi cài dependencies và cấu hình cơ sở dữ liệu trong `.env`, chạy:

```bash
php artisan migrate
php artisan app:create-admin
```

Lệnh `app:create-admin` hỏi tên, email và mật khẩu tương tác; mật khẩu phải có ít nhất 12 ký tự và không được lưu sẵn trong mã nguồn. Tài khoản này có quyền quản trị toàn hệ thống và khởi tạo các quyền nghiệp vụ. Trước khi tạo tài khoản tỉnh, Trung ương tạo một nhóm quyền cấp Tỉnh phù hợp; các tài khoản tỉnh/xã tự tạo nhóm quyền cấp dưới trong phạm vi của mình.

## Mô hình phân quyền

- Nhóm người dùng áp dụng đúng một cấp địa bàn và tài khoản phải được gán nhóm cùng cấp. Trung ương chỉ tạo tài khoản cấp tỉnh; quản trị viên tỉnh chỉ tạo tài khoản cấp xã; quản trị viên xã chỉ tạo tài khoản cấp thôn/xóm. Không được nhảy qua cấp dưới và không tạo thêm tài khoản cùng cấp.
- Mỗi tỉnh chỉ có một tài khoản quản trị tỉnh đang hoạt động; mỗi xã chỉ có một tài khoản quản trị xã đang hoạt động. Tài khoản đã thu hồi quyền đăng nhập không chiếm chỗ, nên có thể tạo quản trị thay thế.
- Khi tạo tài khoản, cấp hoạt động được giới hạn theo phân cấp và địa bàn quản lý. Trung ương tạo nhóm cấp Tỉnh để khởi tạo tài khoản tỉnh; mỗi tài khoản tỉnh/xã tự tạo nhóm quyền dùng riêng cho cấp dưới trực tiếp. Tài khoản địa phương chỉ thấy nhóm đang được gán cho mình và các nhóm do chính tài khoản đó tạo; nhóm của tài khoản khác không được dùng chung.
- Quyền nhóm được giới hạn theo chức năng đã triển khai và cấp áp dụng: cấp tỉnh quản lý trường học, nhóm quyền và tài khoản, không quản trị phiếu/nhân khẩu; cấp xã quản lý thôn/xóm và phiếu/nhân khẩu; cấp thôn/xóm chỉ có các quyền phiếu/nhân khẩu được giao chi tiết. Tài khoản tỉnh có thể tạo nhóm cấp xã với quyền nghiệp vụ của cấp xã nhưng không nhận các quyền đó cho tài khoản tỉnh.
- Vai trò không đúng cấp tài khoản không được gán; nhóm toàn hệ thống chỉ dành cho quản trị Trung ương. Quyền ngoài cấp bị chặn cả ở biểu mẫu lẫn khi kiểm tra quyền lúc chạy.
- Quyền quản lý nhóm/tài khoản chỉ được giao cho cấp dưới khi người quản lý được cấp quyền tương ứng; việc tạo tài khoản và phân công địa bàn vẫn bị giới hạn trong phạm vi được giao.
- Nhóm quyền nghiệp vụ không được tạo sẵn dùng chung. Mỗi tài khoản quản lý tự tạo nhóm phù hợp với nghiệp vụ và cấp dưới mình phụ trách.
- Các chức năng chưa được triển khai trong ứng dụng như phê duyệt/báo cáo liên cấp không được thêm thành quyền giả lập.
- Tạo, sửa, xóa, nhập và xuất phiếu yêu cầu quyền xem phiếu; khi gán các quyền này, hệ thống tự gán kèm quyền xem. Quyền xem riêng không cấp quyền thao tác khác.
- Danh mục thôn/xóm và trường học có quyền quản lý riêng; quyền quản lý danh mục cũ được chuyển thành cả hai quyền khi nâng cấp.
- Việc truy vấn, nhập/xuất và thao tác dữ liệu được giới hạn theo địa bàn của tài khoản. Mã trường học chỉ là thông tin giáo dục, không còn xác định phạm vi quyền quản lý.
- Quản trị hệ thống vẫn có toàn quyền nghiệp vụ; giới hạn cấp tỉnh ở trên áp dụng cho việc tạo nhóm/tài khoản địa bàn.
- Tạo, sửa, xóa, nhập và xuất phiếu yêu cầu quyền xem phiếu; khi gán các quyền này, hệ thống tự gán kèm quyền xem. Quyền xem riêng không cấp quyền thao tác khác.
- Danh mục thôn/xóm và trường học có quyền quản lý riêng; quyền quản lý danh mục cũ được chuyển thành cả hai quyền khi nâng cấp.
- Việc truy vấn, nhập/xuất và thao tác dữ liệu được giới hạn theo địa bàn của tài khoản. Mã trường học chỉ là thông tin giáo dục, không còn xác định phạm vi quyền quản lý.
- Quản trị hệ thống quản lý danh mục quyền thao tác; nhóm người dùng và tài khoản được quản lý trong phạm vi địa bàn, nếu tài khoản được cấp quyền tương ứng.

## Kiểm thử

```bash
php artisan test
```

Để tạo bộ tài khoản thử nghiệm quyền trên DB local, đặt biến môi trường `PCGD_TEST_ACCOUNT_PASSWORD` thành mật khẩu tạm tối thiểu 12 ký tự rồi chạy:

```bash
php artisan db:seed --class=AccessControlTestAccountsSeeder
```

Các tài khoản có email `pcgd-test-*@example.test`; seeder chỉ tạo/cập nhật các tài khoản mang tiền tố này và không tự chạy trong `DatabaseSeeder`. Hãy thu hồi hoặc xóa các tài khoản test sau khi kiểm tra xong.
