<?php

namespace App\Services;

/**
 * Result object for sites sync operations
 */
class SitesSyncResult
{
    public function __construct(
        public bool $success,
        public int $inserted,
        public int $updated,
        public int $failed,
        public ?string $errorMessage = null,
        public ?string $message = null
    ) {}

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'inserted' => $this->inserted,
            'updated' => $this->updated,
            'failed' => $this->failed,
            'error_message' => $this->errorMessage,
            'message' => $this->message,
        ];
    }
}
