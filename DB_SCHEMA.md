# DB_SCHEMA: stresscheck-r8

## 1. 方針
WordPress 標準テーブルには回答データを保存せず、パフォーマンスと保守性を考慮して専用カスタムテーブルを利用する。

- `wp_sc_questions`: 質問マスタ
- `wp_sc_test_executions`: テスト実施コード（実施単位）
- `wp_sc_tokens`: ユニークURL/個別トークン（受検単位）
- `wp_sc_responses`: 回答生データ
- `wp_sc_results`: 計算済み結果

本サービス側では、企業名、部署名、拠点名、社員番号、メールアドレス、group_code を保存しない。どの部署の誰がどの URL で受検したかは外部サービス側が管理する。外部照合キー（`external_subject_key` 等）も本サービス側には保存しない。

WordPress の `$wpdb->prefix` を使用し、実際のテーブル名はインストール環境に応じて `{prefix}_sc_questions` のように生成する。

## 2. DDL案

```sql
CREATE TABLE {prefix}_sc_questions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_no TINYINT UNSIGNED NOT NULL,
  section_code CHAR(1) NOT NULL,
  scale_code VARCHAR(50) NULL,
  question_text_ja TEXT NOT NULL,
  question_text_en TEXT NULL,
  option_set_code VARCHAR(50) NOT NULL DEFAULT 'default_4',
  is_reverse_scoring TINYINT(1) NOT NULL DEFAULT 0,
  display_order TINYINT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_question_no (question_no),
  KEY idx_section_code (section_code),
  KEY idx_is_active (is_active)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {prefix}_sc_test_executions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  execution_code_hash CHAR(64) NOT NULL,
  execution_code_prefix VARCHAR(16) NULL,
  title VARCHAR(255) NOT NULL,
  delivery_date DATE NULL,
  answer_deadline DATETIME NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  target_year SMALLINT UNSIGNED NOT NULL,
  issued_token_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_execution_code_hash (execution_code_hash),
  KEY idx_status (status),
  KEY idx_target_year (target_year),
  KEY idx_answer_deadline (answer_deadline)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {prefix}_sc_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  test_execution_id BIGINT UNSIGNED NULL,
  token_hash CHAR(64) NOT NULL,
  token_prefix VARCHAR(12) NULL,
  token_issue_seq INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'issued',
  target_year SMALLINT UNSIGNED NOT NULL,
  issued_at DATETIME NOT NULL,
  started_at DATETIME NULL,
  submitted_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  reissued_from_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token_hash (token_hash),
  KEY idx_test_execution_id (test_execution_id),
  KEY idx_execution_status (test_execution_id, status),
  UNIQUE KEY uq_execution_seq (test_execution_id, token_issue_seq),
  KEY idx_status (status),
  KEY idx_target_year (target_year),
  KEY idx_expires_at (expires_at)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {prefix}_sc_responses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_id BIGINT UNSIGNED NOT NULL,
  answers_json LONGTEXT NOT NULL,
  user_agent_hash CHAR(64) NULL,
  ip_hash CHAR(64) NULL,
  submitted_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token_id (token_id),
  KEY idx_submitted_at (submitted_at)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE {prefix}_sc_results (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_id BIGINT UNSIGNED NOT NULL,
  target_year SMALLINT UNSIGNED NOT NULL,
  score_a SMALLINT NOT NULL,
  score_b SMALLINT NOT NULL,
  score_c SMALLINT NOT NULL,
  score_d SMALLINT NOT NULL,
  score_ac SMALLINT NOT NULL,
  high_stress_flag TINYINT(1) NOT NULL DEFAULT 0,
  high_stress_reason VARCHAR(50) NULL,
  interview_requested TINYINT(1) NOT NULL DEFAULT 0,
  interview_requested_at DATETIME NULL,
  calculated_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_result_token_id (token_id),
  KEY idx_target_year (target_year),
  KEY idx_high_stress_flag (high_stress_flag),
  KEY idx_interview_requested (interview_requested)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 3. 初期質問データ仕様
質問データはプラグイン有効化時に投入する。最低限以下のフィールドを持つ。

```php
[
  'question_no' => 18,
  'section_code' => 'B',
  'scale_code' => 'vigor',
  'question_text_ja' => '活気がわいてくる',
  'option_set_code' => 'frequency_4',
  'is_reverse_scoring' => 1,
  'display_order' => 18,
]
```

## 4. テスト実施コード・トークン保存方針
- テスト実施作成時に平文の `test_execution_code` を生成する。
- 平文の `test_execution_code` は作成レスポンスで一度だけ返し、DBには `execution_code_hash` と `execution_code_prefix` のみ保存する。
- 個別トークンはテスト実施コード配下で件数指定により発行し、`test_execution_id` と `token_issue_seq` で実施内の発行順を保持する。
- 発行時に平文トークンを生成する。
- 平文トークンは発行レスポンスと CSV にのみ含める。
- DB には `hash_hmac('sha256', token, AUTH_SALT)` 等でハッシュ化して保存する。
- 管理画面には `token_prefix` のみ表示する。
- トークンと個人情報の対応表は外部サービス側で管理する。
- 回答締切日時（`answer_deadline`）と個別トークンの `expires_at` は同期する。

## 5. データ保持
初期実装では、回答生データ、個人結果、統計結果を無期限保持する。

将来拡張として以下の運用機能を追加できる設計にしておく。

- 手動バックアップ
- 年度単位のアーカイブ
- 管理画面からのエクスポート
- 将来的な削除・匿名化バッチ

## 6. 明示的に保存しないカラム
以下のカラムは初期実装では作成しない。

- company_id / tenant_id
- company_name
- department_name
- office_name
- employee_id
- email
- group_code
- external_subject_key / 外部照合キー
