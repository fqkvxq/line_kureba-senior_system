import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { ServersConfigFile, ServerConfig } from './types.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// プロジェクトルート（mcp-server）内の servers.json または環境変数で指定されたパス
const CONFIG_PATH = process.env.KUREBA_SERVERS_CONFIG || path.resolve(__dirname, '../servers.json');
const SAMPLE_CONFIG_PATH = path.resolve(__dirname, '../servers.json.sample');

export class ConfigManager {
  private config: ServersConfigFile = { servers: {} };
  private activeServerId: string = '';
  private activeAccountKey: string = '';

  constructor() {
    this.loadConfig();
  }

  public loadConfig(): void {
    if (fs.existsSync(CONFIG_PATH)) {
      try {
        const raw = fs.readFileSync(CONFIG_PATH, 'utf-8');
        this.config = JSON.parse(raw);
      } catch (err) {
        console.error(`[ConfigManager] Failed to parse ${CONFIG_PATH}:`, err);
      }
    } else if (fs.existsSync(SAMPLE_CONFIG_PATH)) {
      try {
        const raw = fs.readFileSync(SAMPLE_CONFIG_PATH, 'utf-8');
        this.config = JSON.parse(raw);
      } catch (err) {
        console.error(`[ConfigManager] Failed to parse ${SAMPLE_CONFIG_PATH}:`, err);
      }
    } else {
      // デフォルトフォールバック（ローカル開発環境）
      this.config = {
        default_server: 'local',
        servers: {
          local: {
            name: 'ローカル開発環境',
            baseUrl: 'http://localhost/line_kureba-senior_system/public_html/api.php',
            adminPassword: 'admin',
            defaultAccount: 'default'
          }
        }
      };
    }

    // デフォルトサーバーの決定
    const serverKeys = Object.keys(this.config.servers || {});
    if (this.config.default_server && this.config.servers[this.config.default_server]) {
      this.activeServerId = this.config.default_server;
    } else if (serverKeys.length > 0) {
      this.activeServerId = serverKeys[0];
    }

    // デフォルトアカウントの決定
    if (this.activeServerId && this.config.servers[this.activeServerId]) {
      this.activeAccountKey = this.config.servers[this.activeServerId].defaultAccount || 'default';
    }
  }

  public saveConfig(): void {
    try {
      fs.writeFileSync(CONFIG_PATH, JSON.stringify(this.config, null, 2), 'utf-8');
    } catch (err) {
      console.error(`[ConfigManager] Failed to write ${CONFIG_PATH}:`, err);
    }
  }

  public getServers(): Record<string, ServerConfig> {
    this.loadConfig();
    return this.config.servers || {};
  }

  public getServer(serverId?: string): { id: string; config: ServerConfig } {
    this.loadConfig();
    const id = serverId || this.activeServerId;
    const server = this.config.servers[id];
    if (!server) {
      const available = Object.keys(this.config.servers).join(', ');
      throw new Error(`サーバー [${id}] が登録されていません。利用可能サーバー: [${available}]`);
    }
    return { id, config: server };
  }

  public getActiveServerId(): string {
    return this.activeServerId;
  }

  public getActiveAccountKey(): string {
    return this.activeAccountKey;
  }

  public setActiveServer(serverId: string): { serverId: string; serverName: string; defaultAccount: string } {
    const { id, config } = this.getServer(serverId);
    this.activeServerId = id;
    this.activeAccountKey = config.defaultAccount || 'default';
    return {
      serverId: id,
      serverName: config.name,
      defaultAccount: this.activeAccountKey
    };
  }

  public setActiveAccount(accountKey: string): { serverId: string; accountKey: string } {
    this.activeAccountKey = accountKey;
    return {
      serverId: this.activeServerId,
      accountKey: this.activeAccountKey
    };
  }

  public addOrUpdateServer(serverId: string, config: ServerConfig): void {
    this.config.servers[serverId] = config;
    this.saveConfig();
  }
}

export const configManager = new ConfigManager();
