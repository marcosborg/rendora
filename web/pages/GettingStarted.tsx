import { ArrowUpRight } from 'lucide-react';
import { homePath, tr } from '../locale';
import { useApp } from '../ui';

type Step = {
  page: string;
  title: [string, string];
  text: [string, string];
};

export function GettingStarted({ publicDemo }: { publicDemo: boolean }) {
  const { go, role } = useApp();
  const properties: Step = { page: 'properties', title: ['Consultar alojamentos', 'View properties'], text: ['Abra um alojamento para consultar os seus dados e a ocupação.', 'Open a property to see its details and occupancy.'] };
  const contracts: Step = { page: 'contracts', title: ['Consultar contratos', 'View agreements'], text: ['Abra um contrato para consultar as condições, as datas e o valor da renda.', 'Open an agreement to check the terms, dates and rent amount.'] };
  const rent: Step = { page: 'payments', title: ['Consultar rendas', 'View rent'], text: ['Escolha o mês e consulte os vencimentos, os pagamentos e os saldos.', 'Choose the month and check due dates, payments and balances.'] };
  const messages: Step = { page: 'messages', title: ['Consultar mensagens', 'View messages'], text: ['Abra uma conversa para acompanhar as mensagens da sua empresa.', 'Open a conversation to catch up on messages from your workspace.'] };

  const management: Step[] = [
    { page: 'settings', title: role === 'ADMIN' ? ['Configurar a empresa', 'Set up the workspace'] : ['Consultar as definições da empresa', 'Review workspace settings'], text: ['Confirme o nome, os contactos públicos e os lembretes na secção Empresa.', 'Check the name, public contact details and reminders in Company settings.'] },
    { page: 'settings-team', title: [role === 'ADMIN' ? 'Convidar proprietários e inquilinos' : 'Ver a equipa e os acessos', role === 'ADMIN' ? 'Invite owners and tenants' : 'Review team access'], text: role === 'ADMIN'
      ? ['Use Convidar para a equipa e seleccione o perfil de cada pessoa. Adicione o inquilino antes de criar o contrato.', 'Use Invite to the team and choose each person’s role. Add the tenant before creating the agreement.']
      : ['Confirme que os proprietários e inquilinos estão na equipa. Peça ao administrador da empresa para enviar os convites em falta.', 'Check that owners and tenants are in the workspace. Ask your workspace administrator to send any missing invitations.'] },
    { page: 'properties', title: ['Adicionar o primeiro alojamento', 'Add your first property'], text: ['Escolha Novo alojamento. Pode criar um edifício primeiro e associar-lhe um apartamento ou quarto. Seleccione o proprietário do espaço.', 'Choose New property. You can create a building first and link an apartment or room to it. Select the property owner.'] },
    { page: 'contracts', title: ['Criar o primeiro contrato', 'Create your first agreement'], text: ['Escolha Novo contrato e indique o alojamento, os inquilinos, as datas e a renda mensal.', 'Choose New contract and enter the property, tenants, dates and monthly rent.'] },
    { page: 'payments', title: ['Acompanhar a primeira renda', 'Track your first rent charge'], text: ['Escolha o mês, abra a cobrança e consulte o saldo. Registe um pagamento manual quando já tiver recebido o valor.', 'Choose the month, open the charge and check the balance. Record a manual payment once you have received the money.'] },
  ];

  const guides: Record<string, Step[]> = {
    ADMIN: management,
    MANAGER: management,
    RESIDENT: [
      contracts, rent,
      { page: 'maintenance', title: ['Reportar uma avaria', 'Report an issue'], text: ['Escolha Novo pedido, indique o alojamento e descreva o problema. Volte aqui para acompanhar o estado.', 'Choose New request, select your property and describe the issue. Come back here to track its status.'] },
      messages,
    ],
    OWNER: [properties, contracts, rent, messages],
    TECHNICIAN: [
      { page: 'maintenance', title: ['Ver trabalhos atribuídos', 'View assigned jobs'], text: ['Abra um pedido atribuído para consultar o alojamento, a prioridade e os detalhes do trabalho.', 'Open an assigned request to see the property, priority and job details.'] },
      { page: 'maintenance', title: ['Actualizar um pedido', 'Update a request'], text: ['Abra o pedido, registe o progresso e actualize o estado à medida que o trabalho avança.', 'Open the request, record your progress and update its status as work advances.'] },
      messages,
    ],
    FINANCE: [rent, contracts, properties],
  };
  const steps = guides[role];
  if (!steps) return null;

  return <details className="panel getting-started" open>
    <summary>{tr('Primeiros passos', 'Getting started')}</summary>
    {publicDemo && <p>{tr('Explore cada tarefa com dados fictícios. Nesta demo pode consultar os ecrãs e os exemplos.', 'Explore each task with sample data. In this demo you can browse the screens and examples.')}</p>}
    <ol>
      {steps.map(({ page, title, text }) => <li key={title[0]}>
        <a href={`${homePath}?page=${page}`} onClick={event => {
          if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
          event.preventDefault();
          go(page);
        }}>{tr(...title)} <ArrowUpRight size={15} aria-hidden="true"/></a>
        <span>{tr(...text)}</span>
      </li>)}
    </ol>
  </details>;
}
