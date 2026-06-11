# 外部向けAPI仕様書: stresscheck-r8

## 1. 目的

この仕様書は、外部事業者のシステムがストレスチェック Web Service と連携するための外部向けAPI契約です。

前提:
- 本サービスは氏名、メールアドレス、社員番号などの個人情報を保持しません。
- 外部事業者は「受検者 ↔ plain_token」の対応表を自社側で管理します。
- `plain_token` はトークン発行時に一度だけ返却されます。
- トークンの再取得APIは提供しません。Token管理は外部事業者の責任です。
- レスポンスにDB内部ID、ハッシュ値、内部設定値は含めません。

## 2. ベースURL

```text
/wp-json/sc/v1
```

## 3. 認証

### 3.1 外部連携API

外部連携APIはAPIキー認証です。次のいずれかで送信します。

```http
Authorization: Bearer YOUR_API_KEY
```

または

```http
X-SC-API-Key: {api_key}
```

ブラウザから `X-SC-API-Key` を送る場合の preflight では、`Access-Control-Allow-Headers` に `X-SC-API-Key` が含まれます。

認証に失敗した場合:

```json
{
  "ok": false,
  "error": {
    "code": "EXTERNAL_AUTH_REQUIRED",
    "message": "外部連携APIの認証情報が必要です。",
    "status": 401
  }
}
```

### 3.2 受検者向けAPI

受検者向けAPIはURL内の `token` により認証します。Originは認可に使いません。

### 3.3 日時の扱い

- リクエストの `answer_deadline` は `YYYY-MM-DD HH:mm:ss` または ISO 8601（例: `2031-05-31T23:59:59+09:00`）を受け付けます。
- timezone offset 付きの日時は、offset を保持して解釈したうえで UTC に正規化されます。
- レスポンスの実施単位日時（例: `answer_deadline`）と発行時の `expires_at` は ISO 8601 UTC（`+00:00`）です。
- 進捗APIの `tokens[].issued_at` / `started_at` / `submitted_at` / `expires_at` と結果取得APIの `results[].submitted_at` は、現行実装では保存済み日時文字列（`YYYY-MM-DD HH:mm:ss`）です。

## 4. 共通レスポンス

成功:

```json
{
  "ok": true,
  "data": {}
}
```

エラー:

```json
{
  "ok": false,
  "error": {
    "code": "INVALID_TOKEN",
    "message": "このURLは無効です。管理者にお問い合わせください。",
    "status": 404
  }
}
```

## 5. 外部事業者フロー

### A. テスト実施を作成する

`POST /external/test-executions`

リクエスト:

```json
{
  "title": "2031年 ストレスチェック",
  "delivery_date": "2031-05-01",
  "answer_deadline": "2031-05-31T23:59:59+09:00",
  "target_year": 2031
}
```

レスポンス:

```json
{
  "ok": true,
  "data": {
    "test_execution_code": "te_xxxxx",
    "title": "2031年 ストレスチェック",
    "delivery_date": "2031-05-01",
    "answer_deadline": "2031-05-31T14:59:59+00:00",
    "status": "draft",
    "target_year": 2031,
    "issued_token_count": 0
  }
}
```

### B. N件分のトークンを発行する

`POST /external/test-executions/{test_execution_code}/tokens`

リクエスト:

```json
{
  "count": 100,
  "base_url": "https://vendor.example/stresscheck/start"
}
```

レスポンス:

```json
{
  "ok": true,
  "data": {
    "test_execution_code": "te_xxxxx",
    "issued_count": 100,
    "tokens": [
      {
        "token_issue_seq": 1,
        "plain_token": "abc123...",
        "token_prefix": "abc123",
        "token_url": "https://vendor.example/stresscheck/start?t=abc123...",
        "status": "issued",
        "expires_at": "2031-05-31T14:59:59+00:00"
      }
    ]
  }
}
```

重要:
- `plain_token` はこのレスポンスでのみ返却されます。
- 外部事業者はこの時点で `token_issue_seq`、`plain_token`、受検者情報の対応表を保存してください。
- `GET /external/test-executions/{code}/tokens` は提供しません。

### C. 進捗を確認する

`GET /external/test-executions/{test_execution_code}/progress`

レスポンス:

```json
{
  "ok": true,
  "data": {
    "test_execution_code": "te_xxxxx",
    "title": "2031年 ストレスチェック",
    "status": "active",
    "delivery_date": "2031-05-01",
    "answer_deadline": "2031-05-31T14:59:59+00:00",
    "issued_count": 100,
    "not_started_count": 70,
    "started_count": 20,
    "submitted_count": 10,
    "expired_count": 0,
    "completion_rate": 10.0,
    "tokens": [
      {
        "token_issue_seq": 1,
        "token_prefix": "abc123",
        "status": "submitted",
        "issued_at": "2031-05-01 00:00:00",
        "started_at": "2031-05-02 01:00:00",
        "submitted_at": "2031-05-02 01:15:00",
        "expires_at": "2031-05-31 14:59:59",
        "high_stress_flag": true,
        "interview_requested": true
      }
    ]
  }
}
```

