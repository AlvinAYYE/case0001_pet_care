# 恆寵愛前端串接規格書（給後端）

本文件定義目前前端所需的兩個 API：

1. 最新消息 API（已串接）
2. 樂齡館內容 API（新需求，需後端實作）

## 1) 基本要求

- 回傳格式：`application/json; charset=utf-8`
- 編碼：UTF-8
- 方法：`GET`
- 錯誤時回傳 HTTP 4xx/5xx，body 仍建議為 JSON
- 欄位命名：使用 camelCase
- 若目前無資料：
  - 最新消息 API 可回傳空陣列 `[]`
  - 樂齡館 API 可回傳 404 或空物件 `{}`（前端會 fallback）

## 2) 最新消息 API

### Endpoint

- `GET /api/news.php`

### 回傳型別

陣列（最多不限，前端只取前 4 筆）：

```json
[
  {
    "id": 101,
    "title": "公告標題",
    "excerpt": "摘要內容",
    "publishedAt": "2026-03-01T08:00:00+08:00",
    "link": "https://example.com/news/101"
  }
]
```

### 欄位說明

- `id`: `number|string`，唯一值
- `title`: `string`，必填
- `excerpt`: `string`，建議必填（前端也接受 `summary` 或 `content`）
- `publishedAt`: `string`（ISO 8601 或 `YYYY-MM-DD`）
- `link`: `string`，可空字串

## 3) 樂齡館內容 API（新）

### Endpoint

- `GET /api/senior-care.php`

### 回傳型別

單一物件：

```json
{
  "title": "樂齡館",
  "subtitle": "高齡犬與特殊需求毛孩照護",
  "description": "完整文案...",
  "tags": "#安靜休養 #高齡犬照護",
  "imageUrl": "https://cdn.example.com/senior-cover.jpg",
  "imageUrl2": "https://cdn.example.com/senior-2.jpg",
  "imageUrl3": "https://cdn.example.com/senior-3.jpg",
  "imageUrl4": "https://cdn.example.com/senior-4.jpg"
}
```

### 欄位說明

- `title`: `string`，分區名稱
- `subtitle`: `string`，分區主標
- `description`: `string`，段落文案
- `tags`: `string`，自訂標籤文字（空格分隔等）
- `imageUrl`: `string`，完整可公開存取主圖 URL（建議 1800px+）
- `imageUrl2`: `string`，附加圖片 2 URL（可為空）
- `imageUrl3`: `string`，附加圖片 3 URL（可為空）
- `imageUrl4`: `string`，附加圖片 4 URL（可為空）

## 4) 錯誤回傳建議

### 範例（500）

```json
{
  "error": true,
  "message": "Database unavailable"
}
```

## 5) 快取與排序建議

- 最新消息：依 `publishedAt DESC`
- 建議可加 `Cache-Control: public, max-age=60`（可選）

## 6) 前端已接好的環境變數

- `VITE_NEWS_API_URL`（預設 `/api/news.php`）
- `VITE_SENIOR_API_URL`（預設 `/api/senior-care.php`）

## 7) 驗收條件

- `GET /api/news.php` 可正常回傳陣列 JSON
- `GET /api/senior-care.php` 可正常回傳單一物件 JSON
- 任一 API 暫時失敗時，前端畫面不會壞版，顯示 fallback 提示
