import { useState } from 'react';
import { api, money, type Row } from '../api';
import { tr } from '../locale';
import { Loading, Panel, useApp, useData } from '../ui';

export function RentPayPalSandbox({ id }: { id: string }) {
  const { role, paypalSandbox, toast, refresh } = useApp();
  const enabled = paypalSandbox && role === 'RESIDENT';
  const path = `/charges/${id}/paypal-sandbox`;
  const { data, error } = useData<Row>(enabled ? path : null);
  const [busy, setBusy] = useState(false);

  async function pay(reconcile = false) {
    setBusy(true);
    try {
      const result = await api(reconcile ? `${path}/reconcile` : path, 'POST');
      if (reconcile) {
        toast(result.status === 'COMPLETED' ? tr('Renda fictícia confirmada.', 'Sandbox rent confirmed.') : tr('A aguardar confirmação do PayPal.', 'Awaiting PayPal confirmation.'));
        refresh();
      } else location.assign(result.url);
    } catch (failure) {
      toast((failure as Error).message, true);
    } finally {
      setBusy(false);
    }
  }

  if (!enabled) return null;
  if (!data) return <Loading error={error}/>;
  if (!data.beneficiary && !data.payment) return null;
  const completed = data.payment?.status === 'COMPLETED';
  return <section>
    <h3>PayPal Sandbox</h3>
    {data.beneficiary && <p>{tr('Beneficiário da renda de teste', 'Sandbox rent beneficiary')}: <strong>{data.beneficiary}</strong></p>}
    {completed ? <p>{tr('Pagamento fictício confirmado.', 'Sandbox payment confirmed.')}</p> : <div className="action-wrap">
      {data.beneficiary && <button className="button secondary" disabled={busy} onClick={() => pay()}>{tr('Abrir checkout de teste', 'Open sandbox checkout')}</button>}
      {data.payment?.status === 'PENDING' && <button className="text-button" disabled={busy} onClick={() => pay(true)}>{tr('Verificar pagamento no PayPal', 'Check payment with PayPal')}</button>}
    </div>}
  </section>;
}

export function SubscriptionPayPalSandbox() {
  const { role, paypalSandbox, toast } = useApp();
  const enabled = paypalSandbox && role === 'ADMIN';
  const path = '/settings/paypal-sandbox';
  const { data, error } = useData<Row>(enabled ? path : null);
  const [busy, setBusy] = useState(false);

  async function subscribe(offer: Row) {
    setBusy(true);
    try {
      const { url } = await api(path, 'POST', { plan_id: offer.plan_id, months: offer.months });
      location.assign(url);
    } catch (failure) {
      toast((failure as Error).message, true);
    } finally {
      setBusy(false);
    }
  }

  if (!enabled) return null;
  if (!data) return <Loading error={error}/>;
  const canSubscribe = !data.payment || ['CREATING', 'PENDING', 'CANCELLED', 'EXPIRED'].includes(data.payment.status);
  return <Panel title={tr('Subscrição PayPal Sandbox', 'PayPal Sandbox subscription')}>
    <div className="settings-description">
      <p>{tr('Ambiente de teste com pagamentos fictícios.', 'Test environment with simulated payments.')}</p>
      {data.payment && <p>{tr('Estado', 'Status')}: {data.payment.status}
        {data.payment.covered_until && ` · ${tr('Pago até', 'Paid until')} ${data.payment.covered_until}`}</p>}
      {canSubscribe && (data.offers as Row[]).map(offer => <div className="plan-row" key={`${offer.plan_id}:${offer.months}`}>
        <strong>{offer.name} · {money(offer.amount_cents)} · {offer.months === 1 ? tr('Mensal', 'Monthly') : tr('Anual', 'Yearly')}</strong>
        <button className="button secondary" disabled={busy} onClick={() => subscribe(offer)}>{tr('Testar subscrição', 'Test subscription')}</button>
      </div>)}
    </div>
  </Panel>;
}
