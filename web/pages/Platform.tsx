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
      title: 'Nova empresa',
      subtitle: 'Cria uma área independente, sem imóveis nem dados de outras empresas. O administrador indicado terá acesso apenas a esta nova empresa e às empresas a que já pertence.',
      submit: 'Criar empresa',
      fields: [
        { name: 'name', label: 'Nome da empresa', wide: true },
        { name: 'slug', label: 'Endereço público', placeholder: 'nome-da-empresa' },
        { name: 'email', label: 'Email público da empresa', type: 'email' },
        { name: 'admin_name', label: 'Nome do administrador', wide: true },
        { name: 'admin_email', label: 'Email do administrador', type: 'email', wide: true },
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
    modal({title:'Novo plano comercial',subtitle:'A cobrança só é possível com um Price ID válido da Stripe.',fields:[{name:'name',label:'Nome do plano'},{name:'stripe_price_id',label:'Stripe Price ID',required:false},{name:'property_limit',label:'Limite de imóveis',type:'number',value:100},{name:'member_limit',label:'Limite de utilizadores',type:'number',value:20}],onSubmit:d=>api('/platform/plans','POST',d)});
  }
  async function resend(companyId: string, adminId: string) {
    const key = `${companyId}:${adminId}`; setSending(key);
    try {
      await api(`/platform/companies/${companyId}/administrators/${adminId}/invite`, 'POST', {});
      toast('Novo convite colocado na fila. O envio depende do serviço de email e do cron.'); refresh();
    } catch (e) { toast((e as Error).message, true); } finally { setSending(''); }
  }
  return <>
    <PageHead eyebrow="ADMINISTRAÇÃO DO SAAS" title="A plataforma, num só lugar." subtitle="Cria empresas independentes e atribui os seus administradores." action={<><button className="button secondary" onClick={newPlan}><Plus size={17}/>Novo plano</button><button className="button" onClick={createCompany}><Plus size={17}/>Nova empresa</button></>}/>
    <div className="info-strip"><Mail size={18}/><span>{data.demo ? 'Modo de demonstração: os convites ficam na fila, sem envio de emails reais.' : !data.email_configured ? 'Email por configurar: podes criar empresas, mas os novos administradores só poderão ativar o acesso depois de receberem o convite. Configura o remetente, o serviço de email e o cron no servidor.' : 'Novos administradores recebem uma ligação para definir a palavra-passe, válida durante uma hora. O cron tem de estar ativo para enviar os convites.'} Contas existentes com email verificado mantêm a palavra-passe e recebem acesso à nova empresa.</span></div>
    {created && <div className="panel platform-success" role="status"><Building2 size={22}/><div><h2>{created.name} criada</h2><p>{created.administrator.invitation_pending ? `Convite pendente para ${created.administrator.email}.` : `Administrador atribuído: ${created.administrator.email}.`}</p><a className="text-button" href={`/alojamentos/${created.slug}`}>Ver site público <ArrowUpRight size={15}/></a></div></div>}
    <Panel title="Empresas" subtitle="Os dados operacionais de cada empresa permanecem separados.">
      {data.companies.length ? <div className="table-scroll"><table><thead><tr><th>Empresa</th><th>Site</th><th>Administradores</th><th>Subscrição</th></tr></thead><tbody>{data.companies.map((c: Row) => <tr key={c.id}><td><strong>{c.name}</strong></td><td><a href={`/alojamentos/${c.slug}`}>{c.slug}</a></td><td>{c.administrators.length ? c.administrators.map((a: Row) => <div className="platform-admin" key={a.id}><strong>{a.name}</strong><small>{a.email}</small><span className={`badge ${a.invitation_pending ? 'badge-pending' : a.active ? 'badge-active' : 'badge-closed'}`}>{a.invitation_pending ? 'Convite pendente' : a.active ? 'Ativo' : 'Suspenso'}</span>{!!a.invitation_pending && <button className="text-button" disabled={!!sending} onClick={() => resend(c.id,a.id)}>{sending === `${c.id}:${a.id}` ? 'A preparar…' : 'Reenviar convite'}</button>}</div>) : <span className="muted">Sem administrador atribuído</span>}</td><td>{labels[c.subscription_status] || c.subscription_status}</td></tr>)}</tbody></table></div> : <Empty title="Ainda não existem empresas" text="Usa Nova empresa para criar a primeira área de gestão."/>}
    </Panel>
    <Panel title="Planos configurados"><div className="settings-description">{data.plans.map((p: Row) => <div className="plan-row" key={p.id}><div><strong>{p.name}</strong><small>{p.property_limit} imóveis · {p.member_limit} utilizadores</small></div><span>{p.stripe_price_id ? 'Preço associado' : 'Sem cobrança configurada'}</span></div>)}</div></Panel>
  </>;
}
