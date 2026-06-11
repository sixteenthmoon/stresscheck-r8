# stresscheck-r8

`stresscheck-r8` は、ストレスチェック Web サービス `stresscheck` の公開用スナップショットです。

この公開版では、OSS として見せるべき中核だけを残しています。

## 含めているもの

- `plugin/`
  - WordPress プラグイン本体
  - 管理画面、トークン管理、API、集計ロジックの骨格
- `frontend/`
  - 受検者向けの静的フロント
  - `index.html` / `check.html` / `result.html` / `config.json`
- 設計ドキュメント
  - `API_SPEC.md`
  - `DB_SCHEMA.md`
  - `SECURITY.md`
  - `SPECS.md`
  - `WIREFRAME.md`
- `README.md`
- `.github/pull_request_template.md`
- `.gitignore`

## 含めていないもの

- 内部向けデモ実装
- ローカル検証用の E2E / テストハーネス
- 実験的な運用ノートや下書きの実装計画
- `node_modules/` などの生成物

## 目的

このリポジトリは、以下を公開するためのものです。

- 個人情報を持たないストレスチェックの設計
- WordPress をバックエンドにした実装方針
- 受検者向け静的フロント
- 外部システム連携を含む API 契約
- セキュリティ設計とデータベース設計

## 使い方

### WordPress プラグイン

`plugin/` を WordPress の `wp-content/plugins/` 配下に配置し、`stress-check-service.php` を有効化します。

### 受検者向けフロント

`frontend/` は、管理画面で設定した公開ベースパスにそのまま同期する想定です。

- 初期ベースパス: `/st-check-r8`
- 配布対象: `index.html`, `check.html`, `result.html`, `config.json`

### 設計の要点

- サービス側では氏名・メールアドレス・社員番号・企業名・部署名を保存しない
- トークンは平文保存せず、ハッシュ化して扱う
- 受検者向け API はトークン認証を前提にする
- 管理系 / 外部連携系 API は Origin allowlist と API キーを併用する
- 結果画面は印刷 / PDF 保存に最適化する

## 関連ドキュメント

- `SECURITY.md`: セキュリティ方針
- `DB_SCHEMA.md`: カスタムテーブル設計
- `API_SPEC.md`: 外部向け API 契約
- `SPECS.md`: 機能要件
- `WIREFRAME.md`: 画面仕様

## ライセンス

この公開版のライセンスは、必要に応じて別途整備してください。
