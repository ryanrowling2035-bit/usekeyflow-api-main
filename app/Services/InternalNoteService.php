<?php

namespace App\Services;

use App\Models\InternalNote;
use App\Repositories\InternalNoteRepository;

class InternalNoteService
{
    public function __construct(
        protected InternalNoteRepository $internalNoteRepository
    ) {}

    public function create(array $data)
    {
        return $this->internalNoteRepository->create($data);
    }

    public function findById($id)
    {
        return $this->internalNoteRepository->findById($id);
    }

    public function update($id, array $data)
    {
        return $this->internalNoteRepository->update($id, $data);
    }

    public function delete($id)
    {
        return $this->internalNoteRepository->delete($id);
    }

    public function getByModule($module, $moduleId)
    {
        return $this->internalNoteRepository->getByModule($module, $moduleId);
    }

    /**
     * Sync notes for a module (adminComments, portalNotes etc.)
     * Creates new or updates existing note by type.
     */
    public function syncModuleNotes(string $module, int $moduleId, array $notes): void
    {
        foreach ($notes as $type => $content) {
            if ($content === null) {
                continue;
            }

            $existing = InternalNote::where('module', $module)
                ->where('moduleId', $moduleId)
                ->where('type', $type)
                ->where('parent', 0)
                ->first();

            if (empty($content)) {
                if ($existing) {
                    $existing->delete();
                }

                continue;
            }

            if ($existing) {
                $existing->update(['content' => $content]);
            } else {
                $this->create([
                    'module' => $module,
                    'moduleId' => $moduleId,
                    'content' => $content,
                    'type' => $type,
                    'parent' => 0,
                    'createdBy' => user()?->id,
                ]);
            }
        }
    }
}
