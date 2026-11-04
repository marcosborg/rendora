export type Row = Record<string, any>;
let company = ''; let csrf = '';
export function sessionContext(companyId: string, token: string) { company = companyId; csrf = token; }
export async function api<T = any>(path: string, method = 'GET', data?: unknown): Promise<T> {
  const multipart = data instanceof FormData;
  const response = await fetch(`/api/v1${path}`, { method, credentials: 'same-origin', headers: { ...(multipart ? {} : { 'Content-Type': 'application/json' }), 'X-Company-Id': company, 'X-CSRF-Token': csrf }, body: data === undefined ? undefined : multipart ? data : JSON.stringify(data) });
  if (!response.ok) { const error = await response.json().catch(() => ({})); throw new Error(error.message || 'Não foi possível concluir o pedido.'); }
  return response.json();
}
export async function download(path: string, filename: string) {
  const response = await fetch(`/api/v1${path}`, { headers: { 'X-Company-Id': company }, credentials: 'same-origin' });
  if (!response.ok) throw new Error((await response.json()).message || 'Não foi possível descarregar.');
  const url = URL.createObjectURL(await response.blob()); const a = document.createElement('a'); a.href = url; a.download = filename; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
}
export const money = (cents: number = 0) => new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR', maximumFractionDigits: 2 }).format(cents / 100);
export const date = (value?: string) => value ? new Intl.DateTimeFormat('pt-PT').format(new Date(value.slice(0, 10) + 'T12:00:00')) : 'Sem data';
export const monthLabel = (value: string) => new Intl.DateTimeFormat('pt-PT', { month: 'long', year: 'numeric' }).format(new Date(value + '-01T12:00:00'));
export const initials = (value = '') => value.split(' ').filter(Boolean).slice(0, 2).map(x => x[0]).join('').toUpperCase();
export const uid = () => crypto.randomUUID();
export const labels: Record<string, string> = { ADMIN: 'Administrador', MANAGER: 'Gestor', FINANCE: 'Financeiro', TECHNICIAN: 'Técnico', OWNER: 'Proprietário', RESIDENT: 'Morador', LEAD: 'Interessado', PROVIDER: 'Prestador', BUILDING: 'Edifício', APARTMENT: 'Apartamento', ROOM: 'Quarto', COMMON: 'Área comum', ACTIVE: 'Ativo', ENDED: 'Terminado', CANCELLED: 'Cancelado', NEW: 'Novo', TRIAGE: 'Em triagem', SCHEDULED: 'Agendado', IN_PROGRESS: 'Em execução', WAITING: 'A aguardar', RESOLVED: 'Resolvido', CLOSED: 'Encerrado', LOW: 'Baixa', NORMAL: 'Normal', HIGH: 'Alta', URGENT: 'Urgente', PLUMBING: 'Canalização', ELECTRICITY: 'Eletricidade', APPLIANCE: 'Equipamentos', CLEANING: 'Limpeza', OTHER: 'Outro', PENDING: 'Pendente', PROCESSING: 'Em processamento', PAID: 'Pago', PARTIAL: 'Parcial', FAILED: 'Falhou', REFUNDED: 'Reembolsado', DRAFT: 'Por confirmar', QUALIFIED: 'Qualificado', VISIT: 'Visita', PROPOSAL: 'Proposta', CONVERTED: 'Convertido', LOST: 'Perdido', PAYMENT: 'Pagamento', ADJUSTMENT: 'Ajuste', REFUND: 'Reembolso', DISPUTE: 'Contestação', DISPUTE_WON: 'Contestação ganha', RECEIVED: 'Recebida', RETURNED: 'Devolvida', DEDUCTED: 'Dedução', ENTRY: 'Entrada', EXIT: 'Saída', SENT: 'Enviado', DONE: 'Concluído', unconfigured: 'Por configurar' };
