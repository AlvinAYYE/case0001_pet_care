# Backend (XAMPP + MySQL)

本後端使用純 PHP，搭配 XAMPP 的 Apache + MySQL。

## 快速安裝

1. 啟動 XAMPP 的 Apache 與 MySQL。
2. 到 phpMyAdmin 匯入 `backend/database/schema.sql`。
3. 複製 `backend/.env.example` 成 `backend/.env`。
4. 依環境調整 `backend/.env` 的 DB 設定。
5. 設定安全參數（至少要改）：`APP_API_KEY`、`ADMIN_USER`、`ADMIN_PASS`。
   - 正式環境建議：`APP_ENFORCE_HTTPS_ADMIN=true`
   - `ADMIN_PASS` 可使用明文，或改放 `password_hash()` 產生的雜湊值
6. API 基底網址：
   - `http://localhost/case0001_20260301/backend/public/api`

## API 清單

- `GET /api/news`（相容：`/api/news.php`）
- `GET /api/senior-care`（相容：`/api/senior-care.php`）
- `GET /api/health`
- `GET /api/content`
- `PUT /api/content/sections/{section_key}`
- `GET /api/content/media`
- `POST /api/content/media`
- `PUT /api/content/media/{id}`
- `DELETE /api/content/media/{id}`
- `GET /api/content/links`
- `POST /api/content/links`
- `PUT /api/content/links/{id}`
- `DELETE /api/content/links/{id}`
- `GET /api/content/store-info`
- `PUT /api/content/store-info`
- `POST /api/upload`（multipart/form-data，欄位名 `file`）

## Bootstrap 後台

- 路徑：`http://localhost/case0001_20260301/backend/public/admin/index.php`
- 功能：
  - 主視覺內容與背景圖編輯
  - 最新消息 CRUD
  - 樂齡館內容編輯（對應前端 `senior-care` 區塊）
  - 文章分享 CRUD
  - 關於恆寵愛內容與彈窗編輯
- 認證：登入頁 + PHP Session（帳密來自 `.env` 的 `ADMIN_USER` / `ADMIN_PASS`）
- 安全機制：
  - 可用 `.env` 的 `APP_ENFORCE_HTTPS_ADMIN=true` 強制 `/admin` 只走 HTTPS，並回傳 HSTS
  - 後台表單已加上 CSRF token 驗證
  - Session cookie 預設帶 `HttpOnly`、`SameSite=Strict`，在 HTTPS 下自動加 `Secure`

## API 寫入權限

- 所有寫入 API（POST/PUT/DELETE）需帶 `X-API-Key` header
- 值為 `.env` 的 `APP_API_KEY`

## DEV 資料庫匯入保護

- `POST /api/dev/db/import` 僅在 `.env` 設定 `APP_ENABLE_DEV_API=true` 時可用
- 匯入流程目前為：
  1. 先完整備份目前資料庫
  2. `DROP DATABASE` 並重新建立同名資料庫
  3. 匯入上傳的 SQL
  4. 檢查資料表是否存在且基本查詢正常
- 若匯入或驗證失敗，會自動重建資料庫並還原剛才的備份
- 這個流程會整庫重建，不適合在正式環境使用

```ts
const api = axios.create({
  baseURL: 'http://localhost/case0001_20260301/backend/public/api',
  headers: {
    'X-API-Key': '<your-app-api-key>',
  },
});
```

## 前端 Axios 串接範例

```ts
const api = axios.create({
  baseURL: 'http://localhost/case0001_20260301/backend/public/api',
});
```

上傳成功後 `file_path` 會回傳 `/uploads/<filename>`。
實際圖片 URL：
- `http://localhost/case0001_20260301/backend/public/uploads/<filename>`

## Store Info 欄位調整

- `store_info` 已移除：`store_name`, `phone`, `address`, `line_url`, `ig_url`, `fb_url`, `map_url`
- 目前僅保留可寫欄位：`business_hours`
