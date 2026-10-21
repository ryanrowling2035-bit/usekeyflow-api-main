<?php

namespace App\Services;

use App\Repositories\BoostRepository;

class BoostService
{
    protected $repository;

    public function __construct(BoostRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getAll($request)
    {
        return $this->repository->getAll($request);
    }

    public function getById($id)
    {
        return $this->repository->findById($id);
    }

    public function create($data)
    {
        return $this->repository->create($data);
    }

    public function update($id, $data)
    {
        return $this->repository->update($id, $data);
    }

    public function delete($id)
    {
        return $this->repository->delete($id);
    }

    public function getBoostByPropertyId($propertyId)
    {
        return $this->repository->getByPropertyId($propertyId);
    }
}
