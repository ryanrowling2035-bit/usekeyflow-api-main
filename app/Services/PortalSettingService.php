<?php

namespace App\Services;

use App\Repositories\PortalSettingRepository;
use Illuminate\Http\Request;

class PortalSettingService
{
    protected array $secretFields = [
        'bayutSecretToken' => 'bayutSecretTokenPublic',
        'pfApiKey' => 'pfApiKeyPublic',
        'pfApiSecret' => 'pfApiSecretPublic',
    ];

    public function __construct(
        protected PortalSettingRepository $repo
    ) {}

    public function getAll(Request $request)
    {
        return $this->repo->getAll($request);
    }

    public function getById($id)
    {
        return $this->repo->findById($id);
    }

    public function create(array $data)
    {
        if ($this->repo->isPortalBranchExists($data['portal'], $data['branchName'])) {
            return ['error' => 'Portal settings for this branch already exist'];
        }

        $data = $this->prepareSecrets($data);

        return $this->repo->create($data);
    }

    public function update($id, array $data)
    {
        $setting = $this->repo->findById($id);

        if (! $setting) {
            return null;
        }

        if (
            isset($data['portal'], $data['branchName'])
            && $this->repo->isPortalBranchExists($data['portal'], $data['branchName'], $id)
        ) {
            return ['error' => 'Portal settings for this branch already exist'];
        }

        $data = $this->prepareSecrets($data, $setting);

        return $this->repo->update($setting, $data);
    }

    public function delete($id)
    {
        $setting = $this->repo->findById($id);

        if (! $setting) {
            return null;
        }

        $this->repo->delete($setting);

        return true;
    }

    protected function prepareSecrets(array $data, $existing = null): array
    {
        foreach ($this->secretFields as $field => $publicField) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            if ($value === null || $value === '') {
                if ($existing) {
                    unset($data[$field], $data[$publicField]);
                } else {
                    $data[$field] = null;
                    $data[$publicField] = null;
                }

                continue;
            }

            $data[$publicField] = substr((string) $value, -4);
        }

        return $data;
    }
}
