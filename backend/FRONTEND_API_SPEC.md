# Frontend API Spec (Latest Backend)

此文件依目前 `backend/public/index.php` 的實際路由更新，供前端直接串接。

## Base URL

- 同源部署：`/api`
- 本機 XAMPP 直連：`http://localhost/case0001_20260301/backend/public/api`

## Global Rules

- JSON API：`Content-Type: application/json`
- Upload API：`multipart/form-data`
- 寫入 API（`POST` / `PUT` / `DELETE`）需帶 `X-API-Key: <APP_API_KEY>`
- 公開讀取 API 不需驗證

## Response Convention

### A. 標準包裝型

```json
{
  "success": true,
  "data": {}
}
```

```json
{
  "success": false,
  "message": "error message"
}
```

### B. 直接資料型

以下公開內容 API 直接回傳資料，不包 `success`：
- `/news`
- `/articles`
- `/senior-care`
- `/about`

## TypeScript Types

```ts
export interface Section {
  id: number;
  section_key: string;
  title: string | null;
  content: string | null;
  updated_at: string;
}

export interface MediaAsset {
  id: number;
  media_type: 'image' | 'poster';
  title: string | null;
  file_path: string;
  alt_text: string | null;
  sort_order: number;
  is_active: 0 | 1;
  created_at: string;
  updated_at: string;
}

export interface ExternalLink {
  id: number;
  platform: string;
  label: string | null;
  url: string;
  is_active: 0 | 1;
  sort_order: number;
  created_at: string;
  updated_at: string;
}

export interface StoreInfo {
  id: 1;
  business_hours: string | null;
  updated_at: string;
}

export interface PublicPostItem {
  id: number;
  title: string;
  excerpt: string;
  imageUrl: string;
  publishedAt: string;
  link: string | null;
}

export interface SeniorCareContent {
  id: 1;
  title: string;
  subtitle: string;
  description: string;
  tags: string;
  imageUrl: string;
  updated_at: string;
}

export interface AboutContent {
  id: 1;
  title: string;
  content: string;
  updated_at: string | null;
}

export interface AboutModal {
  id: number;
  modal_key: 'daycare_assessment' | 'hours' | 'manager' | string;
  title: string;
  content: string;
  image_url: string | null;
  updated_at: string;
}

export interface AboutPayload {
  content: AboutContent;
  modals: AboutModal[];
}
```

## Public Read APIs

### `GET /health`

```json
{
  "success": true,
  "message": "Backend is running.",
  "timestamp": "2026-03-09T23:57:56+08:00"
}
```

### `GET /news`
Alias: `GET /news.php`

Response: `PublicPostItem[]`

### `GET /articles`
Alias: `GET /articles.php`

Response: `PublicPostItem[]`

### `GET /senior-care`
Alias: `GET /senior-care.php`

Response: `SeniorCareContent | {}`

```json
{
  "id": 1,
  "title": "樂齡館",
  "subtitle": "高齡犬與特殊需求毛孩照護",
  "description": "...",
  "tags": "#安靜休養 #高齡犬照護",
  "imageUrl": "https://...",
  "updated_at": "2026-03-09 23:56:29"
}
```

### `GET /about`
Alias: `GET /about.php`

Response: `AboutPayload`

```json
{
  "content": {
    "id": 1,
    "title": "恆寵愛 Perpetuity",
    "content": "...",
    "updated_at": "2026-03-09 23:56:29"
  },
  "modals": [
    {
      "id": 1,
      "modal_key": "daycare_assessment",
      "title": "入住前安親評估",
      "content": "...",
      "image_url": null,
      "updated_at": "2026-03-09 23:56:29"
    }
  ]
}
```

## Admin / Content APIs

### `GET /content`

回傳整包後台資料：
- `sections`
- `media`
- `links`
- `store_info`
- `about_content`
- `about_modals`
- `articles`
- `senior_care`

`sections` 內主視覺相關 key：
- `hero`：`title` / `content`
- `hero_visual`：背景圖路徑（存放於 `content`）

### `PUT /content/sections/{section_key}`

```json
{
  "title": "主標題",
  "content": "區塊內容"
}
```

### Media CRUD

- `GET /content/media`
- `POST /content/media`
- `PUT /content/media/{id}`
- `DELETE /content/media/{id}`

POST/PUT body:

```json
{
  "media_type": "image",
  "title": "海報 A",
  "file_path": "/uploads/example.jpg",
  "alt_text": "活動海報",
  "sort_order": 1,
  "is_active": true
}
```

### Links CRUD

- `GET /content/links`
- `POST /content/links`
- `PUT /content/links/{id}`
- `DELETE /content/links/{id}`

POST/PUT body:

```json
{
  "platform": "line",
  "label": "官方 LINE",
  "url": "https://line.me/ti/p/example",
  "sort_order": 1,
  "is_active": true
}
```

### Store Info

- `GET /content/store-info`
- `PUT /content/store-info`

```json
{
  "business_hours": "每日 10:00 - 20:00"
}
```

### Articles CRUD

- `GET /content/articles`
- `POST /content/articles`
- `PUT /content/articles/{id}`
- `DELETE /content/articles/{id}`

POST/PUT body:

```json
{
  "title": "高齡犬照護分享",
  "excerpt": "文章摘要",
  "image_url": "/uploads/article.jpg",
  "published_at": "2026-03-09 23:56:29",
  "link": "",
  "is_active": true
}
```

### About Content

- `GET /content/about`
- `PUT /content/about`

```json
{
  "title": "恆寵愛 Perpetuity",
  "content": "品牌理念全文"
}
```

### About Modal Content

- `PUT /content/about/modals/{modal_key}`

可用 `modal_key`：
- `daycare_assessment`
- `hours`
- `manager`

```json
{
  "title": "店長",
  "content": "店長介紹內容",
  "image_url": "/uploads/manager.jpg"
}
```

## Upload API

### `POST /upload`

- Content-Type: `multipart/form-data`
- Form key: `file`
- Supported mime:
  - `image/jpeg`
  - `image/png`
  - `image/webp`
  - `image/gif`
  - `image/heic`
  - `image/heif`

Response:

```json
{
  "success": true,
  "data": {
    "file_path": "/uploads/20260301120000_abcdef123456.jpg",
    "mime_type": "image/jpeg"
  }
}
```

## HTTP Status

- `200`: success
- `201`: created
- `400`: invalid input / unsupported file type / file too large
- `401`: missing or invalid `X-API-Key`
- `404`: route not found
- `500`: server error

## Axios Example

### Public site

```ts
import axios from 'axios';

export const api = axios.create({
  baseURL: '/api',
});
```

### Admin write requests

```ts
import axios from 'axios';

export const adminApi = axios.create({
  baseURL: '/api',
  headers: {
    'X-API-Key': '<APP_API_KEY>',
  },
});
```

## Image URL Rules

- 若 API 回傳的是外部網址，前端可直接使用
- 若回傳的是 `/uploads/...`，同源部署時可直接組成：
  - `/uploads/filename.jpg`
