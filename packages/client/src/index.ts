import type { components } from './schema';

export type Charge = components['schemas']['Charge'];
export type Property = components['schemas']['Property'];
export type Contract = components['schemas']['Contract'];
export type Ticket = components['schemas']['Ticket'];
export type Workspace = { id: string; name: string; role: string };
export type Identity = { user: { id: string; name: string; email: string }; companies: Workspace[] };
export type MobileSession = { token: string; expires_in: number };
export type PublicConfig = { demo: boolean; public_demo: boolean };
export type Conversation = { id: string; subject: string; last_message: string | null; unread: number };
export type Message = { id: string; body: string; author_id: string; author_name: string; created_at: string };

export class ApiError extends Error {
  constructor(readonly status: number, message: string) {
    super(message);
    this.name = 'ApiError';
  }
}

async function jsonRequest<T>(baseUrl: string, path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  if (init.body) headers.set('Content-Type', 'application/json');
  const response = await fetch(`${baseUrl.replace(/\/$/, '')}/api/v1${path}`, {
    ...init, headers, credentials: 'omit',
  });
  if (!response.ok) {
    const body = await response.json().catch(() => null);
    throw new ApiError(response.status, body?.message || 'Pedido não concluído');
  }
  return response.json() as Promise<T>;
}

export function publicConfig(baseUrl: string) {
  return jsonRequest<PublicConfig>(baseUrl, '/config');
}

export function mobileLogin(baseUrl: string, email: string, password?: string) {
  const demo = password === undefined;
  return jsonRequest<MobileSession>(baseUrl, demo ? '/auth/demo' : '/auth/login', {
    method: 'POST', body: JSON.stringify(demo ? { email, client: 'resident-app' } : { email, password, client: 'resident-app' }),
  });
}

/** Mobile transport. Keep tokens in OS secure storage; reauthenticate after HTTP 401. */
export class HomeAloneClient {
  constructor(private baseUrl: string, private token: () => Promise<string>, private companyId: string) {}

  async request<T>(path: string, init: RequestInit = {}): Promise<T> {
    const headers = new Headers(init.headers);
    headers.set('Content-Type', 'application/json');
    headers.set('Authorization', `Bearer ${await this.token()}`);
    headers.set('X-Company-Id', this.companyId);
    return jsonRequest<T>(this.baseUrl, path, { ...init, headers });
  }

  identity() { return this.request<Identity>('/auth/me'); }
  logout() { return this.request<{ ok: boolean }>('/auth/logout', { method: 'POST' }); }
  charges(period?: string) { return this.request<Charge[]>(`/charges${period ? `?period=${encodeURIComponent(period)}` : ''}`); }
  properties() { return this.request<Property[]>('/properties'); }
  contracts() { return this.request<Contract[]>('/contracts'); }
  tickets() { return this.request<Ticket[]>('/tickets'); }
  conversations() { return this.request<Conversation[]>('/conversations'); }
  messages(id: string) { return this.request<Message[]>(`/conversations/${encodeURIComponent(id)}/messages`); }
}
