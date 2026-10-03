# WP Checksum Verifier

WordPress のコア・プラグイン・テーマ・MU プラグインの checksum を検証し、改ざんを
検出するプラグイン。公式の checksum マニフェスト(wp.org のコア/プラグイン checksum、
wp.org のテーマの zip から作るマニフェスト)と実ファイルを突き合わせ、どのマニフェスト
にも存在しない未知のファイルも報告する。

> **ステータス**: v0.8.0。検証エンジン、計画していた全ての実行モデル
> (WP-CLI・WP-Cron・管理画面の「今すぐ実行」ボタン・REST API)、resume対応の
> ファイル単位分割実行、抑制エンジン(`exclude_target`/`exclude_path`/
> `allowlist_hash`とstrict mode)、公式checksumの無いプラグイン向けのstat
> 差分検知(内容ハッシュ比較のオプション付き)、設定ファイル・ドロップインの
> 監視、更新イベントの記録、差分検知に基づくメールアラート(下記「アラート」
> 参照)、公式テーマの照合、検出結果・抑制一覧・実行履歴の管理画面を実装済み。
> GitHub リポジトリに対応付けたプラグイン・テーマの照合(下記「GitHub Releases の
> 照合」参照)も実装済み。v0.9 では、履歴の保持期間の設定、マルチサイトの検出結果の
> 「Active on」列、必要な権限のフィルター、より完全な uninstall、GitHub のトークンが
> 拒否されたときの個別の扱いを追加した(下記「履歴の保持期間」「マルチサイト」「権限」
> 「アンインストール」参照)。詳細は `CHANGELOG.md` を参照.

## 検証対象

- **WordPress コア**: インストール済みバージョン/ロケールの公式 checksum と照合する.
- **公式(wp.org)プラグイン**: インストール済みバージョンの公式 checksum と照合する.
- **公式(wp.org)テーマ**: インストール済みバージョンの wp.org の zip から作った
  マニフェストと照合する(下記「公式テーマの照合」参照).
- **MU プラグイン**: 公式の checksum ソースが存在しないため、未知ファイルの検出のみ行う.
- **未知ファイル**: 上記いずれの対象についても、マニフェストに存在しないファイルは
  finding として報告する.
- **公式 checksum の無いプラグイン・テーマ**(独自・有料のプラグインとテーマ、
  MU プラグインの loader): stat 差分検知で変更を追跡する(下記).
- **設定ファイルとドロップイン**(`wp-config.php`・`.htaccess`・`.user.ini`・
  実在するWordPress認識済みドロップイン〔例: `object-cache.php`〕): 同じ仕組みで
  追跡するが、常に内容ハッシュ比較(下記「内容ハッシュ比較」参照)も行う ――
  これらのtargetは「Stat-based change detection」の設定とは無関係に無条件で
  存在する.
- **GitHub リポジトリに対応付けたプラグイン・テーマ**(GitHub Releases で配布している
  非公式プラグインなど): インストール済みバージョンの Release のアセットと照合する
  (下記「GitHub Releases の照合」参照).

### 公式テーマの照合

wordpress.org にはテーマの checksum API が無いため、インストールされている
テーマごとに `https://downloads.wordpress.org/theme/{slug}.{version}.zip` を
取得し、zip の中のファイルのハッシュ(sha256 と md5)を計算します。zip はディスクに
展開しません。作ったマニフェストは DB(`wpcv_manifest_cache`)に保存し、テーマの
version が変わるまで使い回すので、取得に時間がかかるのはテーマを入れた・更新した
あとの最初の run だけです(ローカルでの実測で1テーマあたり1.5〜2.5秒程度)。
run に出てこなくなったテーマ・version の行は、run が `success` か `partial` で
終わったときに消します。WordPress コアのマニフェストも同じようにキャッシュします
(その locale のマニフェストがまだ公開されておらず `en_US` で代用した場合を除く).

- **target**: テーマごとに `theme:{stylesheet}`(checksum 照合)、
  `theme:{stylesheet}:_stat`(stat 差分検知。wp.org と照合できなかったときだけ使う)、
  `theme:{stylesheet}:_scan`(未知ファイル。照合できたときだけ使う)の3つを作ります。
  抑制ルールは3つとも共有します。エラーのあるテーマ(親テーマが無い子テーマなど)も
  対象に含めます.
- **wp.org と照合しないもの**(取得せず、stat 差分検知に回す): wp.org に無いテーマ
  (zip が 404。`manifest_not_found`)、`Update URI` ヘッダーが `wordpress.org`/`w.org`
  以外のホストを指すテーマ(`Update URI: false` も含む。wp.org にある同じ名前の別の
  テーマと照合しないため。`unknown_source`)、テーマのルートの下の階層にあるテーマ
  (`unknown_source`)、version が空のテーマ(`version_unknown`).
- **WordPress コアに同梱されたテーマ**(twentytwentyfive など): 同梱版は同じ version の
  wp.org の zip と中身が違うことがあるため、ファイルごとに wp.org の zip とコアの
  checksum のどちらかと一致すれば正とします。コアの照合では `wp-content/themes/`
  配下を見なくなり、これらのファイルはテーマの target が担当します。どのテーマが
  同梱扱いになるかは、その locale のコアのマニフェストで決まります(たとえば
  `ja` 7.1.2 のマニフェストには twentytwentytwo も含まれますが、`en_US` には
  含まれません).
- **今のコアに同梱されていない古い既定テーマ**は、ビルドの違いだけ(`style.min.css`
  の minify のやり直しなど)で wp.org の zip と一致しないことがあります。初回の run で
  `modified` として出るので、`allowlist_hash` で承認してください。承認はテーマの
  version が変わると失効するので、テーマを更新すれば片付きます.
- **未知ファイル**: wp.org と照合できたテーマでは、zip に無いファイル(コア同梱
  テーマではコアの checksum にも無いファイル)を `added` として報告します(PHP 系の
  ファイルは `high`、それ以外は `medium`)。照合できなかったテーマは stat 差分検知が
  新しいファイルを報告するので、二重には報告しません.
- **zip を使えないとき**: 一時的な取得の失敗(`http_error`)、安全性の検査に落ちた
  zip(`archive_rejected`)、壊れた zip(`archive_invalid`)、PHP の ZipArchive 拡張が
  無いサーバー(`ziparchive_missing`)では、その run のテーマは `unverifiable` になり、
  stat 差分検知には**回しません**(ベースラインを不意に作らないため)。そのため
  ZipArchive の無いサーバーでは、テーマは毎回検査されないままになり、独自テーマと
  同じく「連続 unverifiable」のアラートも出ません。サーバーに拡張が無いおそれがある
  場合は実行履歴を確認してください.
