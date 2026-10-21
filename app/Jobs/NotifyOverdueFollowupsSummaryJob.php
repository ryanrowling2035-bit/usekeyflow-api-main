<?php

namespace App\Jobs;

use App\Constants\Constants;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\MailService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyOverdueFollowupsSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public function handle(NotificationService $notificationService, MailService $mailService): void
    {
        $today = Carbon::today()->toDateString();
        $threshold = Constants::OVERDUE_FOLLOWUP_THRESHOLD;
        $frontendUrl = rtrim(env('FRONTEND_URL', config('app.url')), '/');

        // Overdue follow-ups = open tasks (not closed/done) past their due date, grouped per agent.
        $grouped = Task::whereNotIn('status', Constants::TASK_CLOSED_STATUSES)
            ->whereNotNull('assignedTo')
            ->whereDate('dueDate', '<', $today)
            ->selectRaw('assignedTo, COUNT(*) as overdue_count, MIN(dueDate) as oldest_due')
            ->groupBy('assignedTo')
            ->havingRaw('COUNT(*) >= ?', [$threshold])
            ->get();

        if ($grouped->isEmpty()) {
            return;
        }

        $agentNames = User::whereIn('id', $grouped->pluck('assignedTo'))->pluck('name', 'id');

        // Build a per-agent summary row used by both the CRM notification and the email table.
        $agents = [];
        foreach ($grouped as $row) {
            $agents[] = [
                'agentId' => (int) $row->assignedTo,
                'agentName' => $agentNames[$row->assignedTo] ?? "User #{$row->assignedTo}",
                'overdueCount' => (int) $row->overdue_count,
                'oldestOverdueDate' => $row->oldest_due,
                'taskListLink' => $frontendUrl.'/dashboard/tasks?assignedTo='.$row->assignedTo,
            ];
        }

        // Recipients: all active managers.
        $managerRoleIds = Role::where('name', Constants::MANAGER)->pluck('id');
        $managers = User::whereIn('roleId', $managerRoleIds)
            ->where('status', Constants::USER_STATUS_ACTIVE)
            ->get(['id', 'name', 'email']);

        if ($managers->isEmpty()) {
            return;
        }

        $agentCount = count($agents);
        $message = "{$agentCount} agent(s) have overdue follow-ups beyond the threshold of {$threshold}.";
        $tableHtml = $this->buildTable($agents);

        foreach ($managers as $manager) {
            // CRM notification (summary)
            $notificationService->notifyUser(
                userId: $manager->id,
                actorId: $manager->id,
                type: Constants::NOTIFICATION_TYPES['overdue_followups'],
                title: 'Overdue follow-ups summary',
                message: $message,
                module: Constants::MODULE_TASK,
                moduleId: null,
                extraData: [
                    'threshold' => $threshold,
                    'agentCount' => $agentCount,
                    'agents' => $agents,
                ]
            );

            // Email summary
            if (empty($manager->email)) {
                continue;
            }

            try {
                $mailService->sendEmail(
                    Constants::EMAIL_SLUGS['task']['overdue_followups'],
                    $manager->email,
                    [
                        'manager_name' => ucwords($manager->name),
                        'threshold' => $threshold,
                        'agent_count' => $agentCount,
                        'followups_table' => $tableHtml,
                        'company_name' => getCompanyName(),
                    ]
                );
            } catch (\Exception $e) {
                Log::error('NotifyOverdueFollowupsSummaryJob : sendEmail : '.$e->getMessage());
            }
        }
    }

    private function buildTable(array $agents): string
    {
        $rows = '';
        $serial = 1;
        foreach ($agents as $agent) {
            $oldest = $agent['oldestOverdueDate']
                ? Carbon::parse($agent['oldestOverdueDate'])->format('F d, Y')
                : '-';
            $rows .= sprintf(
                '<tr>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%d</td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%d</td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;">%s</td>'
                .'<td style="padding:10px 12px; border:1px solid #e5e7eb;"><a href="%s">View tasks</a></td>'
                .'</tr>',
                $serial++,
                e($agent['agentName']),
                $agent['overdueCount'],
                e($oldest),
                e($agent['taskListLink']),
            );
        }

        return '<table style="width:100%; border-collapse:collapse; margin:20px 0; font-size:14px;">'
            .'<thead>'
            .'<tr style="background-color:#00002a; color:#ffffff;">'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">S.No</th>'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Agent</th>'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Overdue</th>'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Oldest Overdue</th>'
            .'<th style="padding:10px 12px; text-align:left; border:1px solid #e5e7eb;">Link</th>'
            .'</tr>'
            .'</thead>'
            .'<tbody>'.$rows.'</tbody>'
            .'</table>';
    }
}
