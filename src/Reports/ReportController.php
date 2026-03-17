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

    private function getDateRange(Request $request): array
    {
        $to   = $request->input('to')   ?: date('Y-m-d');
        $from = $request->input('from') ?: date('Y-m-d', strtotime('-30 days'));

        // Validate format — fall back to defaults if input is malformed
        $validDate = fn(string $d) => (bool)\DateTime::createFromFormat('Y-m-d', $d);
        if (!$validDate($from)) $from = date('Y-m-d', strtotime('-30 days'));
        if (!$validDate($to))   $to   = date('Y-m-d');

        // Ensure from <= to
        if ($from > $to) [$from, $to] = [$to, $from];

        return [$from, $to];
    }

    public function summary(Request $request): void
    {
        [$from, $to] = $this->getDateRange($request);
        Response::success($this->repo->summary($from, $to));
    }

    public function byAgent(Request $request): void
    {
        [$from, $to] = $this->getDateRange($request);
        Response::success($this->repo->byAgent($from, $to));
    }

    public function byStatus(Request $request): void
    {
        [$from, $to] = $this->getDateRange($request);
        Response::success($this->repo->byStatus($from, $to));
    }

    public function timeToClose(Request $request): void
    {
        [$from, $to] = $this->getDateRange($request);
        $agentId     = $request->input('agent_id') ? (int)$request->input('agent_id') : null;
        Response::success($this->repo->timeToClose($from, $to, $agentId));
    }

    public function volume(Request $request): void
    {
        [$from, $to] = $this->getDateRange($request);
        $groupBy     = in_array($request->input('group_by'), ['day', 'week', 'month'], true)
            ? $request->input('group_by') : 'day';
        Response::success($this->repo->volume($from, $to, $groupBy));
    }
}
