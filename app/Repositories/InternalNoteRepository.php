<?php

namespace App\Repositories;

use App\Models\InternalNote;

class InternalNoteRepository
{
    public function create(array $data)
    {
        $note = InternalNote::create($data);

        return $note->load(['creator:id,name,email,profilePhoto']);
    }

    public function findById($id)
    {
        return InternalNote::with(['creator:id,name,email,profilePhoto', 'child.creator:id,name,email,profilePhoto'])->find($id);
    }

    public function update($id, array $data)
    {
        $note = InternalNote::findOrFail($id);
        $note->update($data);

        return $note->fresh(['creator:id,name,email,profilePhoto', 'child.creator:id,name,email,profilePhoto']);
    }

    public function delete($id)
    {
        $note = InternalNote::findOrFail($id);

        InternalNote::where('parent', $id)->delete();

        return $note->delete();
    }

    public function getByModule($module, $moduleId)
    {
        return InternalNote::with(['creator:id,name,email,profilePhoto', 'child.creator:id,name,email,profilePhoto'])
            ->where('module', $module)
            ->where('moduleId', $moduleId)
            ->where('parent', 0)
            ->orderBy('id', 'desc')
            ->get();
    }
}
