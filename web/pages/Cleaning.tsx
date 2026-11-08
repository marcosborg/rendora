import { CalendarDays, Check, Plus } from 'lucide-react';
import { api, date, monthLabel, type Row } from '../api';
import { locale, tr } from '../locale';
import { Badge, Empty, Loading, options, PageHead, Panel, useApp, useData } from '../ui';

type Visit = {
  plan_id: string;
  property_name: string;
  assignee_name: string;
  visit_time: string;
  occurrence_date: string;
  scheduled_date: string;
  completed_at: string | null;
  completed_by_name: string | null;
};

const completionDate = new Intl.DateTimeFormat(locale, {
  dateStyle: 'short', timeStyle: 'short', timeZone: 'Europe/Lisbon',
});

export function CleaningPage({ publicDemo }: { publicDemo: boolean }) {
  const { role, period, modal } = useApp();
  const { data, error } = useData<{ visits: Visit[] }>(`/cleaning?period=${encodeURIComponent(period)}`);
  const manages = role === 'ADMIN' || role === 'MANAGER';
  const properties = useData<Row[]>(manages && !publicDemo ? '/properties' : null);
  const members = useData<Row[]>(manages && !publicDemo ? '/members' : null);
  const units = properties.data?.filter(property => ['APARTMENT', 'ROOM'].includes(property.type)) ?? [];
  const team = members.data?.filter(member => member.active && member.role === 'TECHNICIAN') ?? [];

  function addPlan() {
    modal({
      title: tr('Nova limpeza recorrente', 'New recurring cleaning'),
      subtitle: tr('Atribua a limpeza a um membro activo com o perfil Técnico. A hora é local em Portugal continental.', 'Assign the cleaning to an active Technician. Times are local to mainland Portugal.'),
      fields: [
        { name: 'property_id', label: tr('Apartamento ou quarto', 'Apartment or room'), type: 'select', options: options(units) },
        { name: 'assignee_id', label: tr('Pessoa responsável', 'Assigned to'), type: 'select', options: options(team) },
        { name: 'start_date', label: tr('Primeira limpeza', 'First visit'), type: 'date' },
        { name: 'end_date', label: tr('Último dia do plano', 'Plan end date'), type: 'date', required: false },
        { name: 'visit_time', label: tr('Hora', 'Time'), type: 'time', value: '10:00' },
        { name: 'interval_days', label: tr('Repetir a cada (dias)', 'Repeat every (days)'), type: 'number', min: 1, max: 365, value: 7 },
      ],
      onSubmit: values => api('/cleaning/plans', 'POST', values),
    });
  }

  function updateVisit(visit: Visit, action: 'COMPLETE' | 'RESCHEDULE') {
    const complete = action === 'COMPLETE';
    modal({
      title: complete ? tr('Confirmar conclusão', 'Confirm completion') : tr('Reagendar limpeza', 'Reschedule cleaning'),
      subtitle: `${visit.property_name} · ${date(visit.scheduled_date)}`,
      submit: complete ? tr('Marcar como concluída', 'Mark as completed') : tr('Guardar nova data', 'Save new date'),
      fields: complete ? [] : [{ name: 'scheduled_date', label: tr('Nova data', 'New date'), type: 'date', value: visit.scheduled_date }],
      onSubmit: values => api(`/cleaning/plans/${visit.plan_id}/visits/${visit.occurrence_date}`, 'PATCH', { ...values, action }),
    });
  }

  return <>
    <PageHead title={tr('Agenda de limpezas', 'Cleaning schedule')}
      subtitle={manages ? tr('Planeie as limpezas dos alojamentos e acompanhe a conclusão.', 'Schedule property cleanings and track their completion.') : tr('Consulte as limpezas que lhe foram atribuídas.', 'See the cleanings assigned to you.')}
      action={manages && !publicDemo && <button className="button" disabled={!units.length || !team.length} onClick={addPlan}>
        <Plus size={17}/>{tr('Nova limpeza recorrente', 'New recurring cleaning')}
      </button>}/>
    {manages && !publicDemo && (properties.error || members.error) && <Loading error={properties.error || members.error}/>}
    {manages && !publicDemo && properties.data && members.data && (!units.length || !team.length) &&
      <p className="info-strip">{tr('Para criar um plano, adicione um apartamento ou quarto e um membro activo com o perfil Técnico.', 'To create a plan, add an apartment or room and an active team member with the Technician role.')}</p>}
    {!data ? <Loading error={error}/> : <Panel title={monthLabel(period)}>
      {!data.visits.length ? <Empty title={tr('Sem limpezas neste mês', 'No cleanings this month')}
        text={tr('Seleccione outro mês no topo para consultar a agenda ou o histórico.', 'Choose another month at the top to view the schedule or history.')}/> :
        <div className="table-scroll"><table>
          <thead><tr><th>{tr('Data e hora', 'Date and time')}</th><th>{tr('Alojamento', 'Property')}</th>
            <th>{tr('Pessoa responsável', 'Assigned to')}</th><th>{tr('Estado', 'Status')}</th>{!publicDemo && <th/>}</tr></thead>
          <tbody>{data.visits.map(visit => <tr key={`${visit.plan_id}/${visit.occurrence_date}`}>
            <td>{date(visit.scheduled_date)} · {visit.visit_time}
              {visit.scheduled_date !== visit.occurrence_date && <div className="muted">{tr('Reagendada de', 'Moved from')} {date(visit.occurrence_date)}</div>}</td>
            <td>{visit.property_name}</td><td>{visit.assignee_name}</td>
            <td>{visit.completed_at ? <>
              <Badge value="RESOLVED" text={tr('Concluída', 'Completed')}/>
              <div className="muted">{completionDate.format(new Date(visit.completed_at.replace(' ', 'T') + 'Z'))} · {visit.completed_by_name}</div>
            </> : <Badge value="PENDING" text={tr('Por concluir', 'Pending')}/>}</td>
            {!publicDemo && <td>{!visit.completed_at && <>
              <button className="text-button" onClick={() => updateVisit(visit, 'COMPLETE')}><Check size={15}/>{tr('Concluir', 'Complete')}</button>
              {manages && <button className="text-button" onClick={() => updateVisit(visit, 'RESCHEDULE')}><CalendarDays size={15}/>{tr('Reagendar', 'Reschedule')}</button>}
            </>}</td>}
          </tr>)}</tbody>
        </table></div>}
    </Panel>}
  </>;
}
