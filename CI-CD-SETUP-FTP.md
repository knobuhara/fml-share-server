# 🚀 Backend CI/CD セットアップガイド【PHP + FTP版】

## 概要

mainブランチへのpushで自動的にXserverにFTPでPHP APIをデプロイします。

---

## **【3ステップセットアップ】**

### **ステップ1️⃣：GitHub Secretsを設定**

以下のURLにアクセス：
```
https://github.com/knobuhara/fml-share-server/settings/secrets/actions
```

右上の「New repository secret」をクリックして、以下の3つを追加：

| 変数名 | 値 | 説明 |
|--------|-----|------|
| `XSERVER_HOST` | `sv161.xserver.jp` | Xserverのホスト名 |
| `XSERVER_FTP_USER` | `nobu@bears.xsrv.jp` | FTPユーザー名 |
| `XSERVER_FTP_PASSWORD` | `Xuan0911` | FTPパスワード |

**設定手順：**
1. GitHub Secrets and variables → Actions
2. 「New repository secret」をクリック
3. 以下の3つを順に追加：

```yaml
Name: XSERVER_HOST
Secret: sv161.xserver.jp
✅ Add secret

---

Name: XSERVER_FTP_USER
Secret: nobu@bears.xsrv.jp
✅ Add secret

---

Name: XSERVER_FTP_PASSWORD
Secret: Xuan0911
✅ Add secret
```

---

### **ステップ2️⃣：mainブランチにプッシュ**

```bash
# 現在の作業内容をコミット
git add .
git commit -m "feature: some API change"

# mainブランチへプッシュ
git push origin main
```

---

### **ステップ3️⃣：デプロイメント状態確認**

#### **GitHub Actionsで確認：**

1. GitHub リポジトリ → 「Actions」タブを開く
2. 左サイドバーから「Deploy Backend to Xserver (FTP)」を選択
3. 最新のワークフロー実行をクリック
4. 各ステップが ✅ で完了したら成功

#### **APIの疎通確認：**

```bash
# ローカルテスト（APIエンドポイント確認）
curl -H "Authorization: Bearer [JWT_TOKEN]" \
  https://bears.xsrv.jp/app/api/videos
```

---

## **📋 ワークフロー処理フロー**

```
mainブランチへのpush
        ↓
GitHub Actions トリガー
        ↓
【並行処理1：デプロイメント】
1. PHPコード検証
2. デプロイメントパッケージ作成
   ├─ /fml-share-api/（非公開）
   └─ /public_html/app/（公開）
3. FTPでXserverにアップロード
   ├─ /fml-share-api/ にAPI本体
   └─ /public_html/app/ にエントリーポイント
↓
【並行処理2：コード検査】
1. PHP Linter実行
2. 構文エラー検査

        ↓
✅ 完了 & APIで即座に反映
```

---

## **🔧 デプロイメント先の構成**

| パス | 用途 | 公開 |
|------|------|------|
| `/fml-share-api/` | PHP API本体 | ❌ 非公開 |
| `/public_html/app/api/` | エントリーポイント | ✅ 公開 |
| `/public_html/app/.htaccess` | リライトルール | ✅ 公開 |

### **実装フロー**

```
1. ユーザーがリクエスト
   https://bears.xsrv.jp/app/api/videos
        ↓
2. .htaccess がリライト
   /public_html/app/api/index.php
        ↓
3. index.php がAPI本体にルーティング
   /fml-share-api/public/index.php
        ↓
4. APIが処理を実行
```

---

## **🛡️ ワークフローに含まれる検査**

### **1️⃣ PHP構文チェック**

```bash
find . -name "*.php" -type f -exec php -l {} \;
```

- 全PHPファイルの構文エラーを検出
- ビルド失敗時はデプロイをブロック

### **2️⃣ コード検証**

```bash
php -d display_errors=1 -r "..."
```

- インクルード可能性をチェック
- 実行時エラーの事前検出

---

## **📝 .env ファイルの管理**

### **重要：.envはGitに含まないでください**

```bash
# .gitignore で除外済み
*.env
.env
.env.*
```

### **Xserver上での.env設定**

Xserver上に手動で `.env` を配置：

