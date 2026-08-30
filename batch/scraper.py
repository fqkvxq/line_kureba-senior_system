#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
グーネット特定店舗 車両情報スクレイピング & SQLite同期スクリプト (Python版)
Xserver等のCronで定期実行 (例: 0 6,12,18 * * *) して使用できます。
"""

import sys
import os
import re
import time
import sqlite3
import urllib.request
from datetime import datetime

# 設定
SHOP_CODE = "0601492"
BASE_URL = f"https://www.goo-net.com/usedcar_shop/{SHOP_CODE}/"
DB_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "cars.db")
USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"

def init_db(conn):
    cursor = conn.cursor()
    cursor.execute("""
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
        )
    """)
    cursor.execute("CREATE INDEX IF NOT EXISTS idx_cars_active ON cars(is_active)")
    cursor.execute("CREATE INDEX IF NOT EXISTS idx_cars_price ON cars(total_price_num)")
    cursor.execute("CREATE INDEX IF NOT EXISTS idx_cars_title ON cars(title)")
    conn.commit()

def fetch_html(url):
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    try:
        with urllib.request.urlopen(req, timeout=20) as res:
            raw = res.read()
            # EUC-JP / UTF-8 デコード
            for enc in ["euc-jp", "utf-8", "cp932"]:
                try:
                    return raw.decode(enc)
                except UnicodeDecodeError:
                    continue
            return raw.decode("euc-jp", errors="replace")
    except Exception as e:
        print(f"Fetch error ({url}): {e}")
        return None

def parse_cars_from_html(html, shop_code):
    cars = {}
    # 車両ブロック: div.box_item_detail
    pattern = re.compile(r'<div class="box_item_detail[^"]*" id="tr_([^"]+)"[^>]*>(.*?)<!--// \.application -->', re.DOTALL)
    matches = pattern.findall(html)

    for car_id, block in matches:
        car_id = car_id.strip()

        # タイトル
        title = ""
        t_match = re.search(r'<h3 class="car_box_title">.*?<a[^>]*>(.*?)</a>', block, re.DOTALL)
        if t_match:
            title = re.sub(r'<[^>]+>', '', t_match.group(1))
            title = re.sub(r'\s+', ' ', title).strip()

        # 詳細URL
        detail_url = f"https://www.goo-net.com/usedcar/spread/goo/15/{car_id}.html"

        # 画像URL
        image_url = ""
        i_match = re.search(r'src="(https://picture1\.goo-net\.com/[^"]+)"', block)
        if i_match:
            image_url = i_match.group(1)

        # 支払総額
        total_price_text = ""
        total_price_num = None
        p_match = re.search(r'<div class="priceAllNum"><em>(.*?)</em><span>(.*?)</span>', block, re.DOTALL)
        if p_match:
            num_str = re.sub(r'<[^>]+>', '', p_match.group(1)).strip()
            unit_str = re.sub(r'<[^>]+>', '', p_match.group(2)).strip()
            total_price_text = f"{num_str}{unit_str}"
            try:
                total_price_num = float(num_str)
            except ValueError:
                pass

        # 本体価格
        base_price_text = ""
        base_price_num = None
        b_match = re.search(r'<p class="car">.*?<em>(.*?)</em><span>(.*?)</span>', block, re.DOTALL)
        if b_match:
            b_num = re.sub(r'<[^>]+>', '', b_match.group(1)).strip()
            b_unit = re.sub(r'<[^>]+>', '', b_match.group(2)).strip()
            base_price_text = f"{b_num}{b_unit}"
            try:
                base_price_num = float(b_num)
            except ValueError:
                pass

        # スペック (年式, 走行距離, 排気量, 修復歴, 車検)
        year = ""
        distance = ""
        distance_num = None
        displacement = ""
        repair_history = ""
        shaken = ""

        table_match = re.search(r'<table>.*?<tr>(.*?)</tr>.*?</table>', block, re.DOTALL)
        if table_match:
            tds = re.findall(r'<td[^>]*>(.*?)</td>', table_match.group(1), re.DOTALL)
            if len(tds) >= 6:
                year = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', '', tds[1])).strip()
                distance = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', '', tds[2])).strip()
                displacement = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', '', tds[3])).strip()
                repair_history = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', '', tds[4])).strip()
                shaken = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', '', tds[5])).strip()

                d_m = re.search(r'([0-9\.]+)\s*万km', distance)
                if d_m:
                    distance_num = float(d_m.group(1))
                else:
                    d_k = re.search(r'([0-9,]+)\s*km', distance)
                    if d_k:
                        distance_num = float(d_k.group(1).replace(',', '')) / 10000.0

        cars[car_id] = {
            "id": car_id,
            "shop_code": shop_code,
            "title": title,
            "total_price_text": total_price_text,
            "total_price_num": total_price_num,
            "base_price_text": base_price_text,
            "base_price_num": base_price_num,
            "year": year,
            "distance": distance,
            "distance_num": distance_num,
            "displacement": displacement,
            "repair_history": repair_history,
            "shaken": shaken,
            "image_url": image_url,
            "detail_url": detail_url,
        }

    return cars

def main():
    now_str = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    print(f"[{now_str}] === グーネット車両データ同期処理を開始します (Python版) ===")
    print(f"対象店舗コード: {SHOP_CODE}")
    print(f"データベース: {DB_FILE}")

    conn = sqlite3.connect(DB_FILE)
    init_db(conn)

    page = 1
    all_cars = {}

    while True:
        target_url = f"{BASE_URL}stock.html" if page == 1 else f"{BASE_URL}stock_{page}.html"
        print(f"ページ取得中: {target_url} ...")
        html = fetch_html(target_url)
        if not html:
            break

        cars = parse_cars_from_html(html, SHOP_CODE)
        if not cars:
            print("車両データが見つかりませんでした。巡回を終了します。")
            break

        print(f"  -> {len(cars)} 台の車両データを抽出しました。")
        all_cars.update(cars)

        if len(cars) < 20:
            break
        page += 1
        time.sleep(0.5)

    total_fetched = len(all_cars)
    print(f"合計取得台数: {total_fetched} 台")

    if total_fetched == 0:
        print("データが取得できなかったため終了します。")
        conn.close()
        return

    cursor = conn.cursor()
    # UPSERT
    for car in all_cars.values():
        cursor.execute("""
            INSERT INTO cars (
                id, shop_code, title, total_price_text, total_price_num,
                base_price_text, base_price_num, year, distance, distance_num,
                displacement, repair_history, shaken, image_url, detail_url,
                is_active, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP)
            ON CONFLICT(id) DO UPDATE SET
                title = excluded.title,
                total_price_text = excluded.total_price_text,
                total_price_num = excluded.total_price_num,
                base_price_text = excluded.base_price_text,
                base_price_num = excluded.base_price_num,
                year = excluded.year,
                distance = excluded.distance,
                distance_num = excluded.distance_num,
                displacement = excluded.displacement,
                repair_history = excluded.repair_history,
                shaken = excluded.shaken,
                image_url = excluded.image_url,
                detail_url = excluded.detail_url,
                is_active = 1,
                updated_at = CURRENT_TIMESTAMP
        """, (
            car["id"], car["shop_code"], car["title"],
            car["total_price_text"], car["total_price_num"],
            car["base_price_text"], car["base_price_num"],
            car["year"], car["distance"], car["distance_num"],
            car["displacement"], car["repair_history"], car["shaken"],
            car["image_url"], car["detail_url"]
        ))

    # 売約済み更新
    current_ids = list(all_cars.keys())
    placeholders = ",".join(["?"] * len(current_ids))
    cursor.execute(f"""
        UPDATE cars SET is_active = 0, updated_at = CURRENT_TIMESTAMP
        WHERE shop_code = ? AND id NOT IN ({placeholders})
    """, [SHOP_CODE] + current_ids)

    conn.commit()
    conn.close()
    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] データベース同期が完了しました！")

if __name__ == "__main__":
    main()
