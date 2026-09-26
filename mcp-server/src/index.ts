// @ts-ignore
import { runPreset } from '../presets/runner.js';
import fs from 'fs';
import path from 'path';
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
  Tool
} from '@modelcontextprotocol/sdk/types.js';
import { configManager } from './config.js';
import { ApiClient } from './api-client.js';

// MCP Server インスタンス生成
const server = new Server(
  {
    name: 'line-kureba-manager',
    version: '1.0.0',
  },
  {
    capabilities: {
      tools: {},
    },
  }
);

// ツール一覧定義
const TOOLS: Tool[] = [
    // --- 4. リッチメニュー・プリセット管理 ---
  {
    name: 'list_richmenu_presets',
    description: '利用可能なリッチメニュープリセット一覧（三島天気メニュー、カスタムテンプレートなど）を取得します。',
    inputSchema: {
      type: 'object',
      properties: {},
    },
  },
  {
    name: 'apply_richmenu_preset',
    description: '指定したプリセット（例: mishima_weather で三島の天気メニュー）を実行し、リッチメニューを生成・LINE公式アカウントに即時適用します。',
    inputSchema: {
      type: 'object',
      properties: {
        presetName: {
          type: 'string',
          description: '適用するプリセット名（デフォルト: mishima_weather）',
        },
      },
    },
  },
  // --- 1. サーバー & LINE公式アカウント管理 ---
  {
    name: 'list_servers',
    description: '登録されている顧客サーバー（企業・環境）一覧と現在の選択状態を取得します。',
    inputSchema: {
      type: 'object',
      properties: {},
    },
  },
  {
    name: 'list_accounts',
    description: '指定したサーバー（または現在選択中のサーバー）に登録されているLINE公式アカウント（店舗・部署など）一覧を取得します。',
    inputSchema: {
      type: 'object',
      properties: {
        serverId: {
          type: 'string',
          description: '対象のサーバーID（省略時は現在のアクティブサーバー）',
        },
      },
    },
  },
  {
    name: 'switch_context',
    description: 'AIが操作する対象の「サーバー」および「LINE公式アカウント」を切り替えます。',
    inputSchema: {
      type: 'object',
      properties: {
        serverId: {
          type: 'string',
          description: '切り替え先のサーバーID',
        },
        accountId: {
          type: 'string',
          description: '切り替え先のLINE公式アカウントID/キー（省略時はそのサーバーのデフォルトアカウント）',
        },
      },
    },
  },
  {
    name: 'get_current_context',
    description: '現在選択されているサーバーおよびLINE公式アカウントの情報を取得します。',
    inputSchema: {
      type: 'object',
      properties: {},
    },
  },

  // --- 2. 顧客 & 車両 & 点検管理 ---
  {
    name: 'search_customers',
    description: '顧客一覧を検索・取得します。名前、カナ、電話番号、ナンバープレート、車種、タグ等で絞り込み可能です。',
    inputSchema: {
      type: 'object',
      properties: {
        query: {
          type: 'string',
          description: '検索キーワード（顧客名、電話番号、車両名、ナンバーなど）',
        },
        tag: {
          type: 'string',
          description: '特定のタグで絞り込む場合',
        },
        serverId: {
          type: 'string',
          description: '対象サーバーID（省略時は現在選択中）',
        },
        accountId: {
          type: 'string',
          description: '対象LINE公式アカウントID（省略時は現在選択中）',
        },
      },
    },
  },
  {
    name: 'get_customer_detail',
    description: '指定した顧客のカルテ詳細（基本情報、所有車両一覧、車検/点検満了日、タグ、管理メモ、LINE状態）を取得します。',
    inputSchema: {
      type: 'object',
      properties: {
        customerId: {
          type: 'string',
          description: '顧客ID（またはLINE User ID）',
        },
        serverId: {
          type: 'string',
          description: '対象サーバーID（省略時は現在選択中）',
        },
        accountId: {
          type: 'string',
          description: '対象LINE公式アカウントID（省略時は現在選択中）',
        },
      },
      required: ['customerId'],
    },
  },
  {
    name: 'get_due_inspections',
    description: '指定した年月に車検または定期点検（12ヶ月/6ヶ月点検等）の満了を迎える顧客・車両一覧を抽出します。リマインダー案内候補の確認に最適です。',
    inputSchema: {
      type: 'object',
      properties: {
        targetMonth: {
          type: 'string',
          description: '対象年月（例: "2026-10"、"2026-11"）。省略時は当月',
        },
        type: {
          type: 'string',
          description: '点検種別フィルター: "shaken" (車検), "tenken12" (12ヶ月点検), "tenken6" (6ヶ月点検), "all" (すべて)',
          enum: ['all', 'shaken', 'tenken12', 'tenken6'],
        },
        serverId: {
          type: 'string',
          description: '対象サーバーID（省略時は現在選択中）',
        },
        accountId: {
          type: 'string',
          description: '対象LINE公式アカウントID（省略時は現在選択中）',
        },
      },
    },
  },
  {
    name: 'save_customer',
    description: '顧客情報・車両情報・タグ・メモを登録または更新します。',
    inputSchema: {
      type: 'object',
      properties: {
        id: {
          type: 'string',
          description: '顧客ID（新規作成時は未指定）',
        },
        name: {
          type: 'string',
          description: '顧客氏名',
        },
        phone: {
          type: 'string',
          description: '電話番号',
        },
        notes: {
          type: 'string',
          description: '管理者メモ',
        },
        tags: {
          type: 'array',
          items: { type: 'string' },
          description: 'タグ一覧（例: ["VIP", "輸入車"]）',
        },
        cars: {
          type: 'array',
          items: {
            type: 'object',
            properties: {
              car_name: { type: 'string', description: '車種名（例: プリウス）' },
              car_number: { type: 'string', description: '車両ナンバー（例: 名古屋 300 あ 1234）' },
              shaken_expiry: { type: 'string', description: '車検満了日（YYYY-MM-DD）' },
              inspection_date: { type: 'string', description: '次回点検日（YYYY-MM-DD）' },
            },
          },
          description: '所有車両情報リスト',
        },
        serverId: {
          type: 'string',
          description: '対象サーバーID（省略時は現在選択中）',
        },
        accountId: {
          type: 'string',
          description: '対象LINE公式アカウントID（省略時は現在選択中）',
        },
      },
      required: ['name'],
    },
  },
  {
    name: 'bulk_update_tags',
    description: '指定した顧客たちに対して、タグの一括追加または一括削除を行います。',
    inputSchema: {
      type: 'object',
      properties: {
        customerIds: {
          type: 'array',
          items: { type: 'string' },
          description: '対象の顧客IDリスト',
        },
        addTags: {
          type: 'array',
          items: { type: 'string' },
          description: '追加するタグリスト',
        },
        removeTags: {
          type: 'array',
          items: { type: 'string' },
          description: '削除するタグリスト',
        },
        serverId: {
          type: 'string',
          description: '対象サーバーID（省略時は現在選択中）',
        },
        accountId: {
          type: 'string',
          description: '対象LINE公式アカウントID（省略時は現在選択中）',
        },
      },
      required: ['customerIds'],
    },
  },

  // --- 3. LINE 1:1 チャット管理 ---
  {
    name: 'get_unread_chats',
    description: '現在未読メッセージがある顧客の一覧と、各顧客の未読件数を取得します。',
    inputSchema: {
      type: 'object',
      properties: {
        serverId: {
          type: 'string',
          description: '対象サーバーID（省略時は現在選択中）',
        },
        accountId: {
          type: 'string',
          description: '対象LINE公式アカウントID（省略時は現在選択中）',
        },
      },
    },
  },
  {
    name: 'get_chat_history',
    description: '指定した顧客（またはLINE User ID）とのチャット履歴（送受信メッセージ）を取得します。',
    inputSchema: {
      type: 'object',
      properties: {
        userId: {
          type: 'string',
          description: 'LINEユーザーID または 顧客ID',
        },
        limit: {
          type: 'number',
          description: '取得件数（デフォルト: 30件）',
        },
        serverId: {
          type: 'string',
          description: '対象サーバーID（省略時は現在選択中）',
        },
        accountId: {
          type: 'string',
          description: '対象LINE公式アカウントID（省略時は現在選択中）',
        },
      },
      required: ['userId'],
    },
  },
  {
    name: 'send_chat_message',
    description: '特定の顧客に対してLINE公式アカウントからメッセージを送信します。【誤操作防止】送信前にプレビュー確認する場合は dry_run: true を指定してください。',
    inputSchema: {
      type: 'object',
      properties: {
        userId: {
          type: 'string',
          description: '送信対象のLINEユーザーID または 顧客ID',
        },
        message: {
          type: 'string',
          description: '送信するテキストメッセージ',
        },
        dry_run: {
          type: 'boolean',
          description: 'true の場合は送信せず、送信対象と文面のプレビューのみを返却します（誤操作防止の安全確認用）',
        },
        serverId: {
          type: 'string',
          description: '対象サーバーID（省略時は現在選択中）',
        },
        accountId: {
          type: 'string',
          description: '対象LINE公式アカウントID（省略時は現在選択中）',
        },
      },
      required: ['userId', 'message'],
    },
  },

  // --- 4. リッチメニュー管理 ---
  {
    name: 'list_richmenus',
    description: '登録されているリッチメニュー一覧と、現在デフォルト適用されているメニューを取得します。',
    inputSchema: {
      type: 'object',
      properties: {
        serverId: {
          type: 'string',
          description: '対象サーバーID（省略時は現在選択中）',
        },
        accountId: {
          type: 'string',
          description: '対象LINE公式アカウントID（省略時は現在選択中）',
        },
      },
    },
  },
  {
    name: 'apply_richmenu',
    description: 'リッチメニューをアカウント全体のデフォルトに設定するか、特定顧客に個別割り当てします。',
    inputSchema: {
      type: 'object',
      properties: {
        richmenuId: {
          type: 'string',
          description: 'リッチメニューID（LINEのrichMenuIdまたは登録ID）',
        },
        targetUserId: {
          type: 'string',
          description: '特定顧客のみにアタッチする場合のLINEユーザーID（省略時はアカウント全体のデフォルトメニューに設定）',
        },
        serverId: {
          type: 'string',
          description: '対象サーバーID（省略時は現在選択中）',
        },
        accountId: {
          type: 'string',
          description: '対象LINE公式アカウントID（省略時は現在選択中）',
        },
      },
      required: ['richmenuId'],
    },
  },

  // --- 5. システムサマリ ---
  {
    name: 'get_system_summary',
    description: '指定アカウント（または全アカウント）の顧客総数、未読チャット数、車両登録数、直近の点検予定数などのサマリを取得します。',
    inputSchema: {
      type: 'object',
      properties: {
        serverId: {
          type: 'string',
          description: '対象サーバーID（省略時は現在選択中）',
        },
        accountId: {
          type: 'string',
          description: '対象LINE公式アカウントID（省略時は現在選択中）',
        },
      },
    },
  },
];

