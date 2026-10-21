<?php

namespace App\Services;

use App\Models\Contact;
use App\Repositories\LeadSourceRepository;
use Illuminate\Http\Request;

class LeadSourceService
{
    protected $repo;

    public function __construct(LeadSourceRepository $repo)
    {
        $this->repo = $repo;
    }

    public function getAll(Request $request)
    {
        return $this->repo->getAll($request);
    }

    public function getById($id)
    {
        return $this->repo->findById($id);
    }

    public function select()
    {
        return $this->repo->getActive();
    }

    public function create($data)
    {
        if ($this->repo->isNameExists($data['name'])) {
            return ['error' => 'Name already exists'];
        }

        return $this->repo->create($data);
    }

    public function update($id, $data)
    {
        $lead = $this->repo->findById($id);

        if (! $lead) {
            return null;
        }

        if ($this->repo->isNameExists($data['name'], $id)) {
            return ['error' => 'Name already exists'];
        }

        $lead->update($data);

        return $lead;
    }

    public function delete($id)
    {
        $lead = $this->repo->findById($id);

        if (! $lead) {
            return null;
        }

        $isUsed = Contact::where('leadSourceDetails', $lead->name)->exists();

        if ($isUsed) {
            return ['error' => 'Lead source is already used'];
        }
        $this->repo->delete($lead);

        return true;
    }
}