- **安全性の検査と上限**: ファイルを読む前に全エントリを検査し、絶対パス・
  ドライブレター・`..`/`.`/空のセグメント・制御文字・`{slug}/` 以外のルート・
  シンボリックリンク・重複する名前を拒否します。サイズの上限(暫定値。実測した
  wp.org のテーマの最大の約10倍以上): ダウンロード 100MB
  (`wpcv_theme_zip_max_archive_bytes`)、エントリ 20,000 件
  (`wpcv_theme_zip_max_entries`)、1ファイル 50MB(`wpcv_theme_zip_max_entry_bytes`)、
  合計 500MB(`wpcv_theme_zip_max_total_bytes`)、圧縮率 100 倍
  (`wpcv_theme_zip_max_compression_ratio`)。取得のタイムアウトは30秒
  (`wpcv_theme_zip_download_timeout`。実測では1テーマあたり共有ホスティングで最大
  約2秒、ローカルで4.6秒).

### GitHub Releases の照合

wordpress.org に無いが、GitHub で Release を配布しているプラグイン・テーマは、
GitHub Releases のアセットと照合できます。自動検出はしません。設定画面の
「GitHub repository mappings」(1行1件)か、フィルター `wpcv_github_mappings` で、
プラグイン・テーマごとにリポジトリを対応付けます。対応付けの無いプラグイン・
テーマは今までどおりに照合します.

```
plugin:forced-auto-update-controller lunaluna/forced-auto-update-controller
theme:my-theme lunaluna/my-theme my-theme-pro
```

1つ目は `plugin:{slug}` または `theme:{stylesheet}`、2つ目は `owner/repo`、省略できる
3つ目はアセットのファイル名の前方一致です。不正な行と、同じ対象の重複は保存時に
捨てられ(画面に理由が出ます)、コメント行は保存されません。フィルターは
`array( 'target' => ..., 'repo' => ..., 'asset' => ... )` の配列を受け取って返します。
設定画面の値のあとに適用され、設定画面で対応付け済みの対象はそちらが優先されます.

- **どの Release か**: 最新ではなく、このサイトにインストールされている version の
  Release です(`/releases/latest` は使いません。更新前のサイトが丸ごと改変扱いになる
  のを避けるため)。tag は `{version}`、404 のときだけ `v{version}` の順に試します。候補は
  `wpcv_github_tag_candidates` で変えられます。下書き(draft)の Release は見えないため、
  公開するまで照合できません(その対象は stat 差分検知に回ります).
- **どのアセットか**: 3つ目の項目があれば、その名前で始まる `.zip`。無ければ
  `{slug}.{version}.zip`、それも無ければ名前が `{slug}` で始まる `.zip` が1つだけのとき
  それを使います。候補が複数なら `asset_ambiguous`、0 なら `no_release_asset` です。
  GitHub が自動生成するソースアーカイブ(zipball/tarball)は使いません.
- **アセットの検査**: GitHub がアセットに `sha256` の digest を付けていれば、ダウンロード
  したファイルと一致する必要があります(違えば `archive_invalid`)。zip の最上位
  ディレクトリはちょうど1つ(名前が slug と同じである必要はありません)で、テーマの
  zip と同じ安全検査を通ります。zip の中のメインファイル(プラグインのメインファイル、
  テーマは `style.css`)の `Version` ヘッダーがインストール済みの version と同じである
  必要もあり、対応付けの誤りや tag と中身の食い違いは、大量の `modified` ではなく
  `asset_ambiguous` になります。サイズの上限はテーマの zip と同じ暫定値で、
  `wpcv_github_zip_max_archive_bytes`・`wpcv_github_zip_max_entries`・
  `wpcv_github_zip_max_entry_bytes`・`wpcv_github_zip_max_total_bytes`・
  `wpcv_github_zip_max_compression_ratio` で変えられます。アセットのダウンロードの
  タイムアウトは30秒(`wpcv_github_download_timeout`)、API のタイムアウトは10秒
  (`wpcv_github_api_timeout`。実測はローカルで0.2〜0.5秒、共有ホスティングで0.26秒、
  アセットのダウンロードは最大0.9秒)です。
  `X-GitHub-Api-Version` ヘッダーは `2022-11-28`(`wpcv_github_api_version`)です.
- **キャッシュ**: マニフェストは `owner/repo` + インストール済み version をキーにして
  マニフェストキャッシュに保存し、version が変わるまで再利用します。通常の run では
  GitHub に問い合わせません。使われなくなった行(外した対応付け・古い version)は、
  run が `success` / `partial` で終わるときに削除されます。同じ tag のままアセットが
  差し替えられても、version が変わるまで気づきません.
- **`.git` があるディレクトリ**: ディレクトリの直下に `.git`(開発用のチェックアウト、
  またはそれへのシンボリックリンク。リポジトリのツリーは配布 zip と中身が違う)が
  あるプラグイン・テーマは GitHub と照合せず、`unknown_source` として stat 差分検知に
  回ります.
- **結果**: 対応付けた target の `source`(finding の `source` も)は `github` になり、
  wordpress.org には問い合わせません.

  | 結果 | stat 差分検知 |
  | --- | --- |
  | 照合できた(`success`) | 省略(`checksum_covered`) |
  | `manifest_not_found`(tag が無い・下書き・トークン無しの非公開リポジトリ・トークンで見られない非公開リポジトリ。非公開リポジトリの存在を確認させないため、GitHub は 404 を返す)・`unknown_source`(`.git`)・`version_unknown`・`no_release_asset`・`asset_ambiguous` | 実行する(対応付けを誤っても、その対象が何も見られなくならない) |
  | `source_access_denied`(トークンが拒否された: 401、またはレート制限の印の無い 403) | 実行する(トークンが失効しても、その対象が何も見られなくならない。下記「トークンの問題」参照) |
  | `rate_limited`・`http_error`・`archive_invalid`・`archive_rejected`・`ziparchive_missing` | その run では省略(不意にベースラインを作らない) |

- **トークンと非公開リポジトリ**: `wp-config.php` で `WPCV_GITHUB_TOKEN` を定義します
  (または `wpcv_github_token` フィルターでトークンを返します。第2引数は `owner/repo`)。
  トークンは DB に保存せず、画面にも出しません(設定画面は設定済みかどうかだけを表示)。
  トークンがあるときはアセットを GitHub API(`Accept: application/octet-stream`)で
  ダウンロードし、非公開リポジトリでも取得できます。無いときは公開の
  `browser_download_url` を使います。2026-10-01に実機で確認しました: 非公開リポジトリ
  1つに限定し、権限を「Contents: Read-only」だけにした fine-grained トークンで、
  Release の検索とアセットのダウンロードができました(トークン無しでは同じリポジトリが
  `manifest_not_found` になります)。classic トークンでも動きますが、必要以上に権限が広くなります.
