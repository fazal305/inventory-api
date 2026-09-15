<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A business-layer error that already knows how it should look as an HTTP
 * response. Services and Validators throw this instead of calling
 * ApiResponse directly, which keeps them HTTP-agnostic — a Service should
 * be testable without a request/response cycle at all. The single
 * exception handler in public/index.php is the only place that turns this
 * into an actual JSON response (see rule 20: centralized error handling).
 */
class ApiException extends \RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $statusCode = 400,
        private readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function details(): ?array
    {
        return $this->details;
    }
}
