<?php

namespace App\Repositories;

use App\Constants\Constants;
use App\Models\DocumentCenter;
use Illuminate\Http\Request;

class DocumentCenterRepository
{
    public function getAll(Request $request)
    {
        $perPage = $request->filled('per_page') && $request->per_page > 0
            ? $request->per_page
            : 10;

        $query = DocumentCenter::query()->with(['creator:id,name']);

        if ($request->filled('search')) {
            $search = $request->search;

            // Tags are stored as slugs (e.g. "ownersPassportCopy"), so a plain text
            // search never matches them. Map the search term to every tag slug whose
            // human label (or slug) matches, then also match documents carrying those
            // tags — so searching "Owner Passport" finds all passport-tagged docs.
            // Word-based match so "Owner Passport" still finds the "Owners Passport
            // Copy" tag (every search word must appear in the label or slug).
            $words = array_filter(preg_split('/\s+/', mb_strtolower(trim($search))));
            $matchingTagSlugs = [];
            $documentTags = Constants::DOCUMENT_TAGS;
            array_walk_recursive($documentTags, function ($label, $slug) use ($words, &$matchingTagSlugs) {
                if (empty($words)) {
                    return;
                }
                $haystack = mb_strtolower($label.' '.$slug);
                foreach ($words as $word) {
                    if (! str_contains($haystack, $word)) {
                        return;
                    }
                }
                $matchingTagSlugs[] = $slug;
            });

            $query->where(function ($q) use ($search, $matchingTagSlugs) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('referenceNo', 'LIKE', "%{$search}%")
                    ->orWhere('docType', 'LIKE', "%{$search}%");

                foreach ($matchingTagSlugs as $slug) {
                    $q->orWhereJsonContains('tags', $slug);
                }
            });
        }

        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }

        if ($request->filled('docType')) {
            $query->where('docType', $request->docType);
        }

        if ($request->filled('moduleId')) {
            $query->where('moduleId', $request->moduleId);
        }

        if ($request->filled('tags')) {
            $tags = is_array($request->tags) ? $request->tags : explode(',', $request->tags);
            $query->where(function ($q) use ($tags) {
                foreach ($tags as $tag) {
                    $q->whereJsonContains('tags', trim($tag));
                }
            });
        }

        $query->orderBy('id', 'desc');

        return $query->paginate($perPage);
    }

    public function create(array $data)
    {
        return DocumentCenter::create($data);
    }

    public function findById(int $id)
    {
        return DocumentCenter::with(['creator'])->find($id);
    }

    public function update(int $id, array $data)
    {
        $document = $this->findById($id);

        if (! $document) {
            return null;
        }

        $document->update($data);

        return $document;
    }

    public function delete(int $id): bool
    {
        $document = $this->findById($id);

        if (! $document) {
            return false;
        }

        return (bool) $document->delete();
    }
}
