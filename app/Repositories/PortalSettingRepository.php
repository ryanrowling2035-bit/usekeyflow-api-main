<?php

namespace App\Repositories;

use App\Models\PortalSetting;
use Illuminate\Http\Request;

class PortalSettingRepository
{
    public function getAll(Request $request)
    {
        $query = PortalSetting::query()->with('webhookSubscriptions');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('portal', 'LIKE', "%{$search}%")
                    ->orWhere('branchName', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('portal')) {
            $query->where('portal', $request->portal);
        }

        if ($request->filled('branchName')) {
            $query->where('branchName', $request->branchName);
        }

        return $query->orderBy('id')->get();
    }

    public function findById($id)
    {
        return PortalSetting::with('webhookSubscriptions')->find($id);
    }

    public function create(array $data)
    {
        return PortalSetting::create($data);
    }

    public function update(PortalSetting $setting, array $data)
    {
        $setting->update($data);

        return $setting;
    }

    public function delete(PortalSetting $setting)
    {
        return $setting->delete();
    }

    public function isPortalBranchExists(string $portal, string $branchName, $excludeId = null): bool
    {
        return PortalSetting::where('portal', $portal)
            ->where('branchName', $branchName)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists();
    }
}
