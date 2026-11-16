<?php
declare(strict_types=1);

namespace Straumur\Payment\Api\Webhook;

use Magento\Sales\Api\Data\OrderInterface;

interface RouterInterface
{
    /**
     * Route a verified webhook for one of this shop's orders to the processor for its event type.
     *
     * Returns ['status' => 'ignored', 'reason' => 'unsupported_event'] when no processor handles it.
     *
     * @param array $payload
     * @param OrderInterface $order
     * @return array
     * @throws \Exception
     */
    public function route(array $payload, OrderInterface $order): array;

    /**
     * Register webhook processor
     *
     * @param ProcessorInterface $processor
     * @return void
     */
    public function registerProcessor(ProcessorInterface $processor): void;
}
