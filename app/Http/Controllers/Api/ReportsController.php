<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reports\AgentProductivityReportService;
use App\Services\Reports\CompanyPerformanceReportService;
use App\Services\Reports\EnquiryConversionReportService;
use App\Services\Reports\LeadSourcePerformanceReportService;
use App\Services\Reports\ListingPerformanceReportService;
use App\Services\Reports\PipelineHealthReportService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AgentProductivityReportService $agentProductivity,
        protected CompanyPerformanceReportService $companyPerformance,
        protected EnquiryConversionReportService $enquiryConversion,
        protected LeadSourcePerformanceReportService $leadSourcePerformance,
        protected ListingPerformanceReportService $listingPerformance,
        protected PipelineHealthReportService $pipelineHealth,
    ) {}

    /**
     * Shared filter validation rules for the Agent Productivity report.
     *
     * @return array<string, string>
     */
    protected function filterRules(): array
    {
        return [
            'startDate' => 'nullable|date',
            'endDate' => 'nullable|date|after_or_equal:startDate',
            'teamId' => 'nullable|integer|exists:teams,id',
            'agentId' => 'nullable|integer|exists:users,id',
            'listingType' => 'nullable|string|max:50',
        ];
    }

    //  The paginated Agent Productivity report ({ currency, totals, agents, pagination }).

    public function agentProductivity(Request $request)
    {
        try {
            // Pagination applies to the agents table only; `totals` stay scope-wide.
            $request->validate($this->filterRules() + [
                'page' => 'nullable|integer|min:1',
                'perPage' => 'nullable|integer|min:1|max:100',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            $data = $this->agentProductivity->report($request);

            return $this->success($data, 'Agent productivity report retrieved successfully');
        } catch (ValidationException $e) {
            return $this->error('The given data was invalid.', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->exceptionResponse('ReportsController : agentProductivity', $e);
        }
    }

    //  The complete Company Performance Summary report ({ currency, kpis, ...charts }).

    public function companyPerformance(Request $request)
    {
        try {
            // Company-wide report — SuperAdmin / Admin / Manager only.
            if (! isAdmin() && ! isManager()) {
                return $this->error('You are not authorised to view this report.', 403);
            }

            $request->validate($this->filterRules());

            $data = $this->companyPerformance->report($request);

            return $this->success($data, 'Company performance report retrieved successfully');
        } catch (ValidationException $e) {
            return $this->error('The given data was invalid.', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->exceptionResponse('ReportsController : companyPerformance', $e);
        }
    }

    //  The complete Enquiry Conversion report ({ kpis, enquiryTypes }).

    public function enquiryConversion(Request $request)
    {
        try {
            $request->validate($this->enquiryConversionRules());

            $data = $this->enquiryConversion->report($request);

            return $this->success($data, 'Enquiry conversion report retrieved successfully');
        } catch (ValidationException $e) {
            return $this->error('The given data was invalid.', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->exceptionResponse('ReportsController : enquiryConversion', $e);
        }
    }

    //  The paginated Pipeline Health report ({ currency, period, stages, summary, deals, pagination }).

    public function pipelineHealth(Request $request)
    {
        try {
            $request->validate($this->pipelineRules());

            $data = $this->pipelineHealth->report($request);

            return $this->success($data, 'Pipeline health report retrieved successfully');
        } catch (ValidationException $e) {
            return $this->error('The given data was invalid.', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->exceptionResponse('ReportsController : pipelineHealth', $e);
        }
    }

    /**
     * Export all filtered open deals as a CSV (no pagination).
     */
    public function pipelineHealthExport(Request $request): StreamedResponse
    {
        $request->validate($this->pipelineRules());

        $rows = $this->pipelineHealth->exportRows($request);

        $columns = [
            'dealId' => 'Deal ID',
            'enquiryId' => 'Enquiry ID',
            'stage' => 'Stage',
            'enquiryType' => 'Enquiry Type',
            'dealValue' => 'Deal Value (AED)',
            'commissionRate' => 'Commission Rate',
            'grossCommission' => 'Gross Commission (AED)',
            'probability' => 'Stage Probability',
            'weightedValue' => 'Weighted Value (AED)',
            'daysInStage' => 'Days in Stage',
            'agent' => 'Agent Assigned',
            'listingType' => 'Listing Type',
            'lastMovementDate' => 'Last Stage Movement Date',
            'stagnant' => 'Stagnant Flag',
        ];

        return $this->streamCsv($rows, $columns, 'pipeline-health-report');
    }

    /**
     * Validation rules for the Pipeline Health report.
     *
     * @return array<string, string>
     */
    protected function pipelineRules(): array
    {
        return [
            'startDate' => 'nullable|date',
            'endDate' => 'nullable|date|after_or_equal:startDate',
            'agentId' => 'nullable|integer|exists:users,id',
            'teamId' => 'nullable|integer|exists:teams,id',
            'listingType' => 'nullable|string|max:50',
            'stage' => 'nullable|string|max:50',
            // Accept common boolean-ish strings ("true"/"false" as well as 1/0);
            // the service normalises via filter_var.
            'stagnantOnly' => 'nullable|in:0,1,true,false',
            'sortBy' => 'nullable|string|in:dealId,stageLabel,enquiryType,dealValue,commissionRate,grossCommission,probability,weightedValue,daysInStage,agent,listingType,lastMovementDate',
            'sortDir' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1',
            'perPage' => 'nullable|integer|min:1|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }

    //  The paginated Listing Performance report ({ currency, period, listings, pagination }).

    public function listingPerformance(Request $request)
    {
        try {
            $request->validate($this->listingRules());

            $data = $this->listingPerformance->report($request);

            return $this->success($data, 'Listing performance report retrieved successfully');
        } catch (ValidationException $e) {
            return $this->error('The given data was invalid.', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->exceptionResponse('ReportsController : listingPerformance', $e);
        }
    }

    /**
     * Export all filtered listings as a CSV (no pagination).
     */
    public function listingPerformanceExport(Request $request): StreamedResponse
    {
        $request->validate($this->listingRules());

        $rows = $this->listingPerformance->exportRows($request);

        $columns = [
            'reference' => 'Listing Reference',
            'agent' => 'Agent',
            'status' => 'Status',
            'community' => 'Community',
            'propertyType' => 'Property Type',
            'listingType' => 'Listing Type',
            'beds' => 'Beds',
            'daysOnMarket' => 'Days on Market',
            'enquiriesReceived' => 'Enquiries Received',
            'viewings' => 'Viewings',
            'offersReceived' => 'Offers Received',
            'listingPrice' => 'Listing Price (AED)',
            'finalPrice' => 'Final Price (AED)',
            'finalVsListing' => 'Final Price vs Listing (%)',
        ];

        return $this->streamCsv($rows, $columns, 'listing-performance-report');
    }

    /**
     * Validation rules for the Listing Performance report.
     *
     * @return array<string, string>
     */
    protected function listingRules(): array
    {
        return [
            'startDate' => 'nullable|date',
            'endDate' => 'nullable|date|after_or_equal:startDate',
            'agentId' => 'nullable|integer|exists:users,id',
            'teamId' => 'nullable|integer|exists:teams,id',
            'listingType' => 'nullable|string|max:50',
            'status' => 'nullable|string|in:Active,Under Offer,Sold,Withdrawn',
            'city' => 'nullable|string|max:150',
            'community' => 'nullable|string|max:150',
            'propertyType' => 'nullable|string|max:50',
            'sortBy' => 'nullable|string|in:reference,status,listingType,beds,listingPrice,agent,community,propertyType,daysOnMarket,enquiriesReceived,viewings,offersReceived,finalPrice',
            'sortDir' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1',
            'perPage' => 'nullable|integer|min:1|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }

    //  The complete Lead Source Performance report ({ currency, totals, sources }).

    public function leadSourcePerformance(Request $request)
    {
        try {
            $request->validate($this->leadSourceRules());

            $data = $this->leadSourcePerformance->report($request);

            return $this->success($data, 'Lead source performance report retrieved successfully');
        } catch (ValidationException $e) {
            return $this->error('The given data was invalid.', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->exceptionResponse('ReportsController : leadSourcePerformance', $e);
        }
    }

    /**
     * Export the Lead Source Performance table as a CSV.
     */
    public function leadSourcePerformanceExport(Request $request): StreamedResponse
    {
        $request->validate($this->leadSourceRules());

        $rows = $this->leadSourcePerformance->exportRows($request);

        $columns = [
            'leadSource' => 'Lead Source',
            'technicalSource' => 'Technical Source',
            'sourceVariants' => 'Source Variant',
            'totalEnquiries' => 'Total Enquiries',
            'dealsClosed' => 'Deals Closed',
            'revenue' => 'Revenue (AED)',
            'conversionRate' => 'Conversion Rate (%)',
            'revenuePerLead' => 'Revenue per Lead (AED)',
        ];

        return $this->streamCsv($rows, $columns, 'lead-source-performance-report');
    }

    /**
     * Validation rules for the Lead Source Performance report (adds leadSource
     * and technicalSource to the shared filters).
     *
     * @return array<string, string>
     */
    protected function leadSourceRules(): array
    {
        return $this->filterRules() + [
            'leadSource' => 'nullable|string|max:100',
            'technicalSource' => 'nullable|string|max:50',
        ];
    }

    /**
     * Export the Enquiry Conversion table (per enquiry-type + stage) as a CSV.
     */
    public function enquiryConversionExport(Request $request): StreamedResponse
    {
        $request->validate($this->enquiryConversionRules());

        $rows = $this->enquiryConversion->exportRows($request);

        $columns = [
            'enquiryType' => 'Enquiry Type',
            'stage' => 'Stage',
            'totalEnquiries' => 'Total Enquiries',
            'lostReasons' => 'Lost Reason',
            'timeToConversionDays' => 'Time to Conversion (Days)',
            'avgResponseTimeHours' => 'Average Response Time (Hours)',
        ];

        return $this->streamCsv($rows, $columns, 'enquiry-conversion-report');
    }

    /**
     * Validation rules for the Enquiry Conversion report (adds enquiryType,
     * leadSource and stage to the shared filters).
     *
     * @return array<string, string>
     */
    protected function enquiryConversionRules(): array
    {
        return $this->filterRules() + [
            'enquiryType' => 'nullable|string|max:50',
            'leadSource' => 'nullable|string|max:100',
            'stage' => 'nullable|string|max:50',
        ];
    }

    /**
     * Export the per-agent breakdown as a CSV (opens in Excel), honouring the
     * same filters as the report.
     */
    public function agentProductivityExport(Request $request): StreamedResponse
    {
        $request->validate($this->filterRules());

        $rows = $this->agentProductivity->exportRows($request);

        // Column key => header label (defines order too).
        $columns = [
            'agentName' => 'Agent',
            'teamName' => 'Team',
            'totalActiveEnquiries' => 'Total Active Enquiries',
            'claimedActiveEnquiries' => 'Claimed Active Enquiries',
            'openGameActiveEnquiries' => 'Open Game Active Enquiries',
            'newLeads' => 'New Leads',
            'enquiriesInProgress' => 'Enquiries In Progress',
            'viewingsLogged' => 'Viewings Logged',
            'offersSubmitted' => 'Offers Submitted',
            'closingsCompleted' => 'Closings Completed',
            'revenueGenerated' => 'Revenue Generated (AED)',
            'pendingTasks' => 'Pending Tasks',
            'overdueTasks' => 'Overdue Tasks',
            'activityToDealRatio' => 'Activity-to-Deal Ratio (claimed : closed)',
        ];

        // Format the ratio column as "X.XX : 1" (or an em dash when there are no deals).
        $rows = collect($rows)->map(function ($row) {
            $ratio = $row['activityToDealRatio'] ?? null;
            $row['activityToDealRatio'] = ($ratio !== null && $ratio !== '') ? $ratio.' : 1' : '—';

            return $row;
        })->all();

        return $this->streamCsv($rows, $columns, 'agent-productivity-report');
    }

    /**
     * Stream a list of rows as a CSV download (opens in Excel). The $columns map
     * (key => header) defines both the header row and the column order.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $columns
     */
    protected function streamCsv(array $rows, array $columns, string $baseName): StreamedResponse
    {
        $filename = $baseName.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows, $columns) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM so Excel reads accented names, the "—" placeholder and the
            // "%" columns correctly instead of showing garbled characters.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, array_values($columns));

            foreach ($rows as $row) {
                $line = [];
                foreach (array_keys($columns) as $key) {
                    $line[] = (string) ($row[$key] ?? '');
                }
                fputcsv($handle, $line);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
