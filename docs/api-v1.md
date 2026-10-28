# API v1

Base URL: `/api/v1`. The API is stateless and authenticates with Laravel Sanctum
personal access tokens. It does not use the web session or CSRF-cookie flow.

Swagger UI: `/api/docs`  
OpenAPI specification: `/api/openapi.yaml`

## Authentication

Create a token with `POST /auth/tokens`:

```json
{
  "username": "ten-dang-nhap",
  "password": "mat-khau"
}
```

The `201 Created` response contains `data.access_token`, `data.token_type`
(`Bearer`), `data.expires_at`, and the authenticated user. Access tokens expire after
eight hours. Send the token on protected requests:

Set the `Authorization` header to the `Bearer` scheme followed by the value of
`data.access_token`, and send `Accept: application/json`.

`GET /me` returns the authenticated user and permissions.
`DELETE /auth/tokens/current` revokes the current token and returns `204 No
Content`.

## Resources

| Resource | Endpoints | Required permission |
|---|---|---|
| Hộ gia đình | `GET, POST /households`; `GET, PUT, PATCH, DELETE /households/{household}` | `view-households`, `create-households`, `update-households`, `delete-households` respectively |
| Nhân khẩu | `GET, POST /households/{household}/persons`; `GET, PUT, PATCH, DELETE /households/{household}/persons/{personId}` | Household permissions for the matching operation |
| Xóa nhiều hộ/nhân khẩu | `DELETE /households` with `household_ids`; `DELETE /households/persons` with `person_ids` | `delete-households` |
| Thùng rác | `GET /trash`; `GET, PATCH, DELETE /trash/households/{id}`; `PATCH, DELETE /trash/persons/{id}`; `POST /trash/batch-operations` | `delete-households` |
| Tỉnh, xã, trường theo địa bàn | `GET /provinces/{provinceCode}/communes`; `GET /communes/{communeCode}/villages`; `GET /provinces/{provinceCode}/schools` | Authenticated user and assigned data scope |
| Thôn/xóm | `GET, POST /villages`; `GET, PUT, PATCH, DELETE /villages/{code}` | `manage-villages` |
| Gộp thôn/xóm | `GET, POST /village-merges` | `manage-villages` |
| Trường học | `GET, POST /schools`; `GET, PUT, PATCH, DELETE /schools/{id}` | `manage-schools` |
| Nhập dữ liệu | `POST /household-imports`; `GET /household-imports`, `/household-imports/{id}`, `/household-imports/statuses`; `DELETE /household-imports/{id}` | `import-households` |
| Tệp nhập | `GET /household-imports/template`; `GET /household-imports/{id}/file`; `GET /household-imports/{id}/error-file` | Template: `export-households`; files: `import-households` |
| Xuất dữ liệu | `GET /household-exports` | `export-households` |
| Thông báo nhập | `GET /household-import-notifications`; `PATCH /household-import-notifications/read` | `import-households` |
| Nhóm quyền | `GET, POST /roles`; `GET, PUT, PATCH, DELETE /roles/{role}`; `GET /roles/{role}/users` | `manage-access-control` |
| Tài khoản | `GET, POST /users`; `GET, PUT, PATCH /users/{user}`; `PATCH /users/{user}/status`; `POST /users/{user}/password-resets` | `manage-user-accounts` |
| Hồ sơ cá nhân | `GET /account`; `PATCH /account/password` | Authenticated user |
| Trang tổng quan | `GET /dashboard` | Authenticated user |

`PUT` replaces a resource and `PATCH` applies a partial update. `POST` creates
resources; successful creation returns `201`. Validation failures return `422`
with Laravel's JSON `message` and `errors` fields. Authentication, authorization,
and missing resources return `401`, `403`, and `404` respectively. Household
and person queries remain restricted to the authenticated user's assigned
geographic scope.

Endpoints that only supplied HTML-form defaults (`/create`, `/edit`, and
`/form`) are not part of this API. Clients should send the resource fields
directly to the collection or item endpoint.
