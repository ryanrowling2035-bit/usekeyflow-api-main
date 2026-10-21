<?php

namespace App\Repositories;

use App\Models\Contact;
use App\Models\ContactNote;

class ContactNoteRepository
{
    public function create(array $data)
    {
        $note = ContactNote::create($data);

        return $note->load(['creator:id,name,email,profilePhoto']);
    }

    public function findById($id)
    {
        return ContactNote::with(['creator:id,name,email,profilePhoto', 'replies.creator:id,name,email,profilePhoto'])->find($id);
    }

    public function update($id, array $data)
    {
        $note = ContactNote::findOrFail($id);
        $note->update($data);

        return $note->fresh(['creator:id,name,email,profilePhoto', 'replies.creator:id,name,email,profilePhoto']);
    }

    public function delete($id)
    {
        $note = ContactNote::findOrFail($id);

        // Delete child replies if this is a parent note
        ContactNote::where('parent', $id)->delete();

        return $note->delete();
    }

    public function getByContactId($contactId, $type = null)
    {
        $contact = Contact::find($contactId);

        // Notes written against a contact that was later merged into this one still
        // belong to this person — merging moved nothing, so read the whole family.
        // Falls back to the raw id if the contact is gone, so nothing 500s here.
        $contactIds = $contact ? $contact->aliasIds() : [$contactId];

        $query = ContactNote::with(['creator:id,name,email,profilePhoto', 'replies.creator:id,name,email,profilePhoto'])
            ->whereIn('contactId', $contactIds)
            ->where('parent', 0);

        if ($type) {
            $query->where('type', $type);

            return $query->orderBy('id', 'desc')->get();
        }

        return $query->orderBy('id', 'desc')
            ->get()
            ->groupBy('type');
    }
}
