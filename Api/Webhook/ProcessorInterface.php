<?php
declare(strict_types=1);

namespace Straumur\Payment\Api\Webhook;

use Magento\Sales\Api\Data\OrderInterface;

interface ProcessorInterface
{
    /**
     * Apply a verified webhook to the order it belongs to
     *
     * @param array $payload
     * @param OrderInterface $order
     * @return array
     * @throws \Exception
     */
    public function process(array $payload, OrderInterface $order): array;

    /**
     * Check if processor can handle this webhook for this order
     *
     * @param array $payload
     * @param OrderInterface $order
     * @return bool
     */
    public function canProcess(array $payload, OrderInterface $order): bool;
}
