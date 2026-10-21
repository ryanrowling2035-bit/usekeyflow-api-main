<?php

namespace App\Jobs;

use App\Constants\Constants;
use App\Models\Closing;
use App\Models\DocumentCenter;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyClosingDocumentsMissingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    public function __construct(public int $closingId, public int $actorId) {}

    public function handle(NotificationService $notificationService): void
    {
        $closing = Closing::find($this->closingId);
        if (! $closing) {
            return;
        }

        // Collect the document types already present on this closing. A type counts as
        // present whether the frontend stored it as docType or inside the tags array.
        $documents = DocumentCenter::where('module', Constants::MODULE_CLOSING)
            ->where('moduleId', $closing->id)
            ->get(['docType', 'tags']);

        $present = [];
        foreach ($documents as $doc) {
            if (! empty($doc->docType)) {
                $present[] = $doc->docType;
            }
            foreach ((array) $doc->tags as $tag) {
                $present[] = $tag;
            }
        }
        $present = array_unique($present);

        // Work out which mandatory documents are still missing.
        $missingKeys = array_values(array_diff(Constants::MANDATORY_CLOSING_DOCUMENTS, $present));
        if (empty($missingKeys)) {
            return;
        }

        $labels = Constants::DOCUMENT_TAGS[Constants::MODULE_CLOSING] ?? [];
        $missingLabels = array_map(fn ($key) => $labels[$key] ?? $key, $missingKeys);

        // Recipients: assigned agent + all active admins.
        $adminRoleIds = Role::where('name', Constants::SUPER_ADMIN)->pluck('id');
        $recipientIds = User::whereIn('roleId', $adminRoleIds)
            ->where('status', Constants::USER_STATUS_ACTIVE)
            ->pluck('id')
            ->all();

        if ($closing->assignedAgentId) {
            $recipientIds[] = $closing->assignedAgentId;
        }

        $recipientIds = array_values(array_unique(array_filter($recipientIds)));
        if (empty($recipientIds)) {
            return;
        }

        $closingRef = $closing->referenceNo ?? (string) $closing->id;
        $closingLink = rtrim(env('FRONTEND_URL', config('app.url')), '/').'/dashboard/closing/'.$closing->id.'/view';
        $missingText = implode(', ', $missingLabels);
        $requiredAction = 'Please upload the missing mandatory documents to complete this closing.';

        foreach ($recipientIds as $userId) {
            $notificationService->notifyUser(
                userId: $userId,
                actorId: $this->actorId,
                type: Constants::NOTIFICATION_TYPES['closing_documents_missing'],
                title: "Missing closing documents: {$closingRef}",
                message: "Closing {$closingRef} is missing mandatory documents: {$missingText}. {$requiredAction}",
                module: Constants::MODULE_CLOSING,
                moduleId: $closing->id,
                extraData: [
                    'closingId' => $closing->id,
                    'closingReference' => $closingRef,
                    'missingDocuments' => $missingLabels,
                    'requiredAction' => $requiredAction,
                    'closingLink' => $closingLink,
                ]
            );
        }
    }
}