// Tools リストハンドラ
server.setRequestHandler(ListToolsRequestSchema, async () => {
  return { tools: TOOLS };
});

// Tool 実行ハンドラ
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args = {} } = request.params;

  try {
    switch (name) {
      // 1. list_servers
      case 'list_servers': {
        const servers = configManager.getServers();
        const activeServerId = configManager.getActiveServerId();
        const activeAccountKey = configManager.getActiveAccountKey();

        const serverList = Object.entries(servers).map(([id, s]) => ({
          serverId: id,
          name: s.name,
          baseUrl: s.baseUrl,
          defaultAccount: s.defaultAccount || 'default',
          isActive: id === activeServerId,
        }));

        return {
          content: [
            {
              type: 'text',
              text: JSON.stringify(
                {
                  activeContext: {
                    serverId: activeServerId,
                    accountId: activeAccountKey,
                  },
                  registeredServers: serverList,
                },
                null,
                2
              ),
            },
          ],
        };
      }

      // 2. list_accounts
      case 'list_accounts': {
        const serverId = (args.serverId as string) || configManager.getActiveServerId();
        const res = await ApiClient.call('get_accounts', {}, { serverId, method: 'GET' });
        return {
          content: [
            {
              type: 'text',
              text: JSON.stringify(
                {
                  _executedContext: res.context,
                  activeAccount: res.data.active_account,
                  accounts: res.data.accounts,
                },
                null,
                2
              ),
            },
          ],
        };
      }

      // 3. switch_context
      case 'switch_context': {
        const serverId = args.serverId as string;
        const accountId = args.accountId as string;

        let resultServer;
        let resultAccount;

        if (serverId) {
          resultServer = configManager.setActiveServer(serverId);
        }
        if (accountId) {
          resultAccount = configManager.setActiveAccount(accountId);
        }

        const currentServerId = configManager.getActiveServerId();
        const currentAccountKey = configManager.getActiveAccountKey();
        const currentServer = configManager.getServer(currentServerId);

        return {
          content: [
            {
              type: 'text',
              text: JSON.stringify(
                {
                  success: true,
                  message: `操作コンテキストを切り替えました。`,
                  currentContext: {
                    serverId: currentServerId,
                    serverName: currentServer.config.name,
                    accountId: currentAccountKey,
                  },
                },
                null,
                2
              ),
            },
          ],
        };
      }

      // 4. get_current_context
      case 'get_current_context': {
        const activeServerId = configManager.getActiveServerId();
        const activeAccountKey = configManager.getActiveAccountKey();
        const currentServer = configManager.getServer(activeServerId);

        return {
          content: [
            {
              type: 'text',
              text: JSON.stringify(
                {
                  currentContext: {
                    serverId: activeServerId,
                    serverName: currentServer.config.name,
                    baseUrl: currentServer.config.baseUrl,
                    accountId: activeAccountKey,
                  },
                },
                null,
                2
              ),
            },
          ],
        };
      }

      // 5. search_customers
      case 'search_customers': {
        const serverId = args.serverId as string;
        const accountId = args.accountId as string;
        const query = (args.query as string) || '';
        const tag = (args.tag as string) || '';

        const res = await ApiClient.call(
          'admin_list_customers',
          { q: query, tag, limit: 100 },
          { serverId, accountKey: accountId, method: 'GET' }
        );

        const customers = (res.data.customers || []).map((c: any) => ({
          id: c.id,
          name: c.name,
          phone: c.phone,
          line_user_id: c.line_user_id,
          line_display_name: c.line_display_name,
          tags: c.tags || [],
          cars_count: c.cars ? c.cars.length : 0,
          cars: (c.cars || []).map((car: any) => ({
            car_name: car.car_name,
            car_number: car.car_number,
            shaken_expiry: car.shaken_expiry,
            inspection_date: car.inspection_date,
          })),
          last_message_at: c.last_message_at,
          unread_count: c.unread_count || 0,
        }));

        return {
          content: [
            {
              type: 'text',
              text: JSON.stringify(
                {
                  _executedContext: res.context,
                  totalFound: customers.length,
                  customers,
                },
                null,
                2
              ),
            },
          ],
        };
      }

      // 6. get_customer_detail
      case 'get_customer_detail': {
        const serverId = args.serverId as string;
        const accountId = args.accountId as string;
        const customerId = args.customerId as string;

        const res = await ApiClient.call(
          'admin_list_customers',
          { id: customerId },
          { serverId, accountKey: accountId, method: 'GET' }
        );

        const customer = (res.data.customers || []).find(
          (c: any) => String(c.id) === String(customerId) || c.line_user_id === customerId
        );

        if (!customer) {
          throw new Error(`顧客ID [${customerId}] が見つかりませんでした。`);
        }

        return {
          content: [
            {
              type: 'text',
              text: JSON.stringify(
                {
                  _executedContext: res.context,
                  customer,
                },
                null,
                2
              ),
            },
          ],
        };
      }

      // 7. get_due_inspections
      case 'get_due_inspections': {
        const serverId = args.serverId as string;
        const accountId = args.accountId as string;
        const targetMonth = (args.targetMonth as string) || new Date().toISOString().slice(0, 7);
        const filterType = (args.type as string) || 'all';

        const res = await ApiClient.call(
          'admin_list_customers',
          { limit: 500 },
          { serverId, accountKey: accountId, method: 'GET' }
        );

        const customers = res.data.customers || [];
        const dueList: any[] = [];

        customers.forEach((c: any) => {
          (c.cars || []).forEach((car: any) => {
            const shakenMatch = car.shaken_expiry && car.shaken_expiry.startsWith(targetMonth);
            const inspectionMatch = car.inspection_date && car.inspection_date.startsWith(targetMonth);

            if (filterType === 'all' || filterType === 'shaken') {
              if (shakenMatch) {
                dueList.push({
                  customerId: c.id,
                  customerName: c.name,
                  lineUserId: c.line_user_id,
                  phone: c.phone,
                  type: '車検',
                  carName: car.car_name,
                  carNumber: car.car_number,
                  dueDate: car.shaken_expiry,
                });
              }
            }
            if (filterType === 'all' || filterType !== 'shaken') {
              if (inspectionMatch) {
                dueList.push({
                  customerId: c.id,
                  customerName: c.name,
                  lineUserId: c.line_user_id,
                  phone: c.phone,
                  type: '定期点検',
                  carName: car.car_name,
                  carNumber: car.car_number,
                  dueDate: car.inspection_date,
                });
              }
            }
          });
        });

        return {
          content: [
            {
              type: 'text',
              text: JSON.stringify(
                {
                  _executedContext: res.context,
                  targetMonth,
                  filterType,
                  totalDue: dueList.length,
                  inspections: dueList,
                },
                null,
                2
              ),
            },
          ],
        };
      }

      // 8. save_customer
      case 'save_customer': {
        const serverId = args.serverId as string;
        const accountId = args.accountId as string;

        const payload: Record<string, any> = {
          name: args.name,
          phone: args.phone || '',
          notes: args.notes || '',
        };
        if (args.id) payload.id = args.id;
        if (args.tags) payload.tags = args.tags;
        if (args.cars) payload.cars = args.cars;

        const res = await ApiClient.call(
          'admin_save_customer',
          payload,
          { serverId, accountKey: accountId, method: 'POST' }
        );

        return {
          content: [
            {
              type: 'text',
              text: JSON.stringify(
                {
                  _executedContext: res.context,
                  success: true,
                  message: '顧客情報を正常に保存・更新しました。',
                  savedCustomer: res.data.customer || res.data,
                },
                null,
                2
              ),
            },
          ],
        };
      }

      // 9. bulk_update_tags
      case 'bulk_update_tags': {
        const serverId = args.serverId as string;
        const accountId = args.accountId as string;
        const customerIds = args.customerIds as string[];
        const addTags = (args.addTags as string[]) || [];
        const removeTags = (args.removeTags as string[]) || [];

        const res = await ApiClient.call(
          'admin_bulk_update_tags',
          {
            customer_ids: customerIds,
            add_tags: addTags,
            remove_tags: removeTags,
          },
          { serverId, accountKey: accountId, method: 'POST' }
        );

        return {
          content: [
            {
              type: 'text',
              text: JSON.stringify(
                {
                  _executedContext: res.context,
                  success: true,
                  affectedCount: customerIds.length,
                  result: res.data,
                },
                null,
                2
              ),
            },
          ],
        };
      }

      // 10. get_unread_chats
      case 'get_unread_chats': {
        const serverId = args.serverId as string;
        const accountId = args.accountId as string;

        const res = await ApiClient.call(
          'get_unread_chat_counts',
          {},
          { serverId, accountKey: accountId, method: 'GET' }
        );

        return {
          content: [
            {
              type: 'text',
              text: JSON.stringify(
                {
                  _executedContext: res.context,
                  unreadCounts: res.data.counts || res.data,
                },
                null,
                2
              ),
            },
          ],
        };
      }

      // 11. get_chat_history
      case 'get_chat_history': {
        const serverId = args.serverId as string;
        const accountId = args.accountId as string;
        const userId = args.userId as string;
        const limit = Number(args.limit) || 30;

        const res = await ApiClient.call(
          'get_chat_messages',
          { user_id: userId, limit },
          { serverId, accountKey: accountId, method: 'GET' }
        );

        return {
          content: [
            {
              type: 'text',
              text: JSON.stringify(
                {
                  _executedContext: res.context,
                  userId,
                  messagesCount: (res.data.messages || []).length,
                  messages: res.data.messages || [],
                },
                null,
                2
              ),
            },
          ],
        };
      }

      // 12. send_chat_message (誤操作防止プレビュー対応)
      case 'send_chat_message': {
        const serverId = args.serverId as string;
        const accountId = args.accountId as string;
        const userId = args.userId as string;
        const message = args.message as string;
        const dryRun = Boolean(args.dry_run);

        const { id: sId, config: sConf } = configManager.getServer(serverId);
        const actKey = accountId || configManager.getActiveAccountKey() || sConf.defaultAccount || 'default';

        if (dryRun) {
          return {
            content: [
              {
                type: 'text',
                text: JSON.stringify(
                  {
                    _isDryRun: true,
                    _targetContext: {
                      serverId: sId,
                      serverName: sConf.name,
                      accountId: actKey,
                    },
                    status: 'PREVIEW_ONLY',
                    targetUserId: userId,
                    messagePreview: message,
                    notice: '【ドライラン（確認モード）】実際のメッセージ送信は行われていません。送信を実行するには dry_run: false で再度呼び出してください。',
                  },
                  null,
                  2
                ),
              },
            ],
          };
        }

        const res = await ApiClient.call(
          'send_chat_message',
          {
            user_id: userId,
            message: message,
          },
          { serverId, accountKey: accountId, method: 'POST' }
        );

        return {
          content: [
            {
              type: 'text',
              text: JSON.stringify(
                {
                  _executedContext: res.context,
                  success: true,
                  message: 'LINEメッセージを正常に送信しました。',
                  sentTo: userId,
                  sentMessage: message,
                },
                null,
                2
              ),
            },
          ],
        };
      }

      // 13. list_richmenus
      case 'list_richmenus': {
        const serverId = args.serverId as string;
        const accountId = args.accountId as string;

        const res = await ApiClient.call(
          'admin_list_richmenus',
          {},
          { serverId, accountKey: accountId, method: 'GET' }
        );

        return {
          content: [
            {
              type: 'text',
              text: JSON.stringify(
                {
                  _executedContext: res.context,
                  richmenus: res.data.richmenus || [],
                  activeNotice: res.data.active_notice,
                },
                null,
                2
              ),
            },
          ],
        };
      }

      // 14. apply_richmenu
      case 'apply_richmenu': {
        const serverId = args.serverId as string;
        const accountId = args.accountId as string;
        const richmenuId = args.richmenuId as string;
        const targetUserId = args.targetUserId as string;

        let res;
        if (targetUserId) {
          // 個人ユーザーアタッチ
          res = await ApiClient.call(
            'admin_assign_richmenu_to_user',
            { user_id: targetUserId, richmenu_id: richmenuId },
            { serverId, accountKey: accountId, method: 'POST' }
          );
        } else {
          // 全体適用
          res = await ApiClient.call(
            'admin_apply_richmenu',
            { richmenu_id: richmenuId },
            { serverId, accountKey: accountId, method: 'POST' }
          );
        }

        return {
          content: [
            {
              type: 'text',
              text: JSON.stringify(
                {
                  _executedContext: res.context,
                  success: true,
                  appliedRichmenuId: richmenuId,
                  targetUser: targetUserId || 'ALL_FOLLOWERS_DEFAULT',
                },
                null,
                2
              ),
            },
          ],
        };
      }

      // 15. get_system_summary
      case 'get_system_summary': {
        const serverId = args.serverId as string;
        const accountId = args.accountId as string;

        // 顧客一覧と未読一覧をまとめて取得
        const [custRes, unreadRes] = await Promise.all([
          ApiClient.call('admin_list_customers', { limit: 1000 }, { serverId, accountKey: accountId, method: 'GET' }),
          ApiClient.call('get_unread_chat_counts', {}, { serverId, accountKey: accountId, method: 'GET' }).catch(() => ({ data: { counts: {} }, context: null })),
        ]);

        const customers = custRes.data.customers || [];
        let totalCars = 0;
        let blockedCount = 0;
        const thisMonth = new Date().toISOString().slice(0, 7);
        let dueShakenThisMonth = 0;

        customers.forEach((c: any) => {
          if (c.is_blocked) blockedCount++;
          (c.cars || []).forEach((car: any) => {
            totalCars++;
            if (car.shaken_expiry && car.shaken_expiry.startsWith(thisMonth)) {
              dueShakenThisMonth++;
            }
          });
        });

        return {
          content: [
            {
              type: 'text',
              text: JSON.stringify(
                {
                  _executedContext: custRes.context,
                  summary: {
                    totalCustomers: customers.length,
                    totalCars,
                    blockedCount,
                    dueShakenThisMonth,
                    unreadChats: unreadRes.data?.counts || {},
                  },
                },
                null,
                2
              ),
            },
          ],
        };
      }

      default:
        throw new Error(`未対応のTool: ${name}`);
    }
  } catch (error: any) {
    return {
      isError: true,
      content: [
        {
          type: 'text',
          text: `[MCPエラー] ${error.message || String(error)}`,
        },
      ],
    };
  }
});

// 起動
async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error('[line-kureba-mcp-server] MCP Server running on stdio');
}

main().catch((err) => {
  console.error('[line-kureba-mcp-server] Fatal error:', err);
  process.exit(1);
});
