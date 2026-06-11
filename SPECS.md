# SPECS: stresscheck-r8

## 1. 目的
本仕様書は、厚生労働省の職業性ストレス簡易調査票（57項目）を用いたストレスチェック Web サービスについて、実装に着手できる水準まで機能要件・非機能要件・データベース・API・判定ロジックを定義するものである。

## 2. 前提条件

### 2.1 法制度上の前提
- 改正労働安全衛生法により、50人未満の事業場にもストレスチェック実施義務が拡大される。
- 労働基準監督署への報告義務については、現時点の厚生労働省資料では50人以上の事業場が対象であり、50人未満は不要とされている。
- 本サービスでは、提出義務の有無にかかわらず、実施記録・報告サマリを自動集計できるようにする。

### 2.2 サービス設計上の前提
- 複数企業から利用される。
- ただし、サービス側にはテナント・企業・部署・拠点・氏名・メールアドレス・group_code を持たない。
- アクセスしてきたユーザーがどの企業に属するかは関知しない。
- 受検者は一意の URL / トークンで識別する。
- 個人情報とトークンの対応表は外部で管理する。
- WordPress は API バックエンドとして利用する。
- フロントエンドは WordPress UI に依存しない疎結合構成を推奨する。

## 3. ロール

| ロール | 説明 | 主な権限 |
|---|---|---|
| 受検者 | ユニーク URL でアクセスする従業員 | 回答、個人結果閲覧、PDF保存 |
| 管理者 | WordPress 管理画面を利用する運用担当者 | トークン発行、進捗確認、統計閲覧、設定変更 |
| システム管理者 | WordPress/サーバー管理者 | プラグイン導入、DB保守、ログ確認 |

## 4. 機能要件

### 4.1 質問票管理
| ID | 要件 | 優先度 |
|---|---|---|
| F-Q-01 | 厚労省57項目を初期データとして投入する | Must |
| F-Q-02 | 質問は A/B/C/D の領域コードを持つ | Must |
| F-Q-03 | 逆転項目は `is_reverse_scoring` フラグで管理する | Must |
| F-Q-04 | 質問文は日本語を必須、将来拡張として英語等に対応可能なカラムを持つ | Should |
| F-Q-05 | 管理画面から質問文を編集可能にする | Should |

### 4.1 トークン管理
| ID | 要件 | 優先度 |
|---|---|---|
| F-T-01 | 管理者は1回最大500件のトークンを一括発行できる | Must |
| F-T-02 | トークンは平文保存せず、ハッシュ化して保存する | Must |
| F-T-03 | トークンには有効期限を設定できる | Must |
| F-T-04 | トークン状態は `issued`, `started`, `submitted`, `expired`, `revoked`, `reissued` のいずれかとする | Must |
| F-T-05 | 期限切れトークンは自動失効する | Must |
| F-T-06 | 期限切れ・失効トークンは再発行できる | Must |
| F-T-07 | 再発行時は旧トークンを `reissued` とし、新トークンを発行する | Must |
| F-T-08 | トークンCSVには token_url, expires_at, token_prefix, target_year を含める。group_code は含めない | Must |

### 4.3 受検・回答
| ID | 要件 | 優先度 |
|---|---|---|
| F-R-01 | 受検者はユニーク URL から説明ページへアクセスする | Must |
| F-R-02 | 同意チェック後に質問へ進める | Must |
| F-R-03 | 1画面3問ずつのステップ形式で回答でき、質問順は表示時にランダムにする | Must |
| F-R-04 | 未回答項目がある場合は送信不可 | Must |
| F-R-05 | 回答値は1〜4の整数のみ許可し、画面上は 1 = そう思わない / 2 = あまりそう思わない / 3 = ややそう思う / 4 = そう思う と表示する | Must |
| F-R-06 | 送信後は同じトークンで再回答不可とする | Must |
| F-R-07 | 送信後に即時で個人結果を表示する | Must |
| F-R-08 | 結果画面は印刷・PDF保存に適したCSSを持つ | Must |
| F-R-09 | 結果画面には「具体的なセルフケア情報・相談窓口は勤務先にお問い合わせください」と表示する | Must |
| F-R-10 | 面接指導希望の有無を受検者が選択でき、その希望フラグをサービス内に保存する | Must |

### 4.4 判定・スコアリング
| ID | 要件 | 優先度 |
|---|---|---|
| F-S-01 | 回答送信時に通常項目・逆転項目を考慮したスコアを算出する | Must |
| F-S-02 | 素点合計法で高ストレス判定を行う | Must |
| F-S-03 | 尺度別5段階評価法に拡張できる設計にする | Should |
| F-S-04 | 判定閾値は管理画面から変更可能にする | Should |
| F-S-05 | 計算結果は `wp_sc_results` に保存する | Must |

