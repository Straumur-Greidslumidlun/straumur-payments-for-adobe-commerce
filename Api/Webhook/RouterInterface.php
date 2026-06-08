<?php
declare(strict_types=1);

namespace Straumur\Payment\Api\Webhook;

interface RouterInterface
{
    /**
     * Route webhook to appropriate processor
     *
     * @param array $payload
     * @param array $headers
     * @return array
     * @throws \Exception
     */
    public function route(array $payload, array $headers = []): array;

    /**
     * Register webhook processor
     *
     * @param ProcessorInterface $processor
     * @return void
     */
    public function registerProcessor(ProcessorInterface $processor): void;
}
