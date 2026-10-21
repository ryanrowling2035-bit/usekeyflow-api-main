<?php

namespace App\Jobs;

use App\Constants\Constants;
use App\Models\Closing;
use App\Models\DocumentCenter;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyDocumentStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    /**
     * @param  string  $action  'uploaded' or 'removed'
     * @param  array  $uploadedDocTypes  document type keys that were just uploaded
     */
    public function __construct(
        public string $module,
        public int $moduleId,
        public string $action,
        public array $uploadedDocTypes,
        public int $actorId
    ) {}

    public function handle(NotificationService $notificationService): void
    {
        // Only offer / closing / listing(property) have mandatory-document rules.
        $mandatoryByModule = Constants::MANDATORY_DOCUMENTS_BY_MODULE;
        if (! array_key_exists($this->module, $mandatoryByModule)) {
            return;
        }

        [$agentId, $refLabel] = $this->resolveModule();
        if ($refLabel === null) {
            return; // parent record no longer exists
        }

        // Recipients: all active admins + the module's agent, excluding whoever made the change.
        $adminRoleIds = Role::where('name', Constants::SUPER_ADMIN)->pluck('id');
        $recipientIds = User::whereIn('roleId', $adminRoleIds)
            ->where('status', Constants::USER_STATUS_ACTIVE)
            ->pluck('id')
            ->all();

        if ($agentId) {
            $recipientIds[] = $agentId;
        }

        $recipientIds = array_values(array_unique(array_filter(
            $recipientIds,
            fn ($id) => $id && $id != $this->actorId
        )));

        if (empty($recipientIds)) {
            return;
        }

        $labels = Constants::DOCUMENT_TAGS[$this->module] ?? [];
        $moduleLabel = ucfirst($this->module);
        $link = rtrim(env('FRONTEND_URL', config('app.url')), '/').'/dashboard/'.$this->module.'/'.$this->moduleId.'/view';

        // 1) Document uploaded notification.
        if ($this->action === 'uploaded' && ! empty($this->uploadedDocTypes)) {
            $uploadedLabels = array_map(fn ($k) => $labels[$k] ?? $k, $this->uploadedDocTypes);
            $uploadedText = implode(', ', $uploadedLabels);

            foreach ($recipientIds as $userId) {
                $notificationService->notifyUser(
                    userId: $userId,
                    actorId: $this->actorId,
                    type: Constants::NOTIFICATION_TYPES['document_uploaded'],
                    title: "Document uploaded: {$refLabel}",
                    message: "{$uploadedText} uploaded to {$moduleLabel} {$refLabel}.",
                    module: $this->module,
                    moduleId: $this->moduleId,
                    extraData: [
                        'moduleId' => $this->moduleId,
                        'reference' => $refLabel,
                        'uploadedDocuments' => $uploadedLabels,
                        'link' => $link,
                    ]
                );
            }
        }

        // 2) Missing mandatory documents notification (re-checked on every upload/removal).
        $present = $this->presentDocTypes();
        $missingKeys = array_values(array_diff($mandatoryByModule[$this->module], $present));

        if (! empty($missingKeys)) {
            $missingLabels = array_map(fn ($k) => $labels[$k] ?? $k, $missingKeys);
            $missingText = implode(', ', $missingLabels);
            $requiredAction = 'Please upload the missing mandatory documents.';

            foreach ($recipientIds as $userId) {
                $notificationService->notifyUser(
                    userId: $userId,
                    actorId: $this->actorId,
                    type: Constants::NOTIFICATION_TYPES['document_missing'],
                    title: "Missing documents: {$refLabel}",
                    message: "{$moduleLabel} {$refLabel} is missing mandatory documents: {$missingText}. {$requiredAction}",
                    module: $this->module,
                    moduleId: $this->moduleId,
                    extraData: [
                        'moduleId' => $this->moduleId,
                        'reference' => $refLabel,
                        'missingDocuments' => $missingLabels,
                        'requiredAction' => $requiredAction,
                        'link' => $link,
                    ]
                );
            }
        }
    }

    /**
     * @return array{0: int|null, 1: string|null} [agentId, referenceLabel] — refLabel null if record gone.
     */
    private function resolveModule(): array
    {
        return match ($this->module) {
            Constants::MODULE_PROPERTY => (function () {
                $p = Property::find($this->moduleId);

                return $p ? [$p->agentId, $p->referenceNumber ?? (string) $p->id] : [null, null];
            })(),
            Constants::MODULE_CLOSING => (function () {
                $c = Closing::find($this->moduleId);

                return $c ? [$c->assignedAgentId, $c->referenceNo ?? (string) $c->id] : [null, null];
            })(),
            Constants::MODULE_OFFER => (function () {
                $o = Offer::find($this->moduleId);

                return $o ? [$o->assignedAgentId, $o->referenceNo ?? (string) $o->id] : [null, null];
            })(),
            default => [null, null],
        };
    }

    /**
     * All document type keys present on this record (from docType and tags).
     */
    private function presentDocTypes(): array
    {
        $documents = DocumentCenter::where('module', $this->module)
            ->where('moduleId', $this->moduleId)
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

        return array_unique($present);
    }
}
