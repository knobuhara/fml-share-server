# FML Share — バックエンド

動画一覧・ダウンロード用のPHP APIです。フロントエンドは [fml-share](https://github.com/knobuhara/fml-share) を使用します。

1. PHP 8.x と `pdo_mysql`、MySQLを用意します。FTPSを利用する場合はPHPの `curl` 拡張も必要です。
2. MySQLでデータベースを作成し、[database/schema.sql](./database/schema.sql) を適用します。初期ユーザーや動画データは含まれません。
3. `.env.example` を `.env` にコピーして、接続情報と動画フォルダを設定します。`JWT_SECRET` は `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` で新規生成します。
4. ローカルでは `php -S 127.0.0.1:3001 -t public public/index.php` でAPIを起動します。

ローカルの動画フォルダを読む場合は `XSERVER_VIDEO_LOCAL_PATH` にその実パスを設定します。対象動画をダウンロードするには、`videos.file_path` に `XSERVER_VIDEO_PATH` とファイル名をつないだパスを登録します。

動画の再生は署名付きAPI URLを使い、APIサーバーがFTPSから動画を中継します。ブラウザーからFTPSや動画フォルダへ直接アクセスする必要はありません。公開環境ではAPIをHTTPSで公開してください。

Xserverでは、API本体と `.env` を `public_html` 外の `fml-share-api` に置き、[公開側の入口](./deploy/xserver/public_html/app) を `public_html/app` に配置します。非公開動画は直接URLでアクセスできない場所に保管してください。
