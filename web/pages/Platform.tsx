import { PlatformBankTransfers } from './BankTransfers';
import { catalogPath, tr } from '../locale';
import { useState } from 'react';
import { Plus, Mail, Building2, ArrowUpRight } from 'lucide-react';
import { api, labels, type Row } from '../api';
import { useApp, useData, PageHead, Panel, Loading, Empty } from '../ui';

export function PlatformPage() {
  const { data, error } = useData<Row>('/platform');
  const { modal, toast, refresh, user } = useApp();
  const [created, setCreated] = useState<Row | null>(null);
  const [sending, setSending] = useState('');
  if (!data) return <Loading error={error}/>;

  function createCompany() {
    modal({
      title: tr("Nova empresa", "New company"),
      subtitle: tr("Cria uma área independente, sem imóveis nem dados de outras empresas. O administrador indicado terá acesso apenas a esta nova empresa e às empresas a que já pertence.", "Create a separate workspace with no properties or data from other companies. The administrator will have access to this company and any companies they already belong to."),
      submit: tr("Criar empresa", "Create company"),
      fields: [
        { name: 'name', label: tr("Nome da empresa", "Company name"), wide: true },
        { name: 'slug', label: tr("Endereço público", "Public address"), placeholder: 'nome-da-empresa' },
        { name: 'email', label: tr("Email público da empresa", "Public company email"), type: 'email' },
        { name: 'admin_name', label: tr("Nome do administrador", "Administrator name"), wide: true },
        { name: 'admin_email', label: tr("Email do administrador", "Administrator email"), type: 'email', wide: true },
      ],
      onSubmit: async d => {
        const result = await api('/platform/companies', 'POST', d);
        setCreated(result);
        // Refresh the account's company selector only when the actor was explicitly assigned.
        if (result.administrator.id === user.id && result.administrator.active) location.reload();
      },
    });
  }
  function newPlan() {
    modal({title:tr("Novo plano comercial", "New pricing plan"),subtitle:tr("A cobrança só é possível com um Price ID válido da Stripe.", "Billing requires a valid Stripe Price ID."),fields:[{name:'name',label:tr("Nome do plano", "Plan name")},{name:'stripe_price_id',label:'Stripe Price ID',required:false},{name:'property_limit',label:tr("Limite de imóveis", "Property limit"),type:'number',value:100},{name:'member_limit',label:tr("Limite de utilizadores", "User limit"),type:'number',value:20}],onSubmit:d=>api('/platform/plans','POST',d)});
  }
  async function resend(companyId: string, adminId: string) {
    const key = `${companyId}:${adminId}`; setSending(key);
    try {
      await api(`/platform/companies/${companyId}/administrators/${adminId}/invite`, 'POST', {});
      toast(tr("Novo convite colocado na fila. O envio depende do serviço de email e do cron.", "Invitation queued. Delivery depends on the email service and scheduled jobs.")); refresh();
    } catch (e) { toast((e as Error).message, true); } finally { setSending(''); }
  }
  return <>
    <PageHead eyebrow={tr("ADMINISTRAÇÃO DO SAAS", "PLATFORM ADMINISTRATION")} title={tr("A plataforma, num só lugar.", "Manage the platform in one place.")} subtitle={tr("Cria empresas independentes e atribui os seus administradores.", "Create independent companies and assign their administrators.")} action={<><button className="button secondary" onClick={newPlan}><Plus size={17}/>{tr("Novo plano", "New plan")}</button><button className="button" onClick={createCompany}><Plus size={17}/>{tr("Nova empresa", "New company")}</button></>}/>
    <div className="info-strip"><Mail size={18}/><span>{data.demo ? tr("Modo de demonstração: os convites ficam na fila, sem envio de emails reais.", "Demo mode: invitations are queued without sending real emails.") : !data.email_configured ? tr("Email por configurar: podes criar empresas, mas os novos administradores só poderão ativar o acesso depois de receberem o convite. Configura o remetente, o serviço de email e o cron no servidor.", "Email is not configured. You can create companies, but new administrators need an invitation to activate access. Configure the sender, email service and scheduled jobs on the server.") : tr("Novos administradores recebem uma ligação para definir a palavra-passe, válida durante uma hora. O cron tem de estar ativo para enviar os convites.", "New administrators receive a password setup link valid for one hour. Scheduled jobs must be enabled to send invitations.")} {tr("Contas existentes com email verificado mantêm a palavra-passe e recebem acesso à nova empresa.", "Existing accounts with verified email keep their password and gain access to the new company.")}</span></div>
    {created && <div className="panel platform-success" role="status"><Building2 size={22}/><div><h2>{tr(`${created.name} criada`, `${created.name} created`)}</h2><p>{created.administrator.invitation_pending ? tr(`Convite pendente para ${created.administrator.email}.`, `Invitation pending for ${created.administrator.email}.`) : tr(`Administrador atribuído: ${created.administrator.email}.`, `Administrator assigned: ${created.administrator.email}.`)}</p><a className="text-button" href={catalogPath(created.slug)}>{tr("Ver site público", "View public website")} <ArrowUpRight size={15}/></a></div></div>}
    <Panel title={tr("Empresas", "Companies")} subtitle={tr("Os dados operacionais de cada empresa permanecem separados.", "Each company's operational data remains separate.")}>
      {data.companies.length ? <div className="table-scroll"><table><thead><tr><th>{tr("Empresa", "Company")}</th><th>{tr("Site", "Website")}</th><th>{tr("Administradores", "Administrators")}</th><th>{tr("Subscrição", "Subscription")}</th></tr></thead><tbody>{data.companies.map((c: Row) => <tr key={c.id}><td><strong>{c.name}</strong></td><td><a href={catalogPath(c.slug)}>{c.slug}</a></td><td>{c.administrators.length ? c.administrators.map((a: Row) => <div className="platform-admin" key={a.id}><strong>{a.name}</strong><small>{a.email}</small><span className={`badge ${a.invitation_pending ? 'badge-pending' : a.active ? 'badge-active' : 'badge-closed'}`}>{a.invitation_pending ? tr("Convite pendente", "Invitation pending") : a.active ? tr("Ativo", "Active") : tr("Suspenso", "Suspended")}</span>{!!a.invitation_pending && <button className="text-button" disabled={!!sending} onClick={() => resend(c.id,a.id)}>{sending === `${c.id}:${a.id}` ? 'A preparar…' : tr("Reenviar convite", "Resend invitation")}</button>}</div>) : <span className="muted">{tr("Sem administrador atribuído", "No administrator assigned")}</span>}</td><td>{labels[c.subscription_status] || c.subscription_status}</td></tr>)}</tbody></table></div> : <Empty title={tr("Ainda não existem empresas", "No companies yet")} text={tr("Usa Nova empresa para criar a primeira área de gestão.", "Use New company to create the first workspace.")}/>}
    </Panel>
    <PlatformBankTransfers/>
    <Panel title={tr("Planos configurados", "Configured plans")}><div className="settings-description">{data.plans.map((p: Row) => <div className="plan-row" key={p.id}><div><strong>{p.name}</strong><small>{p.property_limit} {tr("imóveis ·", "properties ·")} {p.member_limit} {tr("utilizadores","users")}</small></div><span>{p.stripe_price_id ? tr("Preço associado", "Price linked") : tr("Sem cobrança configurada", "Billing not configured")}</span></div>)}</div></Panel>
  </>;
}