### 4.5 管理画面
| ID | 要件 | 優先度 |
|---|---|---|
| F-A-01 | トークン発行画面を提供する | Must |
| F-A-02 | トークン一覧・状態・期限をページング付きで表示する | Must |
| F-A-03 | 回答率をプログレスバーで表示する | Must |
| F-A-04 | 未回答者リマインド用に未回答トークン一覧CSVを出力する | Should |
| F-A-05 | 行政報告サマリを表示する | Must |
| F-A-06 | 行政報告サマリCSVを出力する | Should |
| F-A-07 | group_code 別、部署別、拠点別、企業別の集計は初期実装では行わない | Must |
| F-A-08 | 受検者向け API の CORS 方針と、管理/API 連携の許可ドメインを設定する | Must |
| F-A-09 | レートリミット設定を表示・変更できる | Should |

### 4.6 行政報告サマリ
以下の値を年度単位で自動集計する。

| 項目 | 説明 |
|---|---|
| target_year | 対象年 |
| period_start | 実施期間開始日 |
| period_end | 実施期間終了日 |
| issued_token_count | 発行トークン数 |
| submitted_count | 回答済み数 |
| completion_rate | 受検率 |
| high_stress_count | 高ストレス判定者数 |
| high_stress_rate | 高ストレス判定者割合 |
| interview_requested_count | 面接指導希望者数 |
| report_generated_at | サマリ生成日時 |

注: 50人未満事業場の労基署報告提出要否は現時点では不要とされているため、画面上では「提出要否は事業場規模・最新法令を確認」と注意表示する。

## 5. データベース設計

### 5.1 wp_sc_questions
57項目の質問マスタ。

| カラム | 型 | NULL | 説明 |
|---|---|---|---|
| id | BIGINT UNSIGNED PK | NO | 内部ID |
| question_no | TINYINT UNSIGNED | NO | 1〜57 |
| section_code | CHAR(1) | NO | A/B/C/D |
| scale_code | VARCHAR(50) | YES | 尺度コード |
| question_text_ja | TEXT | NO | 日本語質問文 |
| question_text_en | TEXT | YES | 英語質問文。将来用 |
| option_set_code | VARCHAR(50) | NO | 選択肢セット |
| is_reverse_scoring | TINYINT(1) | NO | 逆転項目フラグ |
| display_order | TINYINT UNSIGNED | NO | 表示順 |
| is_active | TINYINT(1) | NO | 有効/無効 |
| created_at | DATETIME | NO | 作成日時 |
| updated_at | DATETIME | NO | 更新日時 |

インデックス: `UNIQUE(question_no)`, `INDEX(section_code)`, `INDEX(is_active)`

### 5.2 wp_sc_tokens
ユニーク URL 用トークン管理。

| カラム | 型 | NULL | 説明 |
|---|---|---|---|
| id | BIGINT UNSIGNED PK | NO | 内部ID |
| token_hash | CHAR(64) | NO | SHA-256等でハッシュ化したトークン |
| token_prefix | VARCHAR(12) | YES | 管理画面表示用の先頭数文字 |
| status | VARCHAR(20) | NO | issued/started/submitted/expired/revoked/reissued |
| target_year | SMALLINT UNSIGNED | NO | 実施年度 |

| issued_at | DATETIME | NO | 発行日時 |
| started_at | DATETIME | YES | 回答開始日時 |
| submitted_at | DATETIME | YES | 回答完了日時 |
| expires_at | DATETIME | NO | 有効期限 |
| reissued_from_id | BIGINT UNSIGNED | YES | 再発行元トークンID |
| created_at | DATETIME | NO | 作成日時 |
| updated_at | DATETIME | NO | 更新日時 |

インデックス: `UNIQUE(token_hash)`, `INDEX(status)`, `INDEX(target_year)`, `INDEX(expires_at)`

### 5.3 wp_sc_responses
回答生データ。

| カラム | 型 | NULL | 説明 |
|---|---|---|---|
| id | BIGINT UNSIGNED PK | NO | 内部ID |
| token_id | BIGINT UNSIGNED | NO | wp_sc_tokens.id |
| answers_json | JSON / LONGTEXT | NO | `{question_no: answer_value}` |
| user_agent_hash | CHAR(64) | YES | 任意。個人特定しない形で重複検知用 |
| ip_hash | CHAR(64) | YES | 任意。レート制御・監査用。IPそのものは保存しない |
| submitted_at | DATETIME | NO | 送信日時 |
| created_at | DATETIME | NO | 作成日時 |

インデックス: `UNIQUE(token_id)`, `INDEX(submitted_at)`

