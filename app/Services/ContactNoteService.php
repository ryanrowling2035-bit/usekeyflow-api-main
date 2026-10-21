<?php

namespace App\Services;

use App\Repositories\ContactNoteRepository;

class ContactNoteService
{
    public function __construct(
        protected ContactNoteRepository $contactNoteRepository
    ) {}

    public function create(array $data)
    {
        return $this->contactNoteRepository->create($data);
    }

    public function findById($id)
    {
        return $this->contactNoteRepository->findById($id);
    }

    public function update($id, array $data)
    {
        return $this->contactNoteRepository->update($id, $data);
    }

    public function delete($id)
    {
        return $this->contactNoteRepository->delete($id);
    }

    public function getByContactId($contactId, $type = null)
    {
        return $this->contactNoteRepository->getByContactId($contactId, $type);
    }
}
