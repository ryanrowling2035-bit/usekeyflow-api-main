<?php

namespace App\Services;

use App\Exceptions\UserFacingException;
use App\Mail\DynamicMail;
use App\Repositories\EmailRepository;
use Illuminate\Support\Facades\Mail;

class EmailService
{
    public function __construct(
        protected EmailRepository $repository,
        protected GlobalSettingService $globalSettingService
    ) {}

    public function list($request)
    {
        return $this->repository->getAll($request);
    }

    public function create(array $data)
    {
        return $this->repository->create($data);
    }

    public function update(int $id, array $data)
    {
        $email = $this->repository->findById($id);

        return $this->repository->update($email, $data);
    }

    public function delete(int $id): bool
    {
        $email = $this->repository->findById($id);

        return $this->repository->delete($email);
    }

    public function getById(int $id)
    {
        return $this->repository->findById($id);
    }

    public function getBySlug(string $slug)
    {
        return $this->repository->findBySlug($slug);
    }

    public function getBySlugs(array $slugs)
    {
        return $this->repository->findBySlugs($slugs);
    }

    public function sendEmailUsingTemplate(
        string $slug,
        string $recipientEmail,
        array $templateData = []
    ): bool {
        $template = $this->repository->findBySlug($slug);

        if (! $template) {
            throw new UserFacingException('Email template not found or inactive');
        }

        $emailBody = $this->replaceTemplateMacros(
            $template->emailContent,
            $templateData
        );

        if (function_exists('emailpdf_layout_wrap')) {
            $emailBody = emailpdf_layout_wrap($emailBody);
        }

        Mail::to($recipientEmail)->send(
            new DynamicMail($template->subject, $emailBody)
        );

        return true;
    }

    private function replaceTemplateMacros(
        string $content,
        array $data
    ): string {
        foreach ($data as $key => $value) {
            $content = str_replace('{{'.$key.'}}', $value, $content);
        }

        return $content;
    }
}
