<?php
declare(strict_types=1);

namespace Andrea\Helpdesk\Reports;

use Andrea\Helpdesk\Core\Request;
use Andrea\Helpdesk\Core\Response;

class ReportController
{
    private ReportRepository $repo;

    public function __construct()
    {
        $this->repo = new ReportRepository();
    }

    private function defaultFrom(): string
    {
        return date('Y-m-01');
    }

    private function getDateRange(Request $request): array
    {
        $to   = $request->input('to')   ?: date('Y-m-d');
        $from = $request->input('from') ?: $this->defaultFrom();

        // Validate format — fall back to defaults if input is malformed
        $validDate = fn(string $d) => (bool)\DateTime::createFromFormat('Y-m-d', $d);
        if (!$validDate($from)) $from = $this->defaultFrom();
        if (!$validDate($to))   $to   = date('Y-m-d');

        // Ensure from <= to
        if ($from > $to) [$from, $to] = [$to, $from];

        return [$from, $to];
    }

    public function snapshot(Request $request): void
    {
        Response::success($this->repo->snapshot());
    }

    public function activitySummary(Request $request): void
    {
        [$from, $to] = $this->getDateRange($request);
        Response::success($this->repo->activitySummary($from, $to));
    }

    public function activityByAgent(Request $request): void
    {
        [$from, $to] = $this->getDateRange($request);
        Response::success($this->repo->activityByAgent($from, $to));
    }

    public function timeToClose(Request $request): void
    {
        [$from, $to] = $this->getDateRange($request);
        $agentId     = $request->input('agent_id') ? (int)$request->input('agent_id') : null;
        Response::success($this->repo->timeToClose($from, $to, $agentId));
    }

    public function activityVolume(Request $request): void
    {
        [$from, $to] = $this->getDateRange($request);
        $groupBy     = in_array($request->input('group_by') ?? $request->input('group'), ['day', 'week', 'month'], true)
            ? ($request->input('group_by') ?? $request->input('group')) : 'day';
        Response::success($this->repo->activityVolume($from, $to, $groupBy));
    }
}
