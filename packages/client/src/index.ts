import type { components } from './schema';
export type Charge = components['schemas']['Charge'];
export type Property = components['schemas']['Property'];
export type Contract = components['schemas']['Contract'];
export type Ticket = components['schemas']['Ticket'];
/** Mobile transport. Keep tokens in OS secure storage; reauthenticate after HTTP 401. */
export class HomeAloneClient {
  constructor(private baseUrl: string, private token: () => Promise<string>, private companyId: string) {}
  async request<T>(path: string, init: RequestInit = {}): Promise<T> {
    const response = await fetch(`${this.baseUrl}/api/v1${path}`, { ...init, headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${await this.token()}`, 'X-Company-Id': this.companyId, ...init.headers } });
    if (!response.ok) throw new Error((await response.json()).message || 'Pedido não concluído');
    return response.json();
  }
  charges(period?: string) { return this.request<Charge[]>(`/charges${period ? `?period=${encodeURIComponent(period)}` : ''}`); }
  properties() { return this.request<Property[]>('/properties'); }
  contracts() { return this.request<Contract[]>('/contracts'); }
  tickets() { return this.request<Ticket[]>('/tickets'); }
}
