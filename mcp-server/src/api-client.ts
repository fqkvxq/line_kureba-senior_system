import axios, { AxiosRequestConfig } from 'axios';
import { configManager } from './config.js';
import { ApiResponse, ExecutionContext } from './types.js';

export class ApiClient {
  /**
   * 対象のサーバーとアカウントのコンテキストを解決してAPIを呼び出す
   */
  public static async call<T = any>(
    action: string,
    params: Record<string, any> = {},
    options: {
      serverId?: string;
      accountKey?: string;
      method?: 'GET' | 'POST';
    } = {}
  ): Promise<{ data: T; context: ExecutionContext }> {
    const { id: serverId, config: serverConfig } = configManager.getServer(options.serverId);
    const accountKey = options.accountKey || configManager.getActiveAccountKey() || serverConfig.defaultAccount || 'default';
    const method = options.method || 'POST';

    const headers: Record<string, string> = {
      'X-Line-Account': accountKey,
    };

    if (serverConfig.adminPassword) {
      headers['X-Admin-Password'] = serverConfig.adminPassword;
    }
    if (serverConfig.adminToken) {
      headers['X-Admin-Auth-Token'] = serverConfig.adminToken;
      headers['Authorization'] = `Bearer ${serverConfig.adminToken}`;
    }

    const context: ExecutionContext = {
      serverId,
      serverName: serverConfig.name,
      baseUrl: serverConfig.baseUrl,
      accountId: accountKey,
    };

    try {
      let response;
      const url = serverConfig.baseUrl;

      if (method === 'GET') {
        const getParams = {
          action,
          account: accountKey,
          ...params
        };
        response = await axios.get(url, {
          params: getParams,
          headers,
          timeout: 20000
        });
      } else {
        // POST (multipart/form-data または urlencoded / json)
        const formData = new URLSearchParams();
        formData.append('action', action);
        formData.append('account', accountKey);

        for (const [key, value] of Object.entries(params)) {
          if (value !== undefined && value !== null) {
            if (typeof value === 'object') {
              formData.append(key, JSON.stringify(value));
            } else {
              formData.append(key, String(value));
            }
          }
        }

        response = await axios.post(url, formData, {
          headers: {
            ...headers,
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          timeout: 20000
        });
      }

      const resData = response.data as ApiResponse<T>;

      if (resData && typeof resData === 'object' && resData.success === false) {
        throw new Error(resData.error || `APIエラー: ${action} に失敗しました`);
      }

      return {
        data: resData as T,
        context
      };
    } catch (error: any) {
      if (error.response) {
        const status = error.response.status;
        const msg = error.response.data?.error || error.response.statusText;
        throw new Error(`[${context.serverName} / ${context.accountId}] HTTP ${status} エラー: ${msg}`);
      }
      throw new Error(`[${context.serverName} / ${context.accountId}] 接続失敗 (${error.message})`);
    }
  }
}