### 5.4 wp_sc_results
計算済み結果。集団分析高速化のため正規化して保存する。

| カラム | 型 | NULL | 説明 |
|---|---|---|---|
| id | BIGINT UNSIGNED PK | NO | 内部ID |
| token_id | BIGINT UNSIGNED | NO | wp_sc_tokens.id |
| target_year | SMALLINT UNSIGNED | NO | 実施年度 |

| score_a | SMALLINT | NO | A領域スコア |
| score_b | SMALLINT | NO | B領域スコア |
| score_c | SMALLINT | NO | C領域スコア |
| score_d | SMALLINT | NO | D領域スコア |
| score_ac | SMALLINT | NO | A+C合計 |
| high_stress_flag | TINYINT(1) | NO | 高ストレス判定 |
| high_stress_reason | VARCHAR(50) | YES | B77 / AC76_B63 等 |
| interview_requested | TINYINT(1) | YES | 面接指導希望 |
| calculated_at | DATETIME | NO | 計算日時 |
| created_at | DATETIME | NO | 作成日時 |

インデックス: `UNIQUE(token_id)`, `INDEX(target_year)`, `INDEX(high_stress_flag)`, `INDEX(interview_requested)`

## 6. 判定ロジック

### 6.1 回答値
回答値は1〜4とする。

### 6.2 逆転項目
以下の項目は `is_reverse_scoring = 1` とする。

- A領域: 1, 2, 3, 8, 9
- B領域: 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46

### 6.3 スコア変換
通常項目:

```text
score = answer_value
```

逆転項目:

```text
score = 5 - answer_value
```

これにより、計算用スコアは「値が高いほどストレスが高い」方向に統一する。

### 6.4 素点合計法
初期実装では素点合計法を Must とする。

```text
score_b = B領域29項目の合計
score_ac = A領域17項目 + C領域9項目の合計

if score_b >= 77:
    high_stress = true
    reason = 'B77'
elif score_ac >= 76 and score_b >= 63:
    high_stress = true
    reason = 'AC76_B63'
else:
    high_stress = false
    reason = null
```

### 6.5 尺度別5段階評価法
V3では拡張可能な設計に留め、初期実装では任意とする。ただし、`scale_code` と `is_reverse_scoring` を質問マスタに持たせ、将来的に素点換算表による5段階換算を追加できる構造とする。

## 7. API仕様

### 7.1 共通仕様
- Base path: `/wp-json/sc/v1`
- JSON API とする。
- 受検者向け API はトークンで認可する。
- 管理者向け API は WordPress ログイン + capability で認可する。
- 外部事業者向け API は `/external/test-executions...` 配下で提供し、`Authorization: Bearer <api_key>` または `X-SC-API-Key: <api_key>` で認証する。
- 外部事業者向けの公開契約は `API_SPEC.md` を正とする。DB内部ID、ハッシュ値、内部設定値は外部レスポンスに含めない。
- 受検者向け API は Origin で認可せずトークンで保護し、管理者向け API / 外部連携 API は許可 Origin のみ受け付ける。
- トークン照会系 API にはレートリミットを適用する。

### 7.2 受検者向けAPI

| Method | Path | 説明 | 認証 |
|---|---|---|---|
| GET | `/survey/{token}` | 質問票・トークン状態取得 | token |
| POST | `/survey/{token}/start` | 回答開始記録 | token |
| POST | `/survey/{token}/submit` | 回答送信 | token |
| GET | `/result/{token}` | 個人結果取得 | token |
| POST | `/result/{token}/interview-request` | 面接指導希望登録 | token |

### 7.3 管理者向けAPI

| Method | Path | 説明 | 認証 |
|---|---|---|---|
| POST | `/admin/test-executions` | テスト実施コード作成 | WP admin |
| GET | `/admin/test-executions` | テスト実施一覧 | WP admin |
| GET | `/admin/test-executions/{execution_code}` | テスト実施詳細 | WP admin |
| POST | `/admin/test-executions/{execution_code}/tokens` | 実施内の個別トークン一括発行 | WP admin |
| GET | `/admin/test-executions/{execution_code}/tokens` | 実施内の個別トークン一覧 | WP admin |
| GET | `/admin/test-executions/{execution_code}/progress` | 実施単位の進捗取得 | WP admin |
| GET | `/admin/test-executions/{execution_code}/results` | 実施単位の結果一覧 | WP admin |
| GET | `/admin/test-executions/{execution_code}/high-stress` | 高ストレス者トークン一覧 | WP admin |
| POST | `/admin/test-executions/{execution_code}/close` | 実施締切 | WP admin |
| GET | `/admin/progress` | 回答進捗取得 | WP admin |
| GET | `/admin/report/summary` | 行政報告サマリ取得 | WP admin |
| GET | `/admin/report/export` | CSV出力 | WP admin |
| GET | `/admin/settings` | 設定取得 | WP admin |
| POST | `/admin/settings` | 設定保存 | WP admin |

