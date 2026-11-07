import { useState, type FormEvent } from 'react';
import { api, download, money, type Row } from '../api';
import { tr } from '../locale';
import { useApp, useData, Loading, Panel, options } from '../ui';

function ProofUpload({ path, fields }: { path: string; fields: Record<string, string> }) {
  const { refresh, toast } = useApp();
  const [busy, setBusy] = useState(false);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    for (const [key, value] of Object.entries(fields)) data.set(key, value);
    setBusy(true);
    try {
      await api(path, 'POST', data);
      refresh();
      toast(tr('Comprovativo enviado para validação.', 'Proof submitted for review.'));
    } catch (error) {
      toast((error as Error).message, true);
    } finally {
      setBusy(false);
    }
  }
  return <form className="action-wrap" onSubmit={submit}>
    <label>{tr('Comprovativo (PDF, JPG ou PNG, até 5 MB)', 'Proof (PDF, JPG or PNG, up to 5 MB)')}
      <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" required/>
    </label>
    <button className="button secondary" disabled={busy}>{tr('Enviar comprovativo', 'Submit proof')}</button>
  </form>;
}

function Instructions({ data }: { data: Row }) {
  return <p>{tr('Beneficiário', 'Beneficiary')}: <strong>{data.beneficiary}</strong><br/>
    IBAN: <strong>{data.iban}</strong><br/>
    {tr('Valor', 'Amount')}: <strong>{money(data.amount_cents)}</strong><br/>
    {tr('Referência a indicar na transferência', 'Transfer reference')}: <strong>{data.reference}</strong>
  </p>;
}

function ProofRows({ proofs, platform = false }: { proofs: Row[]; platform?: boolean }) {
  const { toast, modal } = useApp();
  const prefix = platform ? '/platform/bank-proofs' : '/bank-proofs';
  function review(proof: Row, approve: boolean) {
    modal({
      title: approve ? tr('Confirmar entrada do dinheiro', 'Confirm receipt of funds') : tr('Rejeitar comprovativo', 'Reject proof'),
      subtitle: approve
        ? tr(`Confirma no extracto que ${money(proof.amount_cents)} entrou na conta ${proof.iban} de ${proof.beneficiary}.`,
             `Check the bank statement for ${money(proof.amount_cents)} received by ${proof.beneficiary} at ${proof.iban}.`)
        : tr('O pagador poderá enviar outro comprovativo.', 'The payer can submit another proof.'),
      fields: approve ? [{ name: 'bank_reference', label: tr('Identificador único da operação no extracto', 'Unique transaction ID on the bank statement') }] : [],
      submit: approve ? tr('Confirmar recebimento', 'Confirm receipt') : tr('Rejeitar', 'Reject'),
      onSubmit: data => api(`${prefix}/${proof.id}/review`, 'POST', { ...data, status: approve ? 'APPROVED' : 'REJECTED' }),
    });
  }
  return <>{proofs.map(proof => <div className="plan-row" key={proof.id}>
    <div><strong>{[proof.company, proof.plan, money(proof.amount_cents)].filter(Boolean).join(' · ')}</strong>
      <small>{proof.status === 'APPROVED' ? tr('Recebimento confirmado', 'Receipt confirmed') : proof.status === 'REJECTED' ? tr('Rejeitado', 'Rejected') : tr('A aguardar validação', 'Awaiting review')}
        {proof.paid_until && ` · ${tr('Pago até', 'Paid until')} ${proof.paid_until}`}
      </small></div>
    <div className="action-wrap">
      {proof.downloadable !== false && <button className="text-button" onClick={() => download(`${prefix}/${proof.id}`, `comprovativo-${proof.id}.${proof.extension}`).catch(error => toast(error.message, true))}>{tr('Descarregar', 'Download')}</button>}
      {proof.status === 'PENDING' && (platform || proof.reviewable) &&
        <button className="button secondary" onClick={() => review(proof, true)}>{tr('Validar', 'Approve')}</button>}
      {proof.status === 'PENDING' && (platform || proof.reviewable || proof.rejectable) &&
        <button className="text-button" onClick={() => review(proof, false)}>{tr('Rejeitar', 'Reject')}</button>}
    </div>
  </div>)}</>;
}