注意:
- 進捗APIでは `plain_token` を返しません。
- 外部事業者は `token_issue_seq` または発行時に保存した対応表で自社側の受検者と照合します。

### D. 回答結果を取得する

`GET /external/test-executions/{test_execution_code}/results`

クエリ:
- `page`: 1以上。省略時 `1`
- `per_page`: 1以上。省略時は全件（`total_count`）
- `high_stress`: `1` の場合、高ストレス者のみ

レスポンス:

```json
{
  "ok": true,
  "data": {
    "page": 1,
    "per_page": 100,
    "total_count": 1,
    "results": [
      {
        "token_issue_seq": 1,
        "token_prefix": "abc123",
        "submitted_at": "2031-05-02 01:15:00",
        "score_a": 42,
        "score_b": 62,
        "score_c": 18,
        "score_d": 4,
        "score_ac": 60,
        "high_stress_flag": true,
        "high_stress_reason": "score_threshold",
        "interview_requested": true
      }
    ]
  }
}
```

### E. 高ストレス者・面談対象者を確認する

高ストレス者:

`GET /external/test-executions/{test_execution_code}/high-stress`

面談希望者は、結果取得APIの各行に含まれる `interview_requested` または summary の `interview_requested_count` で確認します。

現時点では、外部向けの面談希望者専用絞り込みクエリは提供しません。

### F. 実施サマリーを取得する

`GET /external/test-executions/{test_execution_code}/summary`

レスポンス:

```json
{
  "ok": true,
  "data": {
    "test_execution_code": "te_xxxxx",
    "title": "2031年 ストレスチェック",
    "status": "active",
    "delivery_date": "2031-05-01",
    "answer_deadline": "2031-05-31T14:59:59+00:00",
    "issued_count": 100,
    "submitted_count": 80,
    "completion_rate": 80.0,
    "high_stress_count": 8,
    "high_stress_rate": 10.0,
    "interview_requested_count": 3
  }
}
```

### G. 締切後に実施を閉じる

`POST /external/test-executions/{test_execution_code}/close`

レスポンス:

```json
{
  "ok": true,
  "data": {
    "status": "closed",
    "test_execution_code": "te_xxxxx"
  }
}
```

## 6. 受検者向けAPI

外部事業者が配布する受検URLから呼び出されるAPIです。

### 6.1 質問票取得

`GET /survey/{token}`

レスポンス:

```json
{
  "ok": true,
  "data": {
    "status": "issued",
    "expires_at": "2031-05-31T14:59:59+00:00",
    "questions": [
      {
        "question_no": 1,
        "section_code": "A",
        "text": "非常にたくさんの仕事をしなければならない",
        "options": [1, 2, 3, 4]
      }
    ]
  }
}
```

### 6.2 回答開始

`POST /survey/{token}/start`

レスポンス:

```json
{
  "ok": true,
  "data": {
    "status": "started",
    "expires_at": "2031-05-31T14:59:59+00:00"
  }
}
```

### 6.3 回答送信

`POST /survey/{token}/submit`

リクエスト:

```json
{
  "answers": {
    "1": 3,
    "2": 4,
    "57": 2
  },
  "interview_requested": false
}
```

必須条件:
- 57問すべてに回答すること
- 回答値は `1`, `2`, `3`, `4` のいずれか

レスポンス:

```json
{
  "ok": true,
  "data": {
    "status": "submitted",
    "submitted_at": "2031-05-02T01:15:00+00:00",
    "result_url": "https://example.com/result/abc123..."
  }
}
```

### 6.4 結果表示

`GET /result/{token}`

### 6.5 面談希望登録

`POST /result/{token}/interview-request`

## 7. 外部に公開しないもの

次は外部API仕様には含めません。

- WordPress管理画面用API: `/admin/*`
- 設定API: `/admin/settings`
- トークン一覧再取得API: `GET /external/test-executions/{code}/tokens`
- DB内部ID: `id`, `test_execution_id`
- ハッシュ値: `token_hash`
- 内部用prefix: `execution_code_prefix`
- WordPress Nonce / Capability の詳細

## 8. ステータス

テスト実施ステータス:
- `draft`: 作成済み
- `active`: 実施中
- `closed`: 締切後
- `aggregated`: 集計済み

トークンステータス:
- `issued`: 発行済み、未開始
- `started`: 回答開始済み
- `submitted`: 回答送信済み
- `expired`: 期限切れ
- `revoked`: 無効化済み
- `reissued`: 再発行済み
