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

| Resource                      | Endpoints                                                                                                                   | Required permission                                                                                    |
| ----------------------------- | --------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| Hộ gia đình                   | `GET, POST /households`; `GET, PUT, DELETE /households/{household}`                                                         | `view-households`, `create-households`, `update-households`, `delete-households` respectively          |
| Nhân khẩu                     | `GET, POST /households/{household}/persons`; `GET, PUT, DELETE /households/{household}/persons/{personId}`                  | Household permissions for the matching operation                                                       |
| Xóa nhiều hộ/nhân khẩu        | `DELETE /households` with `household_ids`; `DELETE /households/persons` with `person_ids`                                   | `delete-households`                                                                                    |
| Thùng rác                     | `GET /trash`                                                                                                                | `view-trash`                                                                                           |
| Khôi phục thùng rác           | `PATCH /trash/households/{id}`; `PATCH /trash/persons/{id}`; `POST /trash/batch-operations` với `operation: restore`        | `update-trash`                                                                                         |
| Xóa vĩnh viễn                 | `DELETE /trash/households/{id}`; `DELETE /trash/persons/{id}`; `POST /trash/batch-operations` với `operation: force_delete` | `delete-trash`                                                                                         |
| Tỉnh, xã, trường theo địa bàn | `GET /provinces/{provinceCode}/communes`; `GET /communes/{communeCode}/villages`; `GET /provinces/{provinceCode}/schools`   | Authenticated user and assigned data scope                                                             |
| Thôn/xóm                      | `GET /villages`; `POST /villages`; `GET /villages/{code}`                                                                   | `view-villages`, `create-villages` respectively                                                        |
| Thôn/xóm                      | `GET /villages/{code}/form`; `PUT /villages/{code}`; `DELETE /villages/{code}`                                              | `update-villages`, `delete-villages` respectively                                                      |
| Gộp thôn/xóm                  | `GET /village-merges`; `POST /village-merges`                                                                               | `view-village-merges`, `create-village-merges` respectively                                            |
| Tạm dừng/mở lại nhận phiếu    | `POST /village-merges/pause`; `POST /village-merges/resume` with `source_codes`                                             | `create-village-merges`                                                                                |
| Trường học                    | `GET /schools`; `POST /schools`; `GET /schools/{id}`                                                                        | `view-schools`, `create-schools`; school records include `is_active` and default to active              |
| Trường học                    | `GET /schools/{id}/form`; `PUT /schools/{id}`; `DELETE /schools/{id}`                                                       | `update-schools`, `delete-schools` respectively; `is_active` can be updated                            |
| Gộp trường học                | `GET /school-merges` (`q`, `target_code`, `page`, `per_page`); `POST /school-merges`                                       | `view-school-merges`, `create-school-merges`; list defaults to 30 items (maximum 100). Any active school in the user's permitted area and levels may be selected as a target. A single-level target accepts single-level sources at the same level; a multi-level target accepts the same and lower general-education levels; preschool only merges with preschool. Geography must match the source level. Blocks pending imports in affected communes, moves education records, renames conflicting classes with the source school code, and retains source schools as inactive records. |
| Lịch sử gộp                   | `GET /merge-histories`                                                                                                      | `view-school-merges` and/or `view-village-merges`; results are limited to the authorized history types |
| Nhập dữ liệu                  | `GET /household-imports`, `/household-imports/{id}`, `/household-imports/statuses`; `POST /household-imports`               | `view-imports`, `create-imports` respectively                                                          |
| Lịch sử nhập                  | `DELETE /household-imports/{id}`                                                                                            | `delete-imports`                                                                                       |
| Tệp nhập                      | `GET /household-imports/template`; `GET /household-imports/{id}/file`; `GET /household-imports/{id}/error-file`             | Template: `create-imports`; files: `view-imports`                                                      |
| Xuất dữ liệu                  | `GET /household-exports`                                                                                                    | `create-exports`                                                                                       |
| Thông báo nhập                | `GET /household-import-notifications`; `PATCH /household-import-notifications/read`                                         | `create-imports`                                                                                       |
| Nhóm quyền                    | `GET /roles`, `/roles/{role}`, `/roles/{role}/users`; `POST /roles`; `PUT, DELETE /roles/{role}`                            | `view-roles`, `create-roles`, `update-roles`, `delete-roles` respectively                              |
| Tài khoản                     | `GET /users`, `/users/{user}`; `POST /users`; `PUT /users/{user}`                                                           | `view-users`, `create-users`, `update-users` respectively                                              |
| Trạng thái tài khoản          | `PATCH /users/{user}/status`; `POST /users/{user}/password-resets`                                                          | Activate/password reset: `update-users`; revoke: `delete-users`                                        |
| Hồ sơ cá nhân                 | `GET /account`; `PATCH /account`, `/account/password`                                                                       | Authenticated user                                                                                     |
| Trang tổng quan               | `GET /dashboard`                                                                                                            | Authenticated user                                                                                     |
| Tổng quan toàn quốc           | `GET /dashboard/overview`                                                                                                   | `system-admin`                                                                                         |

Resource updates use `PUT` with the complete resource payload; resource `PATCH`
routes are not available. Other action endpoints may use `PATCH` where noted
(account profile/password, account status, trash restore, and notification acknowledgement).
Changing the account password requires the current password and a confirmed new
password of at least 12 characters. The account profile can update name, email,
phone, 12-digit citizen ID, and birth date; citizen IDs must be unique and birth
dates cannot be in the future.
`POST` creates resources; successful creation returns `201`. Validation failures return `422`
with `error.code: VALIDATION_FAILED` and field-specific `error.details`.
Authentication, authorization, and missing resources return `401`, `403`, and
`404` respectively using the same error envelope. File downloads keep their
binary response rather than a JSON envelope. Household and person queries remain
restricted to the authenticated user's assigned geographic scope.

Household imports are queued. Valid household groups are committed independently;
invalid rows do not prevent valid rows from being imported. A completed import is
reported as `SUCCESS`, `PARTIAL`, or `FAILED`; the error workbook contains only
rows that need correction. Users with `create-imports` can poll and acknowledge
their own completion notifications while continuing to use the application.

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