export function RentBankTransfer({ id }: { id: string }) {
  const { bankTransfers, role } = useApp();
  const path = `/charges/${id}/bank-transfer`;
  const { data, error } = useData<Row>(bankTransfers ? path : null);
  if (!bankTransfers) return null;
  if (!data) return <Loading error={error}/>;
  if (!data.instructions && !data.proofs.length) return null;
  return <section>
    <h3>{tr('Transferência bancária', 'Bank transfer')}</h3>
    {data.instructions && <Instructions data={data.instructions}/>}
    {role === 'RESIDENT' && data.instructions && !data.proofs.some((proof: Row) => proof.status === 'PENDING') &&
      <ProofUpload path={path} fields={{ version: data.instructions.version }}/>}
    <ProofRows proofs={data.proofs}/>
  </section>;
}

export function ConfigureRentBank({ id }: { id: string }) {
  const { bankTransfers, role, modal, toast } = useApp();
  const path = `/contracts/${id}/bank-account`;
  const { data } = useData<Row>(bankTransfers && role === 'ADMIN' ? path : null);
  if (!data) return null;
  async function edit() {
    try {
      const members = (await api<Row[]>('/members')).filter(member => Number(member.active) && ['OWNER','MANAGER','ADMIN'].includes(member.role));
      modal({
        title: tr('Beneficiário das rendas', 'Rent beneficiary'),
        subtitle: tr('Indica o proprietário do imóvel ou um gestor autorizado e a conta que recebe directamente as rendas.', 'Choose the property owner or an authorised manager and the account that receives rent directly.'),
        fields: [
          { name: 'recipient_id', label: tr('Responsável pela validação', 'Reviewer'), type: 'select', options: options(members), value: data?.account?.recipient_id },
          { name: 'beneficiary', label: tr('Nome do titular da conta', 'Bank account holder'), value: data?.account?.beneficiary },
          { name: 'iban', label: 'IBAN', value: data?.account?.iban },
        ],
        onSubmit: values => api(path, 'PUT', values),
      });
    } catch (error) {
      toast((error as Error).message, true);
    }
  }
  return <button className="button secondary" onClick={edit}>{tr('Configurar transferência da renda', 'Configure rent bank transfer')}</button>;
}

export function SubscriptionBankTransfer() {
  const { bankTransfers, role } = useApp();
  const path = '/settings/bank-transfer';
  const enabled = bankTransfers && role === 'ADMIN';
  const { data, error } = useData<Row>(enabled ? path : null);
  const [selected, setSelected] = useState('');
  if (!enabled) return null;
  if (!data) return <Loading error={error}/>;
  const offer = (data.offers as Row[]).find(item => item.version === selected);
  return <Panel title={tr('Subscrição por transferência', 'Subscription by bank transfer')}>
    <div className="settings-description">
      {!data.offers.length && <p>{tr('Não existem planos disponíveis por transferência.', 'No plans are available by bank transfer.')}</p>}
      {data.offers.length > 0 && !data.proofs.some((proof: Row) => proof.status === 'PENDING') && <>
        <label>{tr('Plano e período', 'Plan and period')} <select value={selected} onChange={event => setSelected(event.target.value)}>
          <option value="">{tr('Seleccionar', 'Select')}</option>
          {(data.offers as Row[]).map(item => <option key={item.version} value={item.version}>{item.name} · {item.months === 1 ? tr('Mensal', 'Monthly') : tr('Anual', 'Yearly')} · {money(item.amount_cents)}</option>)}
        </select></label>
        {offer && <><Instructions data={offer}/><ProofUpload path={path} fields={{ plan_id: offer.plan_id, months: String(offer.months), version: offer.version }}/></>}
      </>}
      <ProofRows proofs={data.proofs}/>
    </div>
  </Panel>;
}

export function PlatformBankTransfers() {
  const { bankTransfers } = useApp();
  const { data, error } = useData(bankTransfers ? '/platform/bank-proofs' : null);
  if (!bankTransfers) return null;
  if (!data) return <Loading error={error}/>;
  return <Panel title={tr('Transferências de subscrições', 'Subscription bank transfers')}>
    <div className="settings-description"><ProofRows proofs={data} platform/></div>
  </Panel>;
}