- **レート制限**: 認証なしの GitHub API は1時間に60回までです(実測: 404 と
  `If-None-Match` 付きのリクエストも1回に数えられ、`browser_download_url` での公開アセットの
  ダウンロードは数えられません)。マニフェストをキャッシュするので、API を呼ぶのは
  対応付けたプラグイン・テーマの version が変わった後の run だけです。GitHub が
  `x-ratelimit-remaining: 0` つきの 403/429、`retry-after` つきの応答、すべての 429、または本文の
  メッセージがレート制限に触れている 403 を返すと(2次レート制限は `retry-after` が付かないことが
  あります。メッセージは「rate limit」という語で大まかに照合します)、その対象は
  `rate_limited` になり、GitHub が示した時刻(`retry-after`、残りが 0 のときは
  `x-ratelimit-reset`、それも無ければ GitHub のドキュメントに従い60秒)まで GitHub に問い合わせません。次の
  run でまた試します。`rate_limited` は `http_error` と同じく、連続 unverifiable のアラートの
  数に入ります.
- **トークンの問題**: GitHub は、拒否したトークンに 401 を返します。短時間に何度も不正な
  リクエストを送ると、レート制限の印の無い 403 を返します(必要な権限が無いトークンも 403)。
  これらは `http_error` ではなく `source_access_denied` になります。失効したトークンは、一時的な
  通信障害ではなく続く状態だからです。この対象は、連続 unverifiable のアラートの数に入らず、
  stat 差分検知に回って見られ続けます。ダッシュボード・プラグイン一覧・このプラグインの
  画面に、影響する対象の数を知らせる通知が出ます。初めて現れたときだけメールを1通送ります
  (ほかにメールを送る理由が無いときは、件名が `N target(s) cannot be compared with the
  source` になります)。続いている間は、それだけではメールを送りませんが、ほかの理由で送る
  アラートのメールには「Cannot compare with the source」の節に影響する対象を載せます
  (新しいものには印が付きます)。トークンが直ってから再び拒否された場合は、また初回のメールを
  送ります。トークンの値は表示しません。トークンで見られないリポジトリは 404 であり、この場合に
  はなりません(リポジトリ名の誤りやアクセス権が無いことは `manifest_not_found` として現れます).
- **WordPress コアに同梱のテーマ**(例: twentytwentyfive)は対応付けを無視し、
  wordpress.org とコアの checksum で照合します。コアのマニフェストがキャッシュされた後
  (最初の run のあと)は、設定画面が警告を出します.
- **未対応**: MU プラグインの loader と、`wp-content/plugins/` 直下の単一ファイルの
  プラグイン.

### stat 差分検知

checksum で「正しいファイルか」を確かめられるのは、比べる公式の配布物がある場合だけです。
配布物が無いプラグインについては、各ファイルのサイズ・ctime・mtime を記録し
(`lstat()` を使うので symlink はたどらず、ファイルの中身も読みません)、前回の run から
変わったものを報告します。確認すべきファイルを指し示すための機能で、変更が悪意によるものか
どうかは判定しません.

- **対象**: checksum の取得結果が「配布物が無い」(`manifest_not_found`・`unknown_source`・
  `version_unknown`、GitHub に対応付けた対象では `no_release_asset`・`asset_ambiguous`)だったプラグイン・テーマと、MU プラグインの loader だけです。checksum で
  照合できたものは省略します(`checksum_covered`)。取得が一時的に失敗したもの
  (`http_error`・`rate_limited`、テーマでは使えなかった zip。上記「公式テーマの照合」参照)も
  その run では省略し、wordpress.org の障害で不意にベースラインが作られないようにしています。
  対象のプラグインごとに `plugin:{slug}:_stat`(テーマは `theme:{stylesheet}:_stat`、loader は
  `muplugin:{file}:_stat`)という target が追加され、抑制ルールは本体と共有します.
- **初回**: ベースラインを記録するだけで、何も報告しません.
- **finding**: `stat_changed`(サイズ・ctime・mtime のいずれかが変化。`detail` に前回値と
  今回値が入る)、`added`(新しいファイル)、`missing`(ベースラインにあったファイルが消えた。
  1回だけ報告し、ベースラインから外す)。サイズが変わったのに mtime が変わっていない場合は
  タイムスタンプ擬装の疑いとして severity を `high` に上げます.
- **プラグインの更新**: プラグインの version がベースライン作成時の version と違う場合、
  まずその version 変化に対応する更新イベントの記録(下記「更新イベント」参照)が
  あるかを確認します。あれば、変更を報告せずにベースラインを作り直し、target に
  `baseline_rebuilt` を記録します(比較しなかった run であることが実行履歴から分かる
  ようにするため)。記録が無ければ(更新イベントが見つからない・記録の追跡期間が
  届いていない)、ベースラインは捨てずに通常どおり比較し、target に
  `version_changed_unrecorded` を記録します(下記「アラート」参照).
- **version を上げない大量変更**: 最大 500 ファイルの処理単位の中で、変更が 20 件以上かつ
  比較したファイルの 50% 以上なら、プラグインのルートを path にした1件の `stat_changed` に
  まとめます(タイムスタンプ擬装の疑いがある finding は常に個別に残します)。閾値は暫定値で、
  `wpcv_stat_rollup_min_count`・`wpcv_stat_rollup_ratio` フィルターで変えられます.
- **無効にする**: 設定画面の「Stat-based change detection」のチェックを外します(既定は有効)。
  既存のベースラインは残るため、あとで有効に戻すと古いベースラインと比較します.
- **既知の制限**: 同じサイズで書き換えて mtime も元に戻された場合も ctime で検出できますが、
  擬装ではなく通常の `stat_changed` として報告されます ―― ただし、そのtargetで下記の
  内容ハッシュ比較が有効なら、`modified` として直接報告されます.

### 内容ハッシュ比較

サイズ・ctime・mtime の追跡だけでは、「同じサイズ・同じmtimeでの書き換え」を
「変更なし」と区別できません。内容ハッシュ比較は、各ファイルの内容の sha256
ハッシュも計算し、前回runで記録した値と比べることでこの穴を埋めます:

- **設定ファイル・ドロップインのtargetでは常に有効**(`core:_config`:
  `wp-config.php`〔ABSPATHに無ければ1つ上の階層。`wp-settings.php`もその
  階層に無い場合だけ、というWordPressコア自身の探し方と同じ。どちらの場所でも
  `wp-config.php`と表示し、サーバーの絶対パスはfindingやアラートメールに
  出さない〕・
  `.htaccess`・`.user.ini`。`dropin:_stat`: WordPressが認識するドロップイン
  〔`advanced-cache.php`・`db.php`・`db-error.php`・`install.php`・
  `maintenance.php`・`object-cache.php`・`php-error.php`・
  `fatal-error-handler.php`。マルチサイトのみ`sunrise.php`・
  `blog-deleted.php`・`blog-inactive.php`・`blog-suspended.php`も追加〕の
  うち`wp-content/`に実在するもの)。この2つのtargetには本体プラグインも
  versionという概念も無いため、「Stat-based change detection」の設定や
  更新イベントの記録の影響を受けず、内容・サイズ・mtimeのいずれかが変われば
  次のrunでそのまま報告されます。初回はベースラインのみ記録し(他のstat
  targetと同じ)、ドロップインの追加・削除は他のstat targetと同じく
  `added`/`missing`として報告されます.
