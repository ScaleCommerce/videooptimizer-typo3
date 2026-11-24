<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Api;

class ApiErrorException extends VideoOptimizerException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
