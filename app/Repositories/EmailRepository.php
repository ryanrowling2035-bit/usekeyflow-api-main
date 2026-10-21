<?php

namespace App\Repositories;

use App\Models\ManageEmail;

class EmailRepository
{
    public function getAll($request = null)
    {
        $query = ManageEmail::query()->with('creator');

        if (is_null($request)) {
            return $query->latest()->get();
        }

        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('slug', 'LIKE', "%{$search}%")
                    ->orWhere('subject', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('createdBy')) {
            $creator = $request->createdBy;

            $query->whereHas('creator', function ($q) use ($creator) {
                $q->where('name', 'LIKE', "%{$creator}%");
            });
        }

        if ($request->filled('slug')) {
            $query->where('slug', $request->slug);
        }

        return $query->latest()->paginate($perPage);
    }

    public function findById(int $id)
    {
        return ManageEmail::findOrFail($id);
    }

    public function create(array $data)
    {
        return ManageEmail::create($data);
    }

    public function update(ManageEmail $email, array $data)
    {
        $email->update($data);

        return $email;
    }

    public function delete(ManageEmail $email)
    {
        return $email->delete();
    }

    public function findBySlug(string $slug)
    {
        return ManageEmail::where('slug', $slug)->where('status', 'active')->first();
    }

    public function findBySlugs(array $slugs)
    {
        return ManageEmail::whereIn('slug', $slugs)->with('creator')->get();
    }
}
