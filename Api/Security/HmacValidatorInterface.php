<?php
declare(strict_types=1);

namespace Straumur\Payment\Api\Security;

interface HmacValidatorInterface
{
    /**
     * Validate HMAC signature for webhook payload
     *
     * @param array $payload
     * @param string $receivedSignature
     * @param string $secret
     * @return bool
     */
    public function validateSignature(array $payload, string $receivedSignature, string $secret): bool;

    /**
     * Calculate HMAC signature for payload
     *
     * @param array $payload
     * @param string $secret
     * @return string
     */
    public function calculateSignature(array $payload, string $secret): string;

    /**
     * Build message string from payload according to Straumur specification
     *
     * @param array $payload
     * @return string
     */
    public function buildMessageString(array $payload): string;
}