- **それ以外のstat target(独自・有料プラグイン、MUプラグインのloader)は
  オプトイン**: 毎回すべてのファイルの内容を読むためI/Oコストが増えることから
  既定は無効で、設定画面の「Content-hash comparison for custom plugins」で
  サイトごとに有効化します.
- 前回runと内容ハッシュが異なれば、size/ctime/mtimeの変化の有無に関わらず
  `modified`(`hash_algorithm`/`expected_hash`/`actual_hash`付き。checksum
  targetのfindingと同じ形)として報告されます。内容ハッシュが一致していれば、
  chmod等のメタデータのみの変更は従来どおり`stat_changed`のままです.
- 10MBを超えるファイルはハッシュを計算せず、そのファイルだけstatのみの追跡に
  フォールバックします(`wpcv_content_hash_max_bytes`フィルター)。1回の処理
  単位では200MiBを読んだ時点でいったん区切り、次の処理単位で続きを読みます
  (`wpcv_content_hash_chunk_max_bytes`フィルター)。どちらの既定値も実測した
  ハッシュ計算のスループットに基づく値です(`CHANGELOG.md`参照)。単なる目安
  ではないため、変更する場合は`wp wpcv bench-stat --hash`で自分の環境を
  実測し直してください.

### 更新イベント

WordPress のコア・プラグイン・テーマが更新・インストールされる(管理画面・
WP-CLI〔`wp plugin update`・`wp theme update`・`wp core update`〕・自動更新の
いずれでも)たびに、対象・更新直後にディスクから読んだ version・経路(単体/一括/
インストール/自動/コア更新)・実行者(cron・CLI では `0`)を記録します。子テーマと
一緒に WordPress が自動で入れる親テーマは記録されませんが、新しく入ったテーマには
比べる前回の結果が無いので害はありません。この記録が、上記「プラグインの更新」
やアラート(下記)で「WordPress の更新機構を通った正当な更新」と「それ以外の
経路での version 変化」を区別するために使われます。`wp --skip-plugins plugin
update` はこの記録が使うフックを発火しないため、その方法での更新は記録されません.
記録は90日保持されます(未実測。`wpcv_update_events_retention_days` フィルターで
変更可能。runが終端に達するたびに古い記録を削除します).

## 検証の実行方法

| モード | 方法 | 備考 |
| --- | --- | --- |
| WP-CLI(同期) | `wp wpcv run` | 既定. 完了まで待機する. |
| WP-CLI(非同期) | `wp wpcv run --async` | Action Scheduler が利用可能なら非同期でキューに追加. 利用不可なら同期にフォールバック. |
| WP-Cron | 自動 | 設定画面で指定したUTC時刻に毎日実行. 実行のたびに次回分を自己連鎖で再予約する. |
| 管理画面のボタン | 設定画面の「今すぐ実行」 | リクエストをブロックせず即時実行を予約する. `DISABLE_WP_CRON` が設定されている場合は無効化される. |
| REST API | `POST /wp-json/wpcv/v1/run` | WP-Cronを使えない外部スケジューラー向け. 詳細は下記. |

いずれの経路でも、実行開始前に `running` のまま止まっている過去の run
(実行途中の致命的エラー等)を検知し `failed` にしてから開始する.

### 実行モデル

1回のrunは全targetを一気に検証するのではなく、まずコア・各公式プラグイン・
各テーマ・MUプラグインすべてをDB上のtarget行として先に列挙し、その後1chunkずつ
処理する:

- 各chunkは件数・経過時間・メモリ余裕で区切られた範囲のファイルだけを検証し、
  yieldする前にcursor(最後に確認したpath、manifestのfingerprint、target
  のversion)を保存する。次のchunkはそのcursorから再開する.
- cursor保存後にmanifestのfingerprintやtargetのversionが変わっていた場合
  (例: run途中でプラグインが更新された)は、そのままの内容で続行せず
  targetを最初からやり直す(retry)扱いにする。versionは各chunkを処理する時点の
  ディスク上のファイル(プラグインのメインファイル・テーマの`style.css`・
  `wp-includes/version.php`)から読み、runの開始時に作った一覧や、同じプロセスで
  WordPressがキャッシュした値は使わない。そのため、planのあとに入った自動更新を
  新しいversionと比べ、誤った`modified`を出さない。更新の前後のファイルが混ざった
  可能性があるchunk(chunkの間にversionが変わった・`.maintenance`や更新のlockが
  出た・chunkの開始以降に更新イベントが記録された)は結果を捨てて取り直す。対象外:
  2つのchunkの*間*に同じversionを入れ直す更新と、未知ファイルの走査
  (`core:_scan`・`theme:{stylesheet}:_scan`).
- WP-Cron・CLI `--async`・`POST /run` はすべて同じdispatcherをAction
  Scheduler action経由(RESTの場合は呼び出し元の次回ポーリング経由)で
  起動する ―― 「非同期用の別ロジック」を別途保守する必要はない.
- WP-CLI(同期)と管理画面の「今すぐ実行」も同じchunk単位のdispatcherを
  使い、同一プロセス内でrunが終端状態に達するまで同期的にループするだけ
  なので、どのモードでも結果は同一になる.

つまり大規模サイトのrunは(意図的にdispatcherを自らループさせて同期的に
ブロックするCLI同期・管理画面ボタンのモードを除き)1つの長時間ブロッキング
処理ではない ―― 別々のHTTPリクエスト・別々のAction Scheduler action・
プロセス再起動をまたいでも進捗は失われない.

### タイムアウトとstale状態

時間に関連する概念が4つ登場し、混同しやすいので整理する:

- **chunkの時間予算** — 1回のchunkがcursorを保存してyieldするまでに
  許容される時間(例: `POST /run`のExternal HTTP time budget設定、既定20秒)。
  この上限に達すること自体は正常な動作でエラーではない ―― targetの状態は
  `retry`になり、次のchunkが続きから処理する.
- **stale lease/worker** — claim済み(`running`)のtargetはそれぞれ
  期限付きのleaseを持つ。claimしたworkerがleaseの期限までに応答しなかった
  場合(クラッシュ・強制終了・PHP fatal等)、そのtargetは再claimされ
  retryされる。これが最大試行回数を超えると、error_code
  `lease_expired`とともに`failed`になる.
- **runのdeadline** — run全体には既定6時間の上限がある。それまでに
  終端状態に達しなかった場合、runおよび終端に達していないtargetは
  すべて`aborted`にされる。これにより、詰まったrunが次回予定runを
  無期限にブロックすることはない.
- **更新処理中** — WordPressのコア・プラグインが実際に更新されている間
  (作られたばかりの`.maintenance`ファイル、または`WP_Upgrader`が保持する
  `core_updater.lock`/`auto_updater.lock`)は、次のchunkをclaimして更新
  途中のファイルを読んでしまわないよう、30秒間隔で延期(再チェック)する。
  この間targetを`skipped`にはせず、単に待つだけ(上記runのdeadlineまで)。
  ロックも`.maintenance`も持たないプラグイン単体の更新は検知できないが、
  途中でtargetのversionが変わった場合は既存のcursor不一致によるretry
  (上記)が拾う.

