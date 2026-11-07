import { en, locale, tr } from './locale';
export type Row = Record<string, any>;
let company = ''; let csrf = '';
export function sessionContext(companyId: string, token: string) { company = companyId; csrf = token; }
export async function api<T = any>(path: string, method = 'GET', data?: unknown): Promise<T> {
  const multipart = data instanceof FormData;
  const response = await fetch(`/api/v1${path}`, { method, credentials: 'same-origin', headers: { ...(multipart ? {} : { 'Content-Type': 'application/json' }), 'X-Rendora-Language': locale, 'X-Company-Id': company, 'X-CSRF-Token': csrf }, body: data === undefined ? undefined : multipart ? data : JSON.stringify(data) });
  if (!response.ok) { const error = await response.json().catch(() => ({})); throw new Error(localError(error.message, response.status)); }
  return response.json();
}
const englishErrors: Record<string, string> = {
  'Email ou palavra-passe incorretos.': 'Incorrect email or password.',
  'Inicie sessão para continuar.': 'Sign in to continue.',
  'Sessão expirada. Inicie sessão novamente.': 'Your session has expired. Sign in again.',
  'Ação indisponível na demonstração pública.': 'This action is unavailable in the public demo.',
  'Demasiadas tentativas. Tente mais tarde.': 'Too many attempts. Try again later.',
  'Não encontrado.': 'Not found.',
};
function localError(message?: string, status?: number) {
  if (!en) return message || 'Não foi possível concluir o pedido.';
  return englishErrors[message || ''] || (status === 403 ? 'You do not have permission for this action.' : 'The request could not be completed.');
}
export async function download(path: string, filename: string) {
  const response = await fetch(`/api/v1${path}`, { headers: { 'X-Rendora-Language': locale, 'X-Company-Id': company }, credentials: 'same-origin' });
  if (!response.ok) throw new Error(localError((await response.json().catch(() => ({}))).message, response.status));
  const url = URL.createObjectURL(await response.blob()); const a = document.createElement('a'); a.href = url; a.download = filename; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
}
export const money = (cents: number = 0) => new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR', maximumFractionDigits: 2 }).format(cents / 100);
export const date = (value?: string) => value ? new Intl.DateTimeFormat(locale).format(new Date(value.slice(0, 10) + 'T12:00:00')) : tr('Sem data','No date');
export const monthLabel = (value: string) => new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }).format(new Date(value + '-01T12:00:00'));
export const initials = (value = '') => value.split(' ').filter(Boolean).slice(0, 2).map(x => x[0]).join('').toUpperCase();
export const uid = () => crypto.randomUUID();
export const labels: Record<string, string> = en ? {
  ADMIN:'Administrator', MANAGER:'Manager', FINANCE:'Finance', TECHNICIAN:'Technician', OWNER:'Owner', RESIDENT:'Resident', LEAD:'Lead', PROVIDER:'Provider',
  BUILDING:'Building', APARTMENT:'Apartment', ROOM:'Room', COMMON:'Common area', ACTIVE:'Active', ENDED:'Ended', CANCELLED:'Cancelled', NEW:'New', TRIAGE:'Under review', SCHEDULED:'Scheduled', IN_PROGRESS:'In progress', WAITING:'Waiting', RESOLVED:'Resolved', CLOSED:'Closed',
  LOW:'Low', NORMAL:'Normal', HIGH:'High', URGENT:'Urgent', PLUMBING:'Plumbing', ELECTRICITY:'Electrical', APPLIANCE:'Appliances', CLEANING:'Cleaning', OTHER:'Other',
  PENDING:'Pending', PROCESSING:'Processing', PAID:'Paid', PARTIAL:'Partial', FAILED:'Failed', REFUNDED:'Refunded', DRAFT:'Awaiting confirmation', QUALIFIED:'Qualified', VISIT:'Viewing', PROPOSAL:'Proposal', CONVERTED:'Converted', LOST:'Lost', PAYMENT:'Payment', ADJUSTMENT:'Adjustment', REFUND:'Refund', DISPUTE:'Dispute', DISPUTE_WON:'Dispute won', RECEIVED:'Received', RETURNED:'Returned', DEDUCTED:'Deduction', ENTRY:'Check-in', EXIT:'Check-out', SENT:'Sent', DONE:'Completed', unconfigured:'Not configured',
} : { ADMIN: 'Administrador', MANAGER: 'Gestor', FINANCE: 'Financeiro', TECHNICIAN: 'Técnico', OWNER: 'Proprietário', RESIDENT: 'Morador', LEAD: 'Interessado', PROVIDER: 'Prestador', BUILDING: 'Edifício', APARTMENT: 'Apartamento', ROOM: 'Quarto', COMMON: 'Área comum', ACTIVE: 'Ativo', ENDED: 'Terminado', CANCELLED: 'Cancelado', NEW: 'Novo', TRIAGE: 'Em triagem', SCHEDULED: 'Agendado', IN_PROGRESS: 'Em execução', WAITING: 'A aguardar', RESOLVED: 'Resolvido', CLOSED: 'Encerrado', LOW: 'Baixa', NORMAL: 'Normal', HIGH: 'Alta', URGENT: 'Urgente', PLUMBING: 'Canalização', ELECTRICITY: 'Eletricidade', APPLIANCE: 'Equipamentos', CLEANING: 'Limpeza', OTHER: 'Outro', PENDING: 'Pendente', PROCESSING: 'Em processamento', PAID: 'Pago', PARTIAL: 'Parcial', FAILED: 'Falhou', REFUNDED: 'Reembolsado', DRAFT: 'Por confirmar', QUALIFIED: 'Qualificado', VISIT: 'Visita', PROPOSAL: 'Proposta', CONVERTED: 'Convertido', LOST: 'Perdido', PAYMENT: 'Pagamento', ADJUSTMENT: 'Ajuste', REFUND: 'Reembolso', DISPUTE: 'Contestação', DISPUTE_WON: 'Contestação ganha', RECEIVED: 'Recebida', RETURNED: 'Devolvida', DEDUCTED: 'Dedução', ENTRY: 'Entrada', EXIT: 'Saída', SENT: 'Enviado', DONE: 'Concluído', unconfigured: 'Por configurar' };
