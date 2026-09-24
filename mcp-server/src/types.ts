export interface ServerConfig {
  name: string;
  baseUrl: string; // e.g. "https://example.com/api.php" or "http://localhost:8000/api.php"
  adminPassword?: string;
  adminToken?: string;
  defaultAccount?: string;
  description?: string;
}

export interface ServersConfigFile {
  default_server?: string;
  servers: Record<string, ServerConfig>;
}

export interface ExecutionContext {
  serverId: string;
  serverName: string;
  baseUrl: string;
  accountId: string;
  accountName?: string;
}

export interface ApiResponse<T = any> {
  success: boolean;
  error?: string;
  data?: T;
  [key: string]: any;
}
