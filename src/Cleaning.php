<?php
declare(strict_types=1);
namespace HomeAlone;

use DateTimeImmutable;
use DateTimeZone;

final class Cleaning {
    public function __construct(private Database $db) {}

    public function agenda(Scope $scope, mixed $period): array {
        $scope->requireRoles('ADMIN', 'MANAGER', 'TECHNICIAN');
        if (!is_string($period) || !preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D', $period)) {
            throw new HttpError(422, 'Mês inválido.');
        }
        $first = self::day($period . '-01');
        $from = $first->format('Y-m-d');
        $through = $first->modify('last day of this month')->format('Y-m-d');
        $assigned = $scope->role === 'TECHNICIAN';
        $filters = $assigned ? [$scope->companyId, $scope->user['id']] : [$scope->companyId];
        $where = 'p.company_id=?' . ($assigned ? ' AND p.assignee_id=?' : '');
        $plans = $this->db->all("SELECT p.*, a.name property_name, u.name assignee_name
            FROM cleaning_plans p JOIN properties a ON a.id=p.property_id
            JOIN users u ON u.id=p.assignee_id WHERE $where ORDER BY a.name, p.start_date", $filters);
        $exceptions = $this->db->all("SELECT v.*, u.name completed_by_name FROM cleaning_visits v
            JOIN cleaning_plans p ON p.id=v.plan_id LEFT JOIN users u ON u.id=v.completed_by
            WHERE $where AND (v.occurrence_date BETWEEN ? AND ? OR v.scheduled_date BETWEEN ? AND ?)",
            [...$filters, $from, $through, $from, $through]);
        $changes = [];
        foreach ($exceptions as $visit) {
            $changes[$visit['plan_id']][$visit['occurrence_date']] = $visit;
        }

        $visits = [];
        foreach ($plans as $plan) {
            $start = self::day($plan['start_date']);
            $interval = (int)$plan['interval_days'];
            $elapsed = max(0, (int)$start->diff($first)->format('%r%a'));
            $offset = intdiv($elapsed + $interval - 1, $interval) * $interval;
            $last = self::day(min($through, $plan['end_date'] ?? $through));
            $overrides = $changes[$plan['id']] ?? [];
            for ($day = $start->modify("+$offset days"); $day <= $last; $day = $day->modify("+$interval days")) {
                $date = $day->format('Y-m-d');
                $overrides[$date] ??= ['occurrence_date' => $date, 'scheduled_date' => $date];
            }
            foreach ($overrides as $visit) {
                if ($visit['scheduled_date'] >= $from && $visit['scheduled_date'] <= $through) {
                    $visits[] = [
                        'plan_id' => $plan['id'], 'property_name' => $plan['property_name'],
                        'assignee_name' => $plan['assignee_name'], 'visit_time' => $plan['visit_time'],
                        'occurrence_date' => $visit['occurrence_date'], 'scheduled_date' => $visit['scheduled_date'],
                        'completed_at' => $visit['completed_at'] ?? null, 'completed_by_name' => $visit['completed_by_name'] ?? null,
                    ];
                }
            }
        }
        usort($visits, static fn($a, $b) => [$a['scheduled_date'], $a['visit_time'], $a['property_name'], $a['plan_id']]
            <=> [$b['scheduled_date'], $b['visit_time'], $b['property_name'], $b['plan_id']]);
        return ['visits' => $visits];
    }

    public function create(Scope $scope, array $data): array {
        $scope->requireRoles('ADMIN', 'MANAGER');
        $start = Validate::date($data, 'start_date');
        $end = Validate::date($data, 'end_date', true);
        $time = Validate::text($data, 'visit_time', 5, 5);
        if ($start < '1000-01-01' || ($end !== null && $end < $start) || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) {
            throw new HttpError(422, 'Datas ou hora de limpeza inválidas.');
        }
        $interval = Validate::integer($data, 'interval_days', 1, 365);
        return $this->db->transaction(function () use ($scope, $data, $start, $end, $time, $interval) {
            $property = $scope->property(Validate::text($data, 'property_id'));
            if (!in_array($property['type'], ['APARTMENT', 'ROOM'], true)) {
                throw new HttpError(422, 'Escolha um apartamento ou quarto.');
            }
            $assignee = $scope->member(Validate::text($data, 'assignee_id'), ['TECHNICIAN']);
            $plan = $this->db->insert('cleaning_plans', [
                'company_id' => $scope->companyId, 'property_id' => $property['id'], 'assignee_id' => $assignee['user_id'],
                'start_date' => $start, 'end_date' => $end, 'interval_days' => $interval, 'visit_time' => $time,
            ]);
            $scope->audit('cleaning.plan_created', $plan['id']);
            return $plan;
        });
    }

    public function updateVisit(Scope $scope, string $id, string $date, array $data): array {
        $scope->requireRoles('ADMIN', 'MANAGER', 'TECHNICIAN');
        $action = Validate::choice($data, 'action', ['COMPLETE', 'RESCHEDULE']);
        if ($action === 'RESCHEDULE') $scope->requireRoles('ADMIN', 'MANAGER');
        Validate::date(['date' => $date], 'date');

        return $this->db->transaction(function () use ($scope, $id, $date, $data, $action) {
            $plan = $scope->row('cleaning_plans', $id);
            if ($scope->role === 'TECHNICIAN' && $plan['assignee_id'] !== $scope->user['id']) {
                throw new HttpError(404, 'Limpeza não encontrada.');
            }
            $offset = (int)self::day($plan['start_date'])->diff(self::day($date))->format('%r%a');
            if ($offset < 0 || $offset % (int)$plan['interval_days'] !== 0 || ($plan['end_date'] && $date > $plan['end_date'])) {
                throw new HttpError(404, 'Limpeza não encontrada.');
            }
            $visit = $this->db->one('SELECT * FROM cleaning_visits WHERE plan_id=? AND occurrence_date=?', [$id, $date]);
            if ($visit && $visit['completed_at']) {
                if ($action === 'COMPLETE') return $visit;
                throw new HttpError(409, 'A limpeza já foi concluída.');
            }
            $fields = $action === 'COMPLETE'
                ? ['completed_at' => gmdate('Y-m-d H:i:s'), 'completed_by' => $scope->user['id']]
                : ['scheduled_date' => Validate::date($data, 'scheduled_date')];
            if (isset($fields['scheduled_date']) && $fields['scheduled_date'] < '1000-01-01') {
                throw new HttpError(422, 'Data de limpeza inválida.');
            }
            if ($visit) {
                $set = $action === 'COMPLETE' ? 'completed_at=?, completed_by=?' : 'scheduled_date=?';
                $this->db->run("UPDATE cleaning_visits SET $set WHERE id=?", [...array_values($fields), $visit['id']]);
                $visit = [...$visit, ...$fields];
            } else {
                $visit = $this->db->insert('cleaning_visits', [
                    'plan_id' => $id, 'occurrence_date' => $date, 'scheduled_date' => $date, ...$fields,
                ]);
            }
            $scope->audit($action === 'COMPLETE' ? 'cleaning.completed' : 'cleaning.rescheduled', $visit['id']);
            return $visit;
        });
    }

    private static function day(string $date): DateTimeImmutable {
        return new DateTimeImmutable($date, new DateTimeZone('Europe/Lisbon'));
    }
}
