<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Api;

class ApiErrorException extends VideoOptimizerException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        ?\Throwable $previous = null,
        private readonly ?string $apiCode = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    /**
     * Machine-readable error code of the API (`data.code`), e.g. `source_url_video_platform`.
     */
    public function getApiCode(): ?string
    {
        return $this->apiCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
