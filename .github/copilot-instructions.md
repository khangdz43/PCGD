# Hướng dẫn cho GitHub Copilot — PCGD-XMC

Ứng dụng web quản lý dữ liệu **Phổ cập giáo dục - Xóa mù chữ** (PCGD), xây dựng trên **Laravel 12** + **PHP 8.2** + **MySQL** + **Vite 7 / Tailwind CSS 4**. Giao diện và comment bằng **tiếng Việt**.

## Lệnh chính

```bash
composer setup   # cài deps, tạo .env, key, migrate, npm install, npm run build
composer dev     # serve + queue + pail + vite (chạy song song)
composer test    # php artisan config:clear && php artisan test
php artisan test # chạy test PHPUnit
php artisan migrate
php artisan app:create-admin   # tạo admin tương tác (không hardcode mật khẩu)
```

- Môi trường: XAMPP local, DB `pcgd` (MySQL 127.0.0.1, user `root`, không mật khẩu) — xem `.env`.
- Frontend không dùng framework JS; chỉ Vite + Tailwind qua `resources/css/app.css` và `resources/js/app.js`.

## Cấu trúc dự án

- `app/Http/Controllers/Geography`: Province, Commune, Village (tỉnh/xã/thôn).
- `app/Http/Controllers/Education`: SchoolController.
- `app/Http/Controllers/Households`: hộ gia đình, nhân khẩu (person), import/export.
- `app/Http/Controllers/Admin/AccessControl`: RoleController, UserRoleController (nhóm người dùng/phòng ban, tài khoản).
- `app/Http/Controllers/Auth`: đăng nhập/đăng xuất.
- `app/Models`: **luôn giữ namespace `App\Models`** — không chia nhỏ namespace models.
- `app/Support`: `AccessPermission` (định nghĩa quyền), `DataAccessScope` (giới hạn dữ liệu theo địa bàn), `DelegatedAccess` (ủy quyền).
- `routes/web.php` (nghiệp vụ, đã auth), `routes/auth.php` (đăng nhập), `routes/admin.php` (quản trị), `routes/console.php`.
- `resources/views`: Blade theo nghiệp vụ; layout chung `layouts/app.blade.php`, partials `header/navbar/location-picker`.
- `docs/database-erd.md`: ERD Mermaid + ràng buộc DB (trigger, unique).
- Tests: `tests/Feature/AccessControlTest.php` là test chính.

## Phân quyền (quan trọng)

- Quyền thao tác được khai báo bằng hằng số trong `App\Support\AccessPermission` (vd `view-households`, `import-households`, `manage-villages`, `manage-schools`) và seed bởi `database/seeders/RolePermissionSeeder`. Tạo, sửa, xóa, nhập, xuất phiếu yêu cầu quyền xem; quyền danh mục thôn/xóm và trường học được tách riêng.
- `AppServiceProvider::boot()` đăng ký `Gate::before`: `SYSTEM_ADMIN` được tudo; ability có trong `AccessPermission::definitions()` kiểm tra qua `$user->hasPermissionTo()`.
- Route bảo vệ bằng middleware `can:<permission>` (vd `Route::middleware('can:export-households')`), nhóm auth dùng `['auth', 'active']` (`active` = `EnsureUserIsActive`).
- Mọi truy vấn dữ liệu phải lọc theo địa bàn qua `DataAccessScope::households($query, $user)`; kiểm tra 1 bản ghi qua `DataAccessScope::canAccessHousehold()`. Scope 3 cấp: `province` / `commune` / `village` (`user->managedVillageCodes()`).
- Tài khoản có phạm vi địa lý (`province_code`, `commune_code`); tài khoản thôn gán nhiều thôn qua bảng `user_villages`.
- Nhóm người dùng/phòng ban chỉ gán quyền nghiệp vụ; cấp và địa bàn hoạt động được gán riêng trên từng tài khoản.
- Mã trường học chỉ là thông tin giáo dục, **không** dùng để xác định phạm vi quyền.

## Quy ước code

- Comment, nhãn UI, tên route, validation message: tiếng Việt.
- Controller nhóm theo miền nghiệp vụ (namespace `App\Http\Controllers\<Miền>`).
- Dùng resource controller + `Route::resource` cho CRUD danh mục; hộ gia thủ tay route theo permission vì check từng nhóm quyền riêng.
- Models Eloquent; relations giữ ổn định, không đổi tên bảng/cột đã có migration.
- UI Blade + Tailwind, phân trang dùng view tùy biến `vendor/pagination/bootstrap-5`.
- Import/Export Excel dùng `maatwebsite/excel` + `phpoffice/phpspreadsheet`; log vào bảng `import_logs`.
- Ràng buộc nghiệp vụ có cả ở DB (trigger `persons_one_head_before_insert/update`, unique `(school_year, household_code)`) — khi sửa logic cần đồng bộ cả migration.
- Không thêm dependency mới khi chưa trao đổi; luôn chạy `php artisan test` sau khi đổi.
