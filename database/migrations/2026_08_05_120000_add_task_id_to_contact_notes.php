<?php

use App\Constants\Constants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a nullable taskId to contact_notes so a call note created as a mirror
     * of a call task can be traced back to that task. KPI call counts add
     * standalone contact calls (taskId null) on top of call tasks; without this
     * link every call task would be counted twice (task + its mirror note).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('contact_notes', 'taskId')) {
            Schema::table('contact_notes', function (Blueprint $table) {
                $table->unsignedBigInteger('taskId')->nullable()->after('contactId')->index();
            });
        }

        $this->backfillMirrorTaskIds();
    }

    public function down(): void
    {
        if (Schema::hasColumn('contact_notes', 'taskId')) {
            Schema::table('contact_notes', function (Blueprint $table) {
                $table->dropColumn('taskId');
            });
        }
    }

    /**
     * Tag pre-existing mirror notes with their source call task so historical
     * KPI counts do not double count them. A mirror note shares the call task's
     * creator, type=call and content (subject + optional comments) and resolves
     * to the same contact. Matches each still-untagged note to a call task.
     */
    private function backfillMirrorTaskIds(): void
    {
        DB::table('tasks')
            ->where('type', 'call')
            ->whereNotNull('module')
            ->whereNotNull('moduleId')
            ->orderBy('id')
            ->chunkById(500, function ($tasks) {
                foreach ($tasks as $task) {
                    $contactId = $this->resolveContactId($task);

                    if (! $contactId) {
                        continue;
                    }

                    $content = trim($task->subject.($task->comments ? "\n".$task->comments : ''));

                    DB::table('contact_notes')
                        ->whereNull('taskId')
                        ->where('type', 'call')
                        ->where('createdBy', $task->createdBy)
                        ->where('contactId', $contactId)
                        ->where('content', $content)
                        ->limit(1)
                        ->update(['taskId' => $task->id]);
                }
            });
    }

    /**
     * Mirror of TaskService::resolveContactIdForTask, kept inline so the
     * backfill does not depend on service internals that may change later.
     */
    private function resolveContactId($task): ?int
    {
        return match ($task->module) {
            Constants::MODULE_CONTACT => (int) $task->moduleId,
            Constants::MODULE_ENQUIRY => DB::table('enquiries')->where('id', $task->moduleId)->value('contactId'),
            Constants::MODULE_PROPERTY => DB::table('properties')->where('id', $task->moduleId)->value('physicalOwnerId'),
            Constants::MODULE_OFFER => DB::table('offers')->where('id', $task->moduleId)->value('buyerId'),
            Constants::MODULE_CLOSING => DB::table('closings')->where('id', $task->moduleId)->value('buyerId'),
            default => null,
        };
    }
};
