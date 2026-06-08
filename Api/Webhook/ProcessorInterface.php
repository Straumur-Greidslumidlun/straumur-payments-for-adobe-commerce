<?php
declare(strict_types=1);

namespace Straumur\Payment\Api\Webhook;

interface ProcessorInterface
{
    /**
     * Process webhook payload
     *
     * @param array $payload
     * @param array $headers
     * @return array
     * @throws \Exception
     */
    public function process(array $payload, array $headers = []): array;

    /**
     * Check if processor can handle this webhook
     *
     * @param array $payload
     * @return bool
     */
    public function canProcess(array $payload): bool;
}
