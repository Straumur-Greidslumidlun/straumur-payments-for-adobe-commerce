<?php
declare(strict_types=1);

namespace Straumur\Payment\Api\Webhook;

interface ValidatorInterface
{
    /**
     * Validate webhook payload and signature
     *
     * @param array $payload
     * @param array $headers
     * @return bool
     * @throws \Exception
     */
    public function validate(array $payload, array $headers): bool;

    /**
     * Get validation error message
     *
     * @return string
     */
    public function getErrorMessage(): string;
}
