import axios from 'axios';

async function test() {
  const url = 'https://kureba.co.jp/line_kureba-senior_system/public_html/api.php';
  const passwords = [
    'KrbSenior_Sec2026!x9Wq$8mP#LvK24r',
    '1020143'
  ];

  for (const pass of passwords) {
    try {
      console.log(`\n--- テスト実行 (パスワード: ${pass.slice(0, 10)}...) ---`);
      const res = await axios.get(url, {
        params: { action: 'get_accounts', account: 'senior' },
        headers: {
          'X-Admin-Password': pass,
          'X-Line-Account': 'senior'
        },
        timeout: 10000
      });

      console.log(`[成功] HTTP ${res.status}`);
      console.log(`success: ${res.data.success}`);
      console.log(`active_account: ${res.data.active_account}`);
      console.log(`accounts:`, res.data.accounts?.map(a => `${a.name} (${a.id})`));

      // 顧客一覧APIもテスト
      const custRes = await axios.get(url, {
        params: { action: 'admin_list_customers', account: 'senior', limit: 3 },
        headers: {
          'X-Admin-Password': pass,
          'X-Line-Account': 'senior'
        },
        timeout: 10000
      });
      console.log(`顧客取得テスト: success=${custRes.data.success}, total=${custRes.data.total || custRes.data.customers?.length}`);
    } catch (err) {
      console.log(`[失敗] ${err.message}`);
      if (err.response) {
        console.log(`ステータス: ${err.response.status}`);
        console.log(`レスポンス:`, err.response.data);
      }
    }
  }
}

test();
