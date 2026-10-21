<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(protected DashboardService $dashboardService) {}

    public function index(Request $request)
    {
        try {
            $request->validate([
                'startDate' => 'nullable|date',
                'endDate' => 'nullable|date|after_or_equal:startDate',
                'leaderboardFrom' => 'nullable|date',
                'leaderboardTo' => 'nullable|date|after_or_equal:leaderboardFrom',
                'leaderBoardPeriod' => 'nullable|in:weekly,monthly,quarter,yearly',
                'leaderBoardTeamId' => 'nullable|integer|exists:teams,id',
                'overviewFrom' => 'nullable|date',
                'overviewTo' => 'nullable|date|after_or_equal:overviewFrom',
                'agentId' => 'nullable|integer|exists:users,id',
                'globalTeam' => 'nullable|integer|exists:teams,id',
            ]);

            $data = isAgent() || $request->filled('agentId')
                ? $this->dashboardService->agentDashboard($request)
                : $this->dashboardService->adminDashboard($request);

            return $this->success($data, 'Dashboard data retrieved successfully');
        } catch (\Throwable $e) {
            return $this->exceptionResponse('DashboardController : index', $e);
        }
    }

    public function leaderboard(Request $request)
    {
        try {
            $request->validate([
                'leaderboardFrom' => 'nullable|date',
                'leaderboardTo' => 'nullable|date|after_or_equal:leaderboardFrom',
                'leaderBoardPeriod' => 'nullable|in:weekly,monthly,quarter,yearly',
                'leaderBoardTeamId' => 'nullable|integer|exists:teams,id',
            ]);

            $data = $this->dashboardService->leaderboard($request);

            return $this->success($data, 'Leaderboard data retrieved successfully');
        } catch (\Throwable $e) {
            return $this->exceptionResponse('DashboardController : leaderboard', $e);
        }
    }

    public function agentPerformance(Request $request)
    {
        try {
            $request->validate([
                'startDate' => 'required|date',
                'endDate' => 'required|date|after_or_equal:startDate',
                'agent' => 'nullable|integer|exists:users,id',
            ]);

            $data = $this->dashboardService->agentPerformanceCharts($request);

            return $this->success($data, 'Dashboard performance chart data retrieved successfully');
        } catch (\Throwable $e) {
            return $this->exceptionResponse('DashboardController : agentPerformance', $e);
        }
    }

    public function agentMonitoring(Request $request)
    {
        try {
            $request->validate([
                'startDate' => 'nullable|date',
                'endDate' => 'nullable|date|after_or_equal:startDate',
                'agent' => 'nullable|integer|exists:users,id',
            ]);

            $agentId = (int) ($request->query('agent') ?: user()->id);

            $data = $this->dashboardService->agentMonitoring($request, $agentId);

            return $this->success($data, 'Agent performance monitoring data retrieved successfully');
        } catch (\Throwable $e) {
            return $this->exceptionResponse('DashboardController : agentMonitoring', $e);
        }
    }
}