### REST API

以下のすべてのエンドポイントはbearerトークンが必要:
`Authorization: Bearer <token>`(優先)または `X-WPCV-Token: <token>` で
送る。クエリパラメータでの指定は意図的にサポートしていない。すべての応答に
`Cache-Control: no-store` を付与する。同一IPからの認証失敗が続くとレート
制限がかかる。トークンは互いに独立した2つのscopeに分かれており、それぞれ
設定画面から個別に発行する(生成時に1回だけ画面に表示し、DBにはソルト付き
ハッシュのみを保存する):

- **run** — `POST /run` に必要。`wp-config.php` 等で `WPCV_REST_TOKEN` 定数を
  定義すると、設定画面で発行したrun scopeのトークンより優先される
  (read scopeには適用されない)。
- **read** — `GET /status`・`GET /findings` に必要。run scopeとは別に発行し、
  run scopeのトークンではこれらを呼べず、read scopeのトークンでは
  `POST /run` を呼べない。

#### `POST /run`

このエンドポイントは外部スケジューラーから(例: 5分間隔で)繰り返し呼ばれる
ことを前提にしており、呼ばれるたびに新規runを作るわけではない:

- 既にrunが進行中(`queued`または`running`)なら、そのrunのchunk分割実行
  (WP-Cron・WP-CLIと同じtarget/ファイル単位のdispatcher)を、設定画面の
  時間予算(既定20秒)の範囲内で前進させてから応答する.
- 進行中のrunが無く、設定した日次実行時刻(UTC。WP-Cronと共通の設定値)を
  過ぎていて、かつ本日分のrunがまだ無ければ、新規runを作成して同様に
  前進させる.
- それ以外(まだ実行時刻前、または本日分は既に作成済み)の場合は何も
  作成せず、直近runの状態を報告する.

応答には `run_id`・`status`・`pending_targets`・`retry_targets`・
`next_retry_at` を含み、呼び出し元はrunがまだ進行中かどうかを見て
ポーリングを続けられる。このエンドポイントはグローバルなAction Scheduler
キューを実行することは無く、あくまで自身のrunだけを前進させる。
busy(advisory lockの競合)や内部エラーの場合は200ではなくエラー応答を返す.

WP-Cronが機能しないホスト(`DISABLE_WP_CRON`設定時や、トリガーとなる
アクセスが無いサイト等)では、外部スケジューラーからこのエンドポイントを
5分間隔で叩く。crontabの例:

```cron
*/5 * * * * curl -s -X POST -H "Authorization: Bearer <run scopeトークン>" https://example.com/wp-json/wpcv/v1/run >/dev/null
```

runが進行中のときの応答例:

```json
{
  "run_id": 42,
  "status": "running",
  "pending_targets": 3,
  "retry_targets": 1,
  "next_retry_at": "2026-09-11T13:05:00+00:00"
}
```

#### `GET /status`

read scopeのトークンが必要。`current_run`(進行中のrun。無ければ `null`)・
`last_run`(直近に完了したrun。無ければ `null`)・`next_scheduled_at`
(設定画面の実行時刻から計算した次回の日次due時刻。実際にどのモードが
それを起動するかは問わない)を返す。各runには、target状態別の集計
(`queued`・`retry`・`running`・`success`・`unverifiable`・`failed`・
`skipped`・`aborted`・`total`)・`findings_total`・`scheduled_for`・
`deadline_at`・`last_activity_at`(最後にtargetがclaim・確定された時刻。
進捗が止まったrunを見つけるのに使える)・`diff_status`(runごとの差分処理の
状態。開始前は`null`。下記「アラート」参照)・`findings_new`/
`findings_resolved`/`findings_continuing`(数えるまで`null`のまま。0には
しない ―― 「まだ数えていない」と「0件」を区別する)・`alert_status`/
`alert_attempted_at` を含む。`alert_error` 等の内部の値はここには出ない
(実行履歴の管理画面を参照).

応答例:

```json
{
  "current_run": null,
  "last_run": {
    "run_id": 37,
    "status": "partial",
    "run_trigger": "cli",
    "started_at": "2026-09-11T05:45:00+00:00",
    "finished_at": "2026-09-11T05:46:12+00:00",
    "scheduled_for": "2026-09-11T05:45:00+00:00",
    "deadline_at": "2026-09-11T11:45:00+00:00",
    "last_activity_at": "2026-09-11T05:46:10+00:00",
    "findings_total": 7,
    "targets": {
      "queued": 0, "retry": 0, "running": 0, "success": 36,
      "unverifiable": 7, "failed": 0, "skipped": 0, "aborted": 0, "total": 43
    },
    "diff_status": "done",
    "findings_new": 5,
    "findings_resolved": 0,
    "findings_continuing": 1,
    "alert_status": "sent",
    "alert_attempted_at": "2026-09-11T05:46:13+00:00"
  },
  "next_scheduled_at": "2026-09-12T05:45:00+00:00"
}
```

#### `GET /findings`

read scopeのトークンが必要。1つのrun(既定は最新run。`run_id`クエリ
パラメータで指定も可能)のfindingsを返す。`dimension`・`status`(`stat_changed`を含む)・
`severity`・`diff_state`(`new`/`continuing`/`event`。下記「アラート」参照)
(単一値または配列。例: `dimension[]=core&dimension[]=plugin`。
それぞれ固定のallowlist外の値を渡すと`400`)・`sort`/`order`
(allowlistされた列のみ)・`page`/`per_page`(小さい既定値・上限あり)の
pagination に対応する。suppressed・closedなfindingは既定で除外し、
`include_suppressed=1`/`include_closed=1` で含められる。応答には
`findings`・`run_id`・`page`・`per_page`・`total`・`total_pages` を含む。
各findingの`detail`は、stat差分検知のfindingでのみ前回値・今回値のサイズ/ctime/mtime
(まとめたfindingでは変更件数と代表パス)をJSON文字列で持ち、それ以外は`null`。
各findingは`finding_key`(runをまたいで同一性を判定するキー)・`diff_state`・
`notified_at`(最後にメール済みの日時。無ければ`null`)・`ended_in_run_id`・
`end_reason`も持つ ―— 差分処理がまだ触れていないfinding(進行中のrun・
failed/abortedのrun・差分検知導入前のrunに属するもの)ではすべて`null`.

応答例:

```json
{
  "findings": [
    {
      "id": 101, "run_id": 37, "target_id": "plugin:hello-dolly",
      "dimension": "plugin", "slug": "hello-dolly", "version": "1.7.2",
      "path": "readme.txt", "status": "modified", "severity": "low",
      "hash_algorithm": "sha256", "expected_hash": "...", "actual_hash": "...",
      "suppressed_by": "soft_change", "suppression_id": null,
      "finding_key": "...", "diff_state": "new", "notified_at": "2026-09-11T05:46:13+00:00",
      "ended_in_run_id": null, "end_reason": null
    }
  ],
  "run_id": 37,
  "page": 1,
  "per_page": 20,
  "total": 1,
  "total_pages": 1
}
```