```
/fml-share-api/.env
```

**内容例：**
```
DB_HOST=127.0.0.1
DB_PORT=3307
DB_NAME=bears_fmlshare
DB_USER=bears_admin
DB_PASSWORD=Xuan0911
JWT_SECRET=...
```

**セットアップ方法：**
1. ローカルの `.env.xserver` をコピー
2. FTP で `fml-share-api/.env` にアップロード
3. ホスト側のファイアウォール設定確認

---

## **🔍 トラブルシューティング**

### ❌ FTP接続エラー

```
Error: 530 User cannot log in
```

**原因：** FTP認証情報が間違っている

**解決策：**
1. GitHub Secrets を確認
   - `XSERVER_FTP_USER`: `nobu@bears.xsrv.jp`
   - `XSERVER_FTP_PASSWORD`: 正確なパスワード

### ❌ PHP構文エラーでビルド失敗

```
Parse error: syntax error in api/index.php on line 10
```

**原因：** PHPファイルに構文エラーがある

**解決策：**
```bash
# ローカルで検証
php -l api/index.php

# エラー箇所を修正してコミット
git add .
git commit -m "fix: PHP syntax error"
git push origin main
```

### ❌ APIエンドポイントが404

```
404 Not Found: /app/api/videos
```

**原因：** `.htaccess` のリライトルール問題またはAPI本体のパス不一致

**解決策：**
1. `.htaccess` がデプロイされているか確認
2. FTP で `/public_html/app/` の内容を確認
3. API本体が `/fml-share-api/` に配置されているか確認

### ❌ データベース接続エラー

```
SQLSTATE[HY000]: General error: 1030 Got error 28
```

**原因：** `.env` ファイルが配置されていない、または認証情報が間違っている

**解決策：**
1. Xserver上で `.env` ファイルが存在するか確認
2. SSH または FTP で `/fml-share-api/.env` を確認
3. DB接続情報を再度確認

---

## **💡 データベース接続（SSH トンネル）**

ローカル開発環境での接続：

```bash
# PuTTY でSSHトンネルを起動
# SSH ホスト: sv161.xserver.jp
# ユーザー: bears
# Source port: 3307
# Destination: localhost:3306

# その後、.env.xserver をコピー
cp .env.xserver .env

# ローカルAPIサーバー起動
php -S 127.0.0.1:3001 -t public public/index.php
```

---

## **📚 ファイル構成**

```
fml-share-server/
├── .github/workflows/
│   └── deploy-xserver.yml          ← 本ワークフロー
├── api/                            ← API実装
├── config/                         ← 設定ファイル
├── database/                       ← DBスキーマ
├── lib/                            ← ライブラリ
├── public/                         ← 公開ドキュメントルート
├── deploy/xserver/
│   └── public_html/app/            ← Xserver上のエントリーポイント
├── .env.example                    ← 環境変数テンプレート
├── .env.xserver                    ← Xserver設定（本番）
└── .env.local                      ← ローカル設定
```

---

## **🎯 フロントエンド側との連携**

| システム | リポジトリ | ワークフロー | デプロイ先 |
|---------|-----------|-----------|---------|
| フロントエンド | `fml-share` | `deploy-xserver.yml` | `/public_html/app/` |
| バックエンド | `fml-share-server` | `deploy-xserver.yml` | `/fml-share-api/` + `/public_html/app/api/` |

**パイプライン全体：**

```
フロントエンド側 (Vue.js)
  ↓
  GitHub Actions
  ↓
  FTP で /public_html/app/ にデプロイ
  ↓
  API呼び出し → /app/api/...
  ↓
  .htaccess がリライト
  ↓
バックエンド側 (PHP API)
  ↓
  GitHub Actions
  ↓
  FTP で /fml-share-api/ にデプロイ
  ↓
  API処理実行
```

---

## **参考資料**

- [GitHub Actions公式ドキュメント](https://docs.github.com/en/actions)
- [FTP-Deploy-Actionの使用方法](https://github.com/SamKirkland/FTP-Deploy-Action)
- [PHPの構文チェック](https://www.php.net/manual/ja/features.commandline.usage.php)
