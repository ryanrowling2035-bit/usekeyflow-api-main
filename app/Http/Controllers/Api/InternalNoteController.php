<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InternalNoteService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InternalNoteController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected InternalNoteService $internalNoteService
    ) {}

    public function addNote(Request $request)
    {
        $rules = [
            'module' => 'required|string',
            'moduleId' => 'required|integer',
            'content' => 'required|string',
            'type' => 'nullable|string',
            'parent' => 'nullable|integer|exists:internal_notes,id',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $data = $validator->validated();
            $data['createdBy'] = user()->id;
            $data['parent'] = $data['parent'] ?? 0;

            $note = $this->internalNoteService->create($data);

            logUserActivity([
                'action' => 'created',
                'module' => $data['module'],
                'moduleId' => $data['moduleId'],
                'description' => "Added internal note on {$data['module']} #{$data['moduleId']}",
            ]);

            return $this->success($note, 'Internal note added successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('InternalNoteController : addNote', $e);
        }
    }

    public function getNoteById($id)
    {
        try {
            $note = $this->internalNoteService->findById($id);

            if (! $note) {
                return $this->error('Internal note not found.', 422);
            }

            return $this->success($note, 'Internal note retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('InternalNoteController : getNoteById', $e);
        }
    }

    public function updateNote(Request $request, $id)
    {
        $note = $this->internalNoteService->findById($id);

        if (! $note) {
            return $this->error('Internal note not found.', 422);
        }

        $validator = Validator::make($request->all(), [
            'content' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $data = $validator->validated();
            $updatedNote = $this->internalNoteService->update($id, $data);

            logUserActivity([
                'action' => 'updated',
                'module' => $note->module,
                'moduleId' => $note->moduleId,
                'description' => "Updated internal note #{$id} on {$note->module} #{$note->moduleId}",
            ]);

            return $this->success($updatedNote, 'Internal note updated successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('InternalNoteController : updateNote', $e);
        }
    }

    public function deleteNote($id)
    {
        $note = $this->internalNoteService->findById($id);

        if (! $note) {
            return $this->error('Internal note not found.', 422);
        }

        try {
            $this->internalNoteService->delete($id);

            logUserActivity([
                'action' => 'deleted',
                'module' => $note->module,
                'moduleId' => $note->moduleId,
                'description' => "Deleted internal note #{$id} from {$note->module} #{$note->moduleId}",
            ]);

            return $this->success([], 'Internal note deleted successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('InternalNoteController : deleteNote', $e);
        }
    }

    public function getNotesByModule($module, $moduleId)
    {
        try {
            $notes = $this->internalNoteService->getByModule($module, $moduleId);

            return $this->success($notes, 'Internal notes retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('InternalNoteController : getNotesByModule', $e);
        }
    }
}
