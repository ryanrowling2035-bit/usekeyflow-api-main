<?php

namespace App\Http\Controllers\Api\V1;

use App\Constants\Constants;
use App\Models\Closing;
use App\Models\Enquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PipelineController extends BaseV1Controller
{
    /**
     * Standard stage definitions and probabilities.
     */
    private const LEAD_STAGES = [
        Constants::ENQUIRY_NEW_LEAD => ['label' => 'New Lead', 'probability' => 5],
        Constants::ENQUIRY_CONTACTING => ['label' => 'Contacting', 'probability' => 10],
        Constants::ENQUIRY_PROPERTY_SEARCH => ['label' => 'Property Search', 'probability' => 30],
        Constants::ENQUIRY_THINKING_ABOUT_IT => ['label' => 'Thinking About It', 'probability' => 50],
        Constants::ENQUIRY_UNDER_OFFER => ['label' => 'Under Offer', 'probability' => 70],
        Constants::ENQUIRY_SALE_PROGRESSING => ['label' => 'Sale Progressing', 'probability' => 90],
        Constants::ENQUIRY_SOLD => ['label' => 'Sold', 'probability' => 100],
        Constants::ENQUIRY_RENTED => ['label' => 'Rented', 'probability' => 100],
        Constants::ENQUIRY_LOST => ['label' => 'Lost / Inactive', 'probability' => 0],
    ];

    /**
     * GET /v1/pipeline
     * Returns live pipeline summary by stage.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Optional agent filter
            $agentId = $request->input('agent_id');

            // 1. Group leads / enquiries by status
            $leadsQuery = Enquiry::query();
            if ($agentId) {
                $leadsQuery->where('agentId', $agentId);
            }

            $leadsByStatus = $leadsQuery
                ->select([
                    'status',
                    DB::raw('COUNT(*) as total_count'),
                    DB::raw('COALESCE(SUM(actualPrice), SUM(budget), 0) as total_value'),
                ])
                ->groupBy('status')
                ->get()
                ->keyBy('status');

            $stagesBreakdown = [];
            $totalActiveLeads = 0;
            $totalPipelineValue = 0.0;
            $totalWeightedValue = 0.0;

            foreach (self::LEAD_STAGES as $statusCode => $meta) {
                $row = $leadsByStatus->get($statusCode);
                $count = (int) ($row?->total_count ?? 0);
                $stageValue = (float) ($row?->total_value ?? 0);
                $probability = $meta['probability'];
                $weighted = ($stageValue * $probability) / 100;

                $isClosed = in_array($statusCode, [Constants::ENQUIRY_SOLD, Constants::ENQUIRY_RENTED, Constants::ENQUIRY_LOST]);
                if (! $isClosed) {
                    $totalActiveLeads += $count;
                    $totalPipelineValue += $stageValue;
                    $totalWeightedValue += $weighted;
                }

                $stagesBreakdown[] = [
                    'stage' => $statusCode,
                    'label' => $meta['label'],
                    'probability' => $probability,
                    'count' => $count,
                    'total_value' => round($stageValue, 2),
                    'weighted_value' => round($weighted, 2),
                    'is_closed' => $isClosed,
                ];
            }

            // 2. Deals / Closings pipeline by status
            $dealsQuery = Closing::query();
            if ($agentId) {
                $dealsQuery->where('assignedAgentId', $agentId);
            }

            $dealsByStatus = $dealsQuery
                ->select([
                    'status',
                    DB::raw('COUNT(*) as count'),
                    DB::raw('COALESCE(SUM(saleRentalPrice), 0) as total_deal_value'),
                    DB::raw('COALESCE(SUM(totalCommission), 0) as total_commission_value'),
                ])
                ->groupBy('status')
                ->get()
                ->map(fn ($row) => [
                    'status' => $row->status ?: 'unspecified',
                    'count' => (int) $row->count,
                    'total_deal_value' => round((float) $row->total_deal_value, 2),
                    'total_commission_value' => round((float) $row->total_commission_value, 2),
                ])
                ->values()
                ->all();

            $totalDealsCount = array_sum(array_column($dealsByStatus, 'count'));
            $totalDealsCommission = array_sum(array_column($dealsByStatus, 'total_commission_value'));

            $data = [
                'currency' => 'AED',
                'summary' => [
                    'total_active_pipeline_leads' => $totalActiveLeads,
                    'total_pipeline_value' => round($totalPipelineValue, 2),
                    'total_weighted_pipeline_value' => round($totalWeightedValue, 2),
                    'total_deals' => $totalDealsCount,
                    'total_deals_commission' => round($totalDealsCommission, 2),
                ],
                'lead_stages' => $stagesBreakdown,
                'deal_stages' => $dealsByStatus,
            ];

            return $this->success($data, 'Live pipeline summary retrieved successfully.');
        } catch (\Throwable $e) {
            return $this->handleException('PipelineController@index', $e);
        }
    }
}
