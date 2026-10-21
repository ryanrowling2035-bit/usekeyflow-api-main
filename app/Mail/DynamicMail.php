<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class DynamicMail extends Mailable
{
    public function __construct(
        public string $subjectText,
        public string $htmlBody,
        public array $fileAttachments = [],
        public ?string $fromEmail = null,
        public ?string $fromName = null
    ) {}

    public function build()
    {
        $this->subject($this->subjectText)
            ->html($this->htmlBody);

        if (! empty($this->fromEmail)) {
            $this->from($this->fromEmail, $this->fromName);
        }

        // Attach files if any
        foreach ($this->fileAttachments as $attachment) {
            if (is_array($attachment) && isset($attachment['path'])) {
                // Array format with path and name
                if (file_exists($attachment['path'])) {
                    $this->attach($attachment['path'], [
                        'as' => $attachment['name'] ?? basename($attachment['path']),
                    ]);
                }
            } elseif (is_string($attachment)) {
                // Simple string path format (backward compatibility)
                if (file_exists($attachment)) {
                    $this->attach($attachment);
                }
            }
        }

        return $this;
    }
}
