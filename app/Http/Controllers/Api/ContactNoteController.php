<?php

namespace App\Http\Controllers\Api;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\ContactNoteService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ContactNoteController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ContactNoteService $contactNoteService
    ) {}

    public function addNote(Request $request)
    {
        $rules = [
            'contactId' => 'required|exists:contacts,id',
            'content' => 'nullable|string|required_unless:type,call',
            'type' => 'required|in:'.implode(',', array_keys(Constants::CONTACT_NOTE_TYPE)),
            'parent' => 'nullable|integer|exists:contact_notes,id',
            'callStatus' => 'nullable|in:'.implode(',', array_keys(Constants::CONTACT_NOTE_CALL_STATUS)),
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        // Agents may only add notes to their assigned contacts.
        if (isAgent() && ! Contact::whereKey($request->contactId)->accessibleByAgent(user()->id)->exists()) {
            return $this->error('You do not have access to this contact.', 403);
        }

        try {
            $data = $validator->validated();
            $data['createdBy'] = user()->id;
            $data['parent'] = $data['parent'] ?? 0;

            if ($data['type'] !== 'call') {
                unset($data['callStatus']);
            }

            $note = $this->contactNoteService->create($data);

            logUserActivity([
                'action' => 'created',
                'module' => Constants::MODULE_CONTACT,
                'moduleId' => $data['contactId'],
                'description' => "Added {$data['type']} note on contact #{$data['contactId']}",
            ]);

            return $this->success($note, 'Note added successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactNoteController : addNote', $e);
        }
    }

    public function getNoteById($id)
    {
        try {
            $note = $this->contactNoteService->findById($id);

            if (! $note) {
                return $this->error('Note not found.', 422);
            }

            return $this->success($note, 'Note retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactNoteController : getNoteById', $e);
        }
    }

    public function updateNote(Request $request, $id)
    {
        $note = $this->contactNoteService->findById($id);

        if (! $note) {
            return $this->error('Note not found.', 422);
        }

        $validator = Validator::make($request->all(), [
            // Allow call logs / status-only updates to be saved without notes.
            'content' => 'sometimes|nullable|string',
            'type' => 'sometimes|required|in:'.implode(',', array_keys(Constants::CONTACT_NOTE_TYPE)),
            'callStatus' => 'nullable|in:'.implode(',', array_keys(Constants::CONTACT_NOTE_CALL_STATUS)),
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $data = $validator->validated();

            if (isset($data['type']) && $data['type'] !== 'call') {
                $data['callStatus'] = null;
            }

            $updatedNote = $this->contactNoteService->update($id, $data);

            logUserActivity([
                'action' => 'updated',
                'module' => Constants::MODULE_CONTACT,
                'moduleId' => $note->contactId,
                'description' => "Updated note #{$id} on contact #{$note->contactId}",
            ]);

            return $this->success($updatedNote, 'Note updated successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactNoteController : updateNote', $e);
        }
    }

    public function deleteNote($id)
    {
        $note = $this->contactNoteService->findById($id);

        if (! $note) {
            return $this->error('Note not found.', 422);
        }

        try {
            $this->contactNoteService->delete($id);

            logUserActivity([
                'action' => 'deleted',
                'module' => Constants::MODULE_CONTACT,
                'moduleId' => $note->contactId,
                'description' => "Deleted note #{$id} from contact #{$note->contactId}",
            ]);

            return $this->success([], 'Note deleted successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactNoteController : deleteNote', $e);
        }
    }

    public function getNotesByContact(Request $request, $contactId)
    {
        try {
            // Agents may only see notes for their assigned contacts.
            if (isAgent() && ! Contact::whereKey($contactId)->accessibleByAgent(user()->id)->exists()) {
                return $this->error('You do not have access to this contact.', 403);
            }

            $type = $request->query('type');
            $notes = $this->contactNoteService->getByContactId($contactId, $type);

            if ($type === 'call') {
                $callStatusLabels = Constants::CONTACT_NOTE_CALL_STATUS;
                $notes->each(function ($note) use ($callStatusLabels) {
                    $note->callStatusLabel = $callStatusLabels[$note->callStatus] ?? $note->callStatus;
                });
            }

            return $this->success($notes, 'Notes retrieved successfully.');
        } catch (\Exception $e) {
            return $this->exceptionResponse('ContactNoteController : getNotesByContact', $e);
        }
    }
}
