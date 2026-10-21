<?php

namespace App\Repositories;

use App\Models\LeadSource;
use Illuminate\Http\Request;

class LeadSourceRepository
{
    public function getAll(Request $request)
    {
        $query = LeadSource::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('name', 'LIKE', "%{$search}%");
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return $query->latest()->get();
    }

    public function findById($id)
    {
        return LeadSource::find($id);
    }

    public function getActive()
    {
        return LeadSource::where('status', 'active')
            ->orderBy('name', 'asc')
            ->get(['id', 'name']);
    }

    public function create($data)
    {
        return LeadSource::create($data);
    }

    public function update($id, $data)
    {
        $lead = $this->findById($id);
        if (! $lead) {
            return null;
        }

        $lead->update($data);

        return $lead;
    }

    public function delete($lead)
    {
        return $lead->delete();
    }

    public function isNameExists($name, $id = null)
    {
        return LeadSource::where('name', $name)
            ->when($id, fn ($q) => $q->where('id', '!=', $id))
            ->exists();
    }
}