## アラート

このプラグインは毎回のrunを基準(baseline)と比較し、注目に値する変化が
あればメールで要約を送る。比較は target(コア・各プラグイン・各MU
プラグイン)ごとに行う ―— あるtargetの基準は、そのtarget自身の直近の
検証成功runであり、他のtargetがどうなったかとは独立している.

- **`new`** — finding の同一性(おおむね target + path + hash)が基準に
  無かったもの.
- **`continuing`** — 基準にもあり、今回も引き続き存在するfinding。本文
  では個別に再掲せず、件数だけをまとめて表示する.
- **`resolved`** — 基準にはあったが今回は無くなったfinding.
- **`event`** — stat差分検知のfinding(上記参照)は常にこの扱いで、毎回
  報告され、再送抑制もかからない。公式のマニフェストと比較しているわけ
  ではないため、stat差分検知が検出した変化はその時点で常に新しい情報
  だから.

プラグイン・テーマの更新それ自体は`new`のfindingを生まない: targetのversionが
基準のversionと異なる場合、古い基準は捨てられ、findingは(何とも比較
せずに)`version_changed`として扱われる ―— 新規追加として報告される
ことはない。version変更後にstatのベースラインを作り直しただけのrunや、
`version_changed`/`excluded`/抑制済みのfindingを終わらせただけのrunは、
それ単独ではメールを送らない ―— 次に実際に送られるメールの
「Not verified today」節に同封される.

checksum targetのversion変化は、更新イベントの記録(上記「更新イベント」
参照)に一致するものが無い場合は扱いが異なる: 古い基準は捨てず、finding は
通常どおり比較され ―— 設定画面の「Alert on version changes that did not go
through the WordPress updater」がチェックされていれば(既定でON)、メールに
「Version changed without a WordPress update:」という節が追加され
`target: from -> to` の形で列挙される。これは version 文字列への不正な
書き換えも、git/FTP/Composer による正当なデプロイも同じく検出しうる ――
そうしたデプロイ方法を使うサイトでは、この設定をOFFにしないと毎回のデプロイ
でアラートが飛んでしまう。これが唯一報告すべき内容の場合(通常の新規/解消
findingが無い場合)、件名は`0 new findings, 0 resolved`ではなく`N version
change(s) without a WordPress update`になる.

同じ同一性の`new`finding は最大でも7日に1回しか再送しない。解決と再発を
繰り返すファイルがrunのたびにメールを溢れさせないようにするため。メール
送信が失敗した場合、そのfindingは「通知済み」にせず、次回のrunで再送する
―— このプラグインは「厳密に1回だけ送る」ではなく「少なくとも1回は送る」
ことを目指している(一時的な失敗のあとの重複送信のほうが、黙って
findingを取りこぼすより望ましい).

通常のfindingが無くても、次の2つの状況ではメールを送る:

- あるtargetが3回連続(既定。未実測)で`unverifiable`になった(例:
  wp.orgのchecksum取得が繰り返し失敗している)場合 ―— 下記
  `wpcv_alert_unverifiable_streak` フィルター参照.
- プラグイン自身の検証runが3回連続(既定。未実測)で失敗・中断した場合
  ―— 下記 `wpcv_alert_run_failure_streak` 参照。このアラートはrunが
  `failed`/`aborted`で終端に達した時点で評価され、上記の差分処理とは
  独立している.

両方の連続アラートとも、連続が続いている間に毎回ではなく1回だけ発火する。
純粋にrunの履歴から評価するため、連続が途切れて再び始まっても、特別に
リセットする必要はない.

3つ目の状況でも、通常のfindingが無くてもメールを送る: あるtargetの GitHub トークンが
初めて拒否されたとき(`source_access_denied`。上記「GitHub Releases の照合」参照)。
問題が続く間は毎回ではなく1回だけ送り、この対象は連続unverifiableのアラートの
数に入らない.

**宛先とテスト送信**: 設定画面の **Alert recipients**(1行1アドレス)に
1つ以上のアドレスを設定する。空のままだとメールは送られず、代わりに
警告の通知が表示される(黙って何もしないのではなく)。実運用の前に
**Send test alert** で宛先が正しいか確認できる ―— 結果は同じ画面に
その場で表示される.

**アラートで検知できないこと**: WP-Cron自体が発火しなくなった場合(例:
`DISABLE_WP_CRON`が未設定でアクセスの少ないサイト、サイト自体がダウン
している等)、runそのものが一切起きず、プラグインの内側からはこれを
検知できない ―— それが懸念なら外部スケジューラーから`POST /run`
(上記参照)を叩くこと.

メール以外にも、`wpcv_alert_channels` フィルターで追加のチャネル
(Slack・webhook等)を登録できる:

```php
add_filter( 'wpcv_alert_channels', function ( $channels, $context ) {
    $channels[] = array(
        'name' => 'my-webhook',
        'send' => function ( $context ) {
            // $context には type('diff'または'run_failure')・run_id・
            // subject・body・件数・管理画面の検出結果画面URLが入る。
            // alert_error・トークン・サーバーのファイルパスは含まれない.
            return true; // 失敗時は false を返すか例外を投げる.
        },
    );
    return $channels;
}, 10, 2 );
```

チャネルは、プラグインが実際にメール送信を試みたとき(宛先未設定の
ときは実行しない)にだけ実行される。1つのチャネルの失敗はメールや他の
チャネルに影響せず、再送もしない。また、runが終端状態に達するたびに
発火する `wpcv_run_terminated` アクション(`do_action(
'wpcv_run_terminated', $run_id, $status )`)もあり、run完了に直接反応
したい連携先向けに使える.

数値の閾値は意図的に設定画面には出していない(未実測の既定値であり、
変える理由がある場合のみ調整する)。フィルターで変更できる:

| フィルター | 既定値 | 内容 |
| --- | --- | --- |
| `wpcv_alert_max_items` | 20 | メール本文にseverity順で載せる上位件数 |
| `wpcv_alert_resend_days` | 7 | 解決後に再発したfindingを再送するまでの日数 |
| `wpcv_alert_unverifiable_streak` | 3 | targetへのアラートに必要な連続unverifiable回数 |
| `wpcv_alert_run_failure_streak` | 3 | アラートに必要な連続failed/aborted回数 |

## 管理画面

設定画面(下記)に加えて、同じ「Checksum Verifier」トップレベルメニュー配下
(マルチサイトではネットワーク管理画面)に3つの読み書き画面がある:

- **検出結果(Findings)** — 直近run(または指定した`run_id`)のfindingsを、
  `GET /findings`と同じ`dimension`/`status`/`severity`/`diff_state`/
  suppressed/closedフィルタ付きで表示する。**Diff**列に`new`/`continuing`/
  `event`(通知済みなら日時を添えて`new (emailed 2026-09-11 05:46:13)`の
  ように表示)、差分処理がまだ触れていないfindingはダッシュを表示する。
  各行から理由入力必須の3操作をワンクリックで
  実行できる: **パス除外**(そのtarget・pathに限定した`exclude_path`
  ルールを作成)、**このhashを承認**(表示中のhash/versionをそのまま
  `allowlist_hash`ルールとして作成。`added`/`modified`のfinding ―— 承認
  対象となるhashを実際に持つもの ―— にのみ表示)、**targetごと除外**
  (そのプラグイン・コア・MUプラグインを次回run以降まるごと検証対象外に
  する`exclude_target`ルールを作成)。いずれも画面に表示中のfindingを
  遡って書き換えることはなく、次回run以降から適用される。stat差分検知の
  findingは**Details**列に何が変わったか(サイズ・時刻の前回値→今回値、
  またはまとめた件数)を表示する。マルチサイトでは**Active on**列に、そのプラグイン・
  テーマを使っている場所を表示する(下記「マルチサイト」参照).
- **抑制一覧(Suppressions)** — これまでに作成された全ての抑制ルール
  (3種別すべて)を、対象・理由・作成者・作成日時・(`allowlist_hash`のみ)
  承認済みversionとhashの先頭部分とともに表示する。有効なルールは
  (理由入力必須で)取消でき、削除ではなく取消として記録・併記される。
  `allowlist_hash`ルールは、その対象のversionが承認時と異なるversionへ
  変わった時点で自動的に失効する(「version changed」と表示される) ―—
  ハッシュを承認しても、古いversionへ戻したときに永久に効き続けることは
  無くなった。`exclude_path`/`exclude_target`ルールはこの対象外.
- **実行履歴(Run History)** — 全runを新しい順に表示し、一覧には**Diff**
  (`+新規 / −解消 / =継続`、差分処理が進行中のrunでは`diff_status`の
  生の値)・**Alert**(`sent`/`not_needed`/`no_recipient`/`failed`)列を
  持つ。run詳細では各targetの状態・`error_code`(`unverifiable`/`retry`/
  `aborted`/`skipped`なtargetの理由を人間可読なラベルに変換したもの。
  `version_changed_unrecorded`〔上記「アラート」参照〕を含む。stat差分検知が代わりに
  見た`unverifiable`のtargetには「cannot be compared with the source; monitored by
  file-change tracking instead」と添える)・
  ファイル件数・試行回数・**Diff mode**列(上記「アラート」参照)を確認
  できる。run単位の詳細にも差分・アラートの状態、アラートのエラーや
  失敗したチャネル(あれば。管理画面限定 ―— RESTには一切出さない)、
  その runのfindingsへのリンクを表示する。**Update events since the
  previous run**節には、直前runから今回runまでの間に記録された更新イベント
  (上記「更新イベント」参照)を一覧表示する(最初のrunには直前runが無いため
  表示しない)。**Findings ended in this run**節には、そのrunが解消・除外等で
  終わらせたfindingを一覧表示する(解消を先頭に並べ、基準が大きいrunでも
  ページ分けする).

## 設定

設定画面(マルチサイトではネットワーク管理画面)は**状態パネル**から
始まる: 進行中のrunと直近完了run(それぞれのtarget集計・最終活動時刻・
`diff: +5 / −0 / =1, alert: sent`のような差分・アラートの要約)、
次回予定実行時刻、WP-Cronが有効かどうか、Action Schedulerが利用可能か
どうか(利用不可の場合、非同期実行は黙って同期実行にフォールバックする)、
このサイトで記録されている直近のWP-CLI実行(あくまで「ここに記録された
run」を反映するだけで、サーバーにWP-CLI自体がインストールされているか
どうかを検出するものではない)。その下から、日次実行時刻
(UTC。WP-CronとRESTの日次due判定が共通で使う)・RESTエンドポイントの
時間予算・strict mode(readme.txt/readme.mdの変更を低リスクな「soft change」
として抑制せず、通常のfindingとして報告する。既定は無効)・stat差分検知
(既定は有効)・独自プラグイン・MUプラグインのloader向けの内容ハッシュ比較
(既定は無効。上記「内容ハッシュ比較」参照。設定ファイル・ドロップインは
この設定に関わらず常に内容ハッシュ比較の対象)・WordPressの更新機構を
通らないversion変化を通知するか(既定は有効。上記「アラート」参照)・
アラートの宛先と「Send test alert」ボタン(上記「アラート」参照)・
GitHub リポジトリの対応付けとGitHubのトークンが設定済みかどうか(上記「GitHub Releases の
照合」参照)・履歴をどれだけ保持するか(下記「履歴の保持期間」参照)・RESTトークンの
発行を設定できる。実行時刻の時・分は常に2桁で表示される.

## 履歴の保持期間

既定では、すべての run の履歴を無期限に保持します。設定画面の**History retention**(**Keep
forever**〔既定〕、3・6・12・24 か月)を選ぶと、古い履歴を削除します。期間を選ぶまでは何も
削除しません。選んだあとは、その後の run の終わりに、古い履歴が少しずつ消えます。

記録の古さは、その run の開始時刻で決まります。各 run の終わりに、期限を過ぎた
対象ごとの結果を最大 500 件、run の記録を最大 500 件削除します(1 回の run がデータベースを
長く占有しないための暫定の上限)。履歴が大きいサイトでは、追いつくまでに数回の run が
かかります。削除の順序は findings → 対象ごとの結果 → run の記録で、途中で止まっても、
プラグインが読む行が壊れた状態にはなりません。

古さに関わらず、常に残すもの:

- 各対象の、直近の照合に成功した結果と、その findings、それが属する run の記録 ―
  次の run が比較する基準で、消すと次の run がすべてを新規として報告してしまいます。
- 処理中の run(差分・アラートの処理を含む)と、それぞれが比較する結果。
- すでにメールで知らせ、その基準にまだ存在する findings(と、それが属する結果) ―
  再送の抑制がこれを探すので、消すと同じ finding をもう一度メールします。
- 有効な抑制ルール(取り消したルール・失効したルールは、期間より長く失効している場合に削除)。

対象外: 更新イベント(90 日。`wpcv_update_events_retention_days`)、マニフェストの
キャッシュ(別に掃除する)、stat のベースライン(もともと最新の世代だけを持つ)。

未解決の finding でも、古い記録は残りません: 長く続く finding の古い世代と、stat 差分検知の
finding(「解消」されない)は、ほかと同じく削除されます。マルチサイトでは履歴がネットワーク
全体で共有されるので、設定も1つです。

副作用が1つあります: 連続 unverifiable と連続 run 失敗のアラートは、連続が途切れるまで履歴を
遡ります。失敗が保持期間より長く続くと、以前に送ったアラートの記録が消え、アラートが再び
送られます。失敗が続く間、おおよそ保持期間ごとに 1 回で、念押しとして働きます。

## マルチサイト

プラグインは、マルチサイトのネットワークを 1 つのインストールとして扱います。テーブルは
ネットワークの base prefix で 1 回だけ作られ、設定はネットワークの設定で、画面はネットワーク
管理のメニューにあり、検証はディスク上のすべてのプラグイン・テーマが対象です(どのサイトで
有効かは問いません)。

