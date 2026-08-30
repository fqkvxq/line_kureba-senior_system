CREATE TABLE IF NOT EXISTS cars (
    id TEXT PRIMARY KEY,
    shop_code TEXT NOT NULL,
    title TEXT NOT NULL,
    total_price_text TEXT,
    total_price_num REAL,
    base_price_text TEXT,
    base_price_num REAL,
    year TEXT,
    distance TEXT,
    distance_num REAL,
    displacement TEXT,
    repair_history TEXT,
    shaken TEXT,
    image_url TEXT,
    detail_url TEXT,
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_cars_active ON cars(is_active);
CREATE INDEX IF NOT EXISTS idx_cars_price ON cars(total_price_num);
CREATE INDEX IF NOT EXISTS idx_cars_title ON cars(title);
INSERT OR REPLACE INTO cars (id, shop_code, title, total_price_text, total_price_num, base_price_text, base_price_num, year, distance, distance_num, displacement, repair_history, shaken, image_url, detail_url, is_active, updated_at)
VALUES ('700060149230251012001', '0601492', 'ワゴンＲスティングレー Ｔ ターボ ベンチシート スマートキー スペアキー 社外ＳＤナビ ワンセグＴＶ ＣＤラジオ １５インチアルミホイール 純正ＨＩＤヘッドライト フォグランプ ...', '60万円', 60, '55万円', 55, '2014(平成26)年', '4.2万km', NULL, '660cc', 'なし', '2027(令和9)年1月', 'https://picture1.goo-net.com/7000601492/30251012/Q/70006014923025101200100.jpg', 'https://www.goo-net.com/usedcar/spread/goo/15/700060149230251012001.html', 1, CURRENT_TIMESTAMP);
INSERT OR REPLACE INTO cars (id, shop_code, title, total_price_text, total_price_num, base_price_text, base_price_num, year, distance, distance_num, displacement, repair_history, shaken, image_url, detail_url, is_active, updated_at)
VALUES ('700060149230260526001', '0601492', 'Ｎ－ＢＯＸ Ｇ・Ｌホンダセンシング ギャザーズ８インチＳＤナビ 地デジ ａｐｐｌｅｃａｒｐｌａｙ ＤＶＤ バックカメラ ＥＴＣ ドラレコ パワースライドドア センシング 革調シート...', '64万円', 64, '58万円', 58, '2017(平成29)年', '10.4万km', NULL, '660cc', 'なし', '2026(令和8)年11月', 'https://picture1.goo-net.com/7000601492/30260526/Q/70006014923026052600100.jpg', 'https://www.goo-net.com/usedcar/spread/goo/15/700060149230260526001.html', 1, CURRENT_TIMESTAMP);
INSERT OR REPLACE INTO cars (id, shop_code, title, total_price_text, total_price_num, base_price_text, base_price_num, year, distance, distance_num, displacement, repair_history, shaken, image_url, detail_url, is_active, updated_at)
VALUES ('700060149230260622001', '0601492', 'デイズ ハイウェイスターＸ Ｖセレクション＋セーフティＩＩ 純正ＳＤナビ 地デジＴＶ ＤＶＤ Ｂｌｕｅｔｏｏｔｈ アラウンドビューモニター ドライブレコーダー ＥＴＣ 衝突軽減装置...', '68万円', 68, '58万円', 58, '2015(平成27)年', '4.3万km', NULL, '660cc', 'あり', '車検整備付', 'https://picture1.goo-net.com/7000601492/30260622/Q/70006014923026062200100.jpg', 'https://www.goo-net.com/usedcar/spread/goo/15/700060149230260622001.html', 1, CURRENT_TIMESTAMP);
INSERT OR REPLACE INTO cars (id, shop_code, title, total_price_text, total_price_num, base_price_text, base_price_num, year, distance, distance_num, displacement, repair_history, shaken, image_url, detail_url, is_active, updated_at)
VALUES ('700060149230260411002', '0601492', 'タント カスタムＸリミテッド 届出済未使用車 純正ディスプレイオーディオ 地デジＴＶ カープレイ対応 バックカメラ 両側パワースライドドア スマートアシスト シートヒーター ベンチ...', '199万円', 199, '192万円', 192, '2026(令和8)年', '10km', 0.001, '660cc', 'なし', '2029(令和11)年3月', 'https://picture1.goo-net.com/7000601492/30260411/Q/70006014923026041100200.jpg', 'https://www.goo-net.com/usedcar/spread/goo/15/700060149230260411002.html', 1, CURRENT_TIMESTAMP);
INSERT OR REPLACE INTO cars (id, shop_code, title, total_price_text, total_price_num, base_price_text, base_price_num, year, distance, distance_num, displacement, repair_history, shaken, image_url, detail_url, is_active, updated_at)
VALUES ('700060149230260412001', '0601492', 'タント Ｌ 届出済未使用車 ９インチディスプレイオーディオ 地デジＴＶ カープレイ対応 ＨＤＭＩ バックカメラ Ｂｌｕｅｔｏｏｔｈ ＬＥＤヘッドライト オートリトラクタブルミラー ...', '159万円', 159, '153万円', 153, '2026(令和8)年', '10km', 0.001, '660cc', 'なし', '2029(令和11)年3月', 'https://picture1.goo-net.com/7000601492/30260412/Q/70006014923026041200100.jpg', 'https://www.goo-net.com/usedcar/spread/goo/15/700060149230260412001.html', 1, CURRENT_TIMESTAMP);
INSERT OR REPLACE INTO cars (id, shop_code, title, total_price_text, total_price_num, base_price_text, base_price_num, year, distance, distance_num, displacement, repair_history, shaken, image_url, detail_url, is_active, updated_at)
VALUES ('700060149230250511001', '0601492', 'ハスラー ハイブリッドＸ ディスプレイオーディオ ＣＤ／ＤＶＤ ＬＥＤオートヘッドライト デュアルカメラブレーキサポート クリアランスソナー シートヒーター スマートキー スペアキ...', '135万円', 135, '125万円', 125, '2021(令和3)年', '4.0万km', NULL, '660cc', 'なし', '車検整備付', 'https://picture1.goo-net.com/7000601492/30250511/Q/70006014923025051100100.jpg', 'https://www.goo-net.com/usedcar/spread/goo/15/700060149230250511001.html', 1, CURRENT_TIMESTAMP);
INSERT OR REPLACE INTO cars (id, shop_code, title, total_price_text, total_price_num, base_price_text, base_price_num, year, distance, distance_num, displacement, repair_history, shaken, image_url, detail_url, is_active, updated_at)
VALUES ('700060149230260715001', '0601492', 'Ｃ－ＨＲ Ｇ モデリスタフルエアロ 純正９インチＳＤナビ 地デジ バックカメラ Ｂｌｕｅｔｏｏｔｈ ＥＴＣ セーフティセンス ＬＥＤヘッドライト ハーフレザーシート スマートキー ...', '200万円', 200, '188万円', 188, '2017(平成29)年', '3.2万km', NULL, '1800cc', 'なし', '車検整備付', 'https://picture1.goo-net.com/7000601492/30260715/Q/70006014923026071500100.jpg', 'https://www.goo-net.com/usedcar/spread/goo/15/700060149230260715001.html', 1, CURRENT_TIMESTAMP);
INSERT OR REPLACE INTO cars (id, shop_code, title, total_price_text, total_price_num, base_price_text, base_price_num, year, distance, distance_num, displacement, repair_history, shaken, image_url, detail_url, is_active, updated_at)
VALUES ('700060149230260630001', '0601492', 'キャデラックＸＴ５クロスオーバー プラチナム 左ハンドル ディスプレオーディオ カープレイ対応 全方位カメラ ドラレコ前後駐車監視 デジタルインナーミラー 衝突軽減装置 パワーバッ...', '300万円', 300, '285万円', 285, '2018(平成30)年', '3.2万km', NULL, '3600cc', 'なし', '2027(令和9)年3月', 'https://picture1.goo-net.com/7000601492/30260630/Q/70006014923026063000100.jpg', 'https://www.goo-net.com/usedcar/spread/goo/15/700060149230260630001.html', 1, CURRENT_TIMESTAMP);
INSERT OR REPLACE INTO cars (id, shop_code, title, total_price_text, total_price_num, base_price_text, base_price_num, year, distance, distance_num, displacement, repair_history, shaken, image_url, detail_url, is_active, updated_at)
VALUES ('700060149230250108001', '0601492', 'Ｎ－ＷＧＮカスタム Ｇ・Ａパッケージ 純正ＨＩＤライト ハーフレザーシート 社外ＳＤナビ 地デジＴＶ ＤＶＤ再生 Ｂｌｕｅｔｏｏｔｈオーディオ バックカメラ ベンチシート スマート...', '65万円', 65, '55万円', 55, '2015(平成27)年', '3.5万km', NULL, '660cc', 'あり', '車検整備付', 'https://picture1.goo-net.com/7000601492/30250108/Q/70006014923025010800100.jpg', 'https://www.goo-net.com/usedcar/spread/goo/15/700060149230250108001.html', 1, CURRENT_TIMESTAMP);
INSERT OR REPLACE INTO cars (id, shop_code, title, total_price_text, total_price_num, base_price_text, base_price_num, year, distance, distance_num, displacement, repair_history, shaken, image_url, detail_url, is_active, updated_at)
VALUES ('700060149230250214001', '0601492', 'Ｂクラス Ｂ１８０ 衝突軽減装置 ドラレコ前後 純正ＳＤナビ 地デジＴＶ走行中ＯＫ Ｂｌｕｅｔｏｏｔｈ カープレイ接続 バックカメラ ＬＥＤヘッドライト シートヒーター 黒革シート...', '138万円', 138, '125万円', 125, '2018(平成30)年', '2.8万km', NULL, '1600cc', 'なし', '車検整備付', 'https://picture1.goo-net.com/7000601492/30250214/Q/70006014923025021400100.jpg', 'https://www.goo-net.com/usedcar/spread/goo/15/700060149230250214001.html', 1, CURRENT_TIMESTAMP);
INSERT OR REPLACE INTO cars (id, shop_code, title, total_price_text, total_price_num, base_price_text, base_price_num, year, distance, distance_num, displacement, repair_history, shaken, image_url, detail_url, is_active, updated_at)
VALUES ('700060149230260406003', '0601492', 'タント ファンクロス 試乗車 サンドベージュ バックカメラ オーディオレス 両側パワースライドドア ＬＥＤヘッドライト スマートアシスト シートヒーター スマートキー スペアキー ...', '167万円', 167, '161万円', 161, '2024(令和6)年', '123km', 0.0123, '660cc', 'なし', '2027(令和9)年8月', 'https://picture1.goo-net.com/7000601492/30260406/Q/70006014923026040600300.jpg', 'https://www.goo-net.com/usedcar/spread/goo/15/700060149230260406003.html', 1, CURRENT_TIMESTAMP);
INSERT OR REPLACE INTO cars (id, shop_code, title, total_price_text, total_price_num, base_price_text, base_price_num, year, distance, distance_num, displacement, repair_history, shaken, image_url, detail_url, is_active, updated_at)
VALUES ('700060149230260421001', '0601492', 'ルークス ハイウェイスター Ｇターボプロパイロットエディション 純正９インチナビ 地デジ Ｂｌｕｅｔｏｏｔｈ ＤＶＤ ＥＴＣ ドラレコ アラウンドビューモニター プロパイロット 両...', '130万円', 130, '119万円', 119, '2021(令和3)年', '3.7万km', NULL, '660cc', 'なし', '車検整備付', 'https://picture1.goo-net.com/7000601492/30260421/Q/70006014923026042100100.jpg', 'https://www.goo-net.com/usedcar/spread/goo/15/700060149230260421001.html', 1, CURRENT_TIMESTAMP);
INSERT OR REPLACE INTO cars (id, shop_code, title, total_price_text, total_price_num, base_price_text, base_price_num, year, distance, distance_num, displacement, repair_history, shaken, image_url, detail_url, is_active, updated_at)
VALUES ('700060149230240727002', '0601492', 'ムーヴキャンバス セオリーＧターボ 届出済未使用車 スマートアシスト ターボ ＬＥＤヘッドランプ フォグ 両側電動スライドドア オーディオレス バックカメラ シートヒーター スマー...', '165万円', 165, '155万円', 155, '2023(令和5)年', '10km', 0.001, '660cc', 'なし', '車検整備付', 'https://picture1.goo-net.com/7000601492/30240727/Q/70006014923024072700200.jpg', 'https://www.goo-net.com/usedcar/spread/goo/15/700060149230240727002.html', 1, CURRENT_TIMESTAMP);