### 7.4 回答送信リクエスト例
```json
{
  "answers": {
    "1": 3,
    "2": 4,
    "3": 3,
    "57": 2
  },
  "interview_requested": false
}
```

### 7.5 回答送信レスポンス例
```json
{
  "status": "submitted",
  "result_url": "https://example.com/result.html?t=xxxx",
  "high_stress_flag": false
}
```

## 8. セキュリティ要件

### 8.1 トークン
- トークンは128bit以上のエントロピーを持つ乱数とする。
- DBには平文トークンを保存しない。
- トークンのハッシュは SHA-256 以上を用いる。
- 管理画面には token_prefix のみ表示する。
- URLに含まれるトークンはログ出力時にマスクする。

### 8.2 レートリミット
以下をデフォルト値とする。

| 対象 | 制限 |
|---|---|
| `/survey/{token}` | IP単位 1分30回 |
| `/result/{token}` | IP単位 1分20回 |
| `/submit` | token単位 1回、IP単位 1分10回 |
| 存在しないトークンへのアクセス | IP単位 10分20回で一時ブロック |

### 8.3 CORS
- 管理画面で許可ドメインを設定する。
- 管理画面の POST 操作は capability と nonce で保護する。
- `Access-Control-Allow-Origin: *` は禁止。
- 本番環境では `localhost` を許可しない。
- `OPTIONS` プリフライトに対応する。

### 8.4 入力検証
- answers は57項目すべてを含む必要がある。
- 各回答は整数 1〜4 のみ許可。
- token は正規表現で形式チェックする。
- group_code、部署コード、拠点コード、企業コードは初期実装では受け付けない。

### 8.5 ログ
- IPアドレス・User-Agentは原則ハッシュ化して保存する。
- 平文トークンをログに出さない。
- 回答本文をアプリケーションログに出さない。

## 9. 非機能要件

| 項目 | 要件 |
|---|---|
| 性能 | 通常時 API レスポンス 1秒以内を目標 |
| 可用性 | WordPress 標準運用に準拠し、バックアップ可能 |
| 保守性 | スコアリングロジックはサービスクラスに分離 |
| アクセシビリティ | WCAG 2.1 AA を目標 |
| スマホ対応 | タップ領域44px以上、レスポンシブ対応 |
| 多言語 | DB上は多言語カラムを持ち、UI文言は i18n 化可能にする |

## 10. 実装順序
1. プラグイン骨格作成
2. DB作成・初期質問投入
3. トークン発行機能
4. 受検者向け質問API
5. 回答送信API
6. スコアリング実装
7. 結果表示API
8. フロントエンド実装
9. 管理画面進捗表示
10. 行政報告サマリ
11. セキュリティ強化
12. テスト・受入確認

## 11. 未確定事項
主要論点は確定済み。

1. 面接指導希望情報は保存する。
2. group_code は初期実装に含めない。
3. 個人結果・回答生データ・統計結果は無期限保存する。
4. PDFはブラウザ印刷CSSのみで対応する。
5. 相談窓口・セルフケアリンクは外部サービス側が管理し、本サービス側は勤務先問い合わせ文言のみ表示する。


## 15. v0.1.0 で確定した仕様

### 15.1 面接指導希望フラグ
受検者が「面接指導を希望する」を押した場合、`wp_sc_results.interview_requested` と `interview_requested_at` を更新する。これは本サービス内で保存する。

### 15.2 group_code を持たない
初期実装では、部署別・拠点別・企業別の進捗可視化や集計のための `group_code` を保存しない。受検 URL と従業員・部署・拠点の対応関係は外部サービス側で管理する。

### 15.3 保存期間
回答生データ、個人結果、統計結果は無期限保持する。バックアップ・アーカイブ・削除機能は将来拡張とする。

### 15.4 PDF
PDF保存はブラウザ印刷 / PDF保存に最適化した CSS で実装する。サーバー側 PDF 生成は初期実装対象外とする。

### 15.5 セルフケア・相談窓口
セルフケア動画や相談窓口リンクの具体内容は外部サービス側で管理する。本サービスの結果画面では「具体的なセルフケア情報や相談窓口は、勤務先にお問い合わせください」と表示する。


## MVP用トークンAPIの廃止

テスト実施コード導入後、個別トークンは必ずテスト実施に属するため、既存MVP用の `/admin/tokens` 系APIは廃止し、`/admin/test-executions/{execution_code}/tokens` へ統一する。
