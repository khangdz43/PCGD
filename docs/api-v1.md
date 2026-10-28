# API v1

Base URL: `/api/v1`. API responses use JSON and authenticate with the Laravel
web session cookie.

## Authentication

First request `GET /csrf-cookie` to initialize the session and CSRF cookie, then
sign in with `POST /login`:

```json
{
  "username": "ten-dang-nhap",
  "password": "mat-khau",
  "remember": false
}
```

Send the session cookie and `X-XSRF-TOKEN` cookie value as the
`X-XSRF-TOKEN` header on state-changing requests. After login, use
`GET /session` to obtain the current user and permissions. Call `POST /logout`
to end the session.

All JSON responses use a shared envelope. A successful response contains
`success: true`, the HTTP `status_code`, a `message`, and `data`. A failed response
contains `success: false`, `status_code`, `message`, and `error` with a stable
error `code` and `details`. Validation details are returned as `{ field, issue }`
entries. Paginated responses also include a `meta` object.

## Resources

| Resource | Endpoints | Required permission |
|---|---|---|
| Hộ gia đình | `GET, POST /households`; `GET, PUT, DELETE /households/{household}` | `view-households`, `create-households`, `update-households`, `delete-households` respectively |
| Nhân khẩu | `GET, POST /households/{household}/persons`; `GET, PUT, DELETE /households/{household}/persons/{personId}` | Household permissions for the matching operation |
| Xóa nhiều hộ/nhân khẩu | `DELETE /households` with `household_ids`; `DELETE /households/persons` with `person_ids` | `delete-households` |
| Thùng rác | `GET /trash`; `GET, PATCH, DELETE /trash/households/{id}`; `PATCH, DELETE /trash/persons/{id}`; `POST /trash/batch-operations` | `delete-households` |
| Tỉnh, xã, trường theo địa bàn | `GET /provinces/{provinceCode}/communes`; `GET /communes/{communeCode}/villages`; `GET /provinces/{provinceCode}/schools` | Authenticated user and assigned data scope |
| Thôn/xóm | `GET, POST /villages`; `GET, PUT, DELETE /villages/{code}` | `manage-villages` |
| Gộp thôn/xóm | `GET, POST /village-merges` | `manage-villages` |
| Trường học | `GET, POST /schools`; `GET, PUT, DELETE /schools/{id}` | `manage-schools` |
| Nhập dữ liệu | `POST /household-imports`; `GET /household-imports`, `/household-imports/{id}`, `/household-imports/statuses`; `DELETE /household-imports/{id}` | `import-households` |
| Tệp nhập | `GET /household-imports/template`; `GET /household-imports/{id}/file`; `GET /household-imports/{id}/error-file` | Template: `export-households`; files: `import-households` |
| Xuất dữ liệu | `GET /household-exports` | `export-households` |
| Thông báo nhập | `GET /household-import-notifications`; `PATCH /household-import-notifications/read` | `import-households` |
| Nhóm quyền | `GET, POST /roles`; `GET, PUT, DELETE /roles/{role}`; `GET /roles/{role}/users` | `manage-access-control` |
| Tài khoản | `GET, POST /users`; `GET, PUT, DELETE /users/{user}`; `PATCH /users/{user}/status`; `POST /users/{user}/password-resets` | `manage-user-accounts` |
| Hồ sơ cá nhân | `GET /account`; `PATCH /account/password` | Authenticated user |
| Trang tổng quan | `GET /dashboard` | Authenticated user |

Resource updates use `PUT` with the complete resource payload; resource `PATCH`
routes are not available. Other action endpoints may use `PATCH` where noted
(password, account status, trash restore, and notification acknowledgement).
`POST` creates resources; successful creation returns `201`. Validation failures return `422`
with `error.code: VALIDATION_FAILED` and field-specific `error.details`.
Authentication, authorization, and missing resources return `401`, `403`, and
`404` respectively using the same error envelope. File downloads keep their
binary response rather than a JSON envelope. Household and person queries remain
restricted to the authenticated user's assigned geographic scope.

The global API exception handler uses localized default messages and stable error
codes. Resource-specific not-found errors use messages such as
`Không tìm thấy thôn/xóm này.`; explicit custom HTTP exception messages are
preserved. Unexpected server errors return a generic message without exposing
internal exception details.

## Lọc và phân trang

Các danh sách hộ gia đình, nhân khẩu trong hộ, thùng rác, thôn/xóm, trường học,
lịch sử nhập, nhóm quyền, tài khoản và thành viên nhóm quyền nhận `page` (bắt đầu từ `1`) và
`per_page` (từ `1` đến `100`). Mỗi danh sách có giới hạn mặc định riêng nếu
không truyền `per_page`. Bộ lọc tìm kiếm và địa bàn được mô tả trong các endpoint API.
Phân trang trả metadata trong `meta`, bao gồm `current_page`, `last_page`,
`per_page` và `total`.

Endpoints that only supplied HTML-form defaults (`/create`, `/edit`, and
`/form`) are not part of this API. Clients should send the resource fields
directly to the collection or item endpoint.