**ネットワーク有効化してください。** ネットワーク有効化が想定の構成です。main site だけで
有効化しても動きます(ネットワーク管理画面は main site のプラグインを読み込むので、メニューも
定時の run も使えます)。しかしサブサイトだけで有効化すると、定時の run は main site から予約
されるため、**定時の run がまったく始まらず**、ネットワーク管理のメニューも出ません。この場合、
そのサブサイトのダッシュボードとプラグイン一覧に警告を出します(プラグインを有効化できる
ユーザーに。スーパー管理者にはネットワーク管理のプラグイン一覧へのリンク付き)。有効化自体は
止めません。サブサイトでも `wp wpcv run` と REST API は動きます(WP-CLI は `--url` を指定)。

**Active on 列。** 検出結果画面の plugin・theme の finding に、そのプラグイン・テーマを使っている
場所を表示します:

- ネットワーク有効化されたプラグインは「Network-wide (all sites)」。そうでなければ、有効に
  しているサイト。
- テーマは、有効なテーマにしているサイトと、有効な子テーマの親として使っているサイト
  (「(parent theme)」)。ネットワークで有効にしているが、どこでも使っていないテーマは、その旨を表示。
- どこでも有効でないものは「Not active on any site」(ファイルは引き続き検査される)。
- core・MU プラグイン・ドロップインはダッシュ。

サイト名は最大 5 件まで列挙し、あとは「and N more」にまとめます。この列は画面を表示するときに、
すべてのサイトの有効なプラグインとテーマを読んで求めます(削除済み・スパムのサイトは除外、
アーカイブ済みのサイトは含める)。保存もキャッシュもせず、run の最中には計算しません。1 サイトの
読み取りは約 1 ms です(20 サイトのネットワークでの実測。大きなネットワークでは未測定)。ネットワークの
サイト数が 500 を超えるときは計算せず、「Not shown (the network has N sites)」と表示します。上限は
`wpcv_affected_sites_scan_limit` フィルター(既定 500)で変えられます:

```php
add_filter( 'wpcv_affected_sites_scan_limit', function () {
    return 2000;
} );
```

## 権限

画面を使える人(トークンの発行・今すぐ実行・抑制ルールの作成と取消を含む)は、1 か所で
決めています。既定は従来と同じで、単一サイトは管理者(`manage_options`)、マルチサイトは
スーパー管理者(`manage_network_options`)です。REST API は専用の bearer トークンで認証し、
WP-CLI はサーバーに入れる人が使うので、どちらも対象外です。

`wpcv_required_capability` フィルターは、権限と画面名(`settings`・`runs`・`findings`・
`suppressions`・`notices`〔アラートの通知〕)を受け取り、要求する権限を返します。同じ名前の
メニュー・画面・送信するすべてのフォーム・通知が、同じ値を使います。空でない文字列以外を
返した場合は無視します。

```php
add_filter( 'wpcv_required_capability', function ( $capability, $screen ) {
    // 編集者に、実行履歴と検出結果を見せる(それ以外は既定のまま).
    return in_array( $screen, array( 'runs', 'findings' ), true ) ? 'edit_pages' : $capability;
}, 10, 2 );
```

緩めるときは注意してください: 検出結果画面は、開ける人に、以後のアラートを止める抑制
ルールの作成を許します。その権限を任せてよいロールにだけ渡してください。1 つの画面だけを緩めても、
ほかの流れ(たとえば抑制一覧)は既定のままです。フィルターは単一サイトで確認しました。
マルチサイトでは、画面がネットワーク管理にあり、既定がスーパー管理者の権限なので、緩めた場合は
未確認です。

## アンインストール

プラグインを無効化してから削除すると、プラグインが作ったものを削除します:

- 7 つのテーブル(run・対象ごとの結果・findings・抑制ルール・stat のベースライン・更新イベント・
  マニフェストのキャッシュ)。
- option `wpcv_db_version`・`wpcv_settings`・`wpcv_rest_token_hash`・
  `wpcv_rest_token_hash_read`・`wpcv_update_events_since`(マルチサイトでは site option)。
- transient: GitHub のレート制限の印・REST トークンの認証失敗の回数・自己更新の確認のキャッシュ
  (名前の完全一致で消すので、同じ自己更新ライブラリを使う他のプラグインのキャッシュは残る)。
- Action Scheduler のテーブルにあるこのプラグインの行: フックが `wpcv_` で始まるアクション
  (完了済み・取消済みを含む)、そのログ、グループ `wpcv`。Action Scheduler 自体のテーブルは
  他のプラグインと共有なので、そのまま残します。

予約済みのイベントは、アンインストールではなく無効化のときに消えます。マルチサイトでは、
失敗の回数と Action Scheduler の行を、すべてのサイトから削除します。大きなネットワーク
(`wp_is_large_network()`。既定ではサイト数 10,000 超)では、所要時間が読めないので main site
だけを掃除します。外部オブジェクトキャッシュにある行は、自然に期限が切れます(失敗の回数は
5 分後)。

## 配布方針

本プラグインは WordPress.org Plugin Directory には公開しない。GitHub Releases
経由の自己更新機構(`Update URI: false`、
[l2d-wp-github-update-lib](https://github.com/lunaluna/l2d-wp-github-update-lib)
を利用)で配布する。

## 動作要件

- PHP 7.4+
- WordPress 6.8+ (同梱する Action Scheduler の最新安定版が要求する最小バージョンに
  合わせている)

## 開発

```sh
composer install
composer run lint     # PHPCS
composer run analyse  # PHPStan
composer run test     # PHPUnit
```

`composer install` するだけで、非同期実行(v0.3以降)の基盤である Action
Scheduler もローカルで使えるようになる(`lib/` への手動コピーは不要)。
開発環境では `vendor/woocommerce/action-scheduler` から自動的に読み込む
(`WPCV_Action_Scheduler_Loader` 参照)。`lib/` へのコピーはリリースビルド時
(`bin/build-zip.pre.sh`)にのみ生成される、配布zip専用のものである.

### 翻訳

管理画面・アラートメール・管理画面の通知は日本語に翻訳済みです
(`languages/wp-checksum-verifier-ja.po`。`.mo` と、WordPress 6.5 以降が先に読む
`.l10n.php` にコンパイルして同梱します)。WP-CLI のメッセージは翻訳しません。
翻訳対象の文字列を変えたら、次の手順でファイルを作り直してください
(`lib/l2d-updater` は別のテキストドメインなので `lib/` と `vendor/` は除きます):

```sh
wp i18n make-pot . languages/wp-checksum-verifier.pot --exclude=vendor,lib,tests,bin,node_modules
# languages/wp-checksum-verifier-ja.po を更新(msgmerge -U)し、新しい文字列を訳す
wp i18n make-mo languages
wp i18n make-php languages
composer run test   # TranslationFilesTest: 未訳・fuzzy が無く、プレースホルダーが一致すること
```

English version: [README.md](README.md)
