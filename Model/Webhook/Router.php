<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Webhook;

use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use Straumur\Payment\Api\Webhook\ProcessorInterface;
use Straumur\Payment\Api\Webhook\RouterInterface;

class Router implements RouterInterface
{
    /**
     * @var ProcessorInterface[]
     */
    private $processors = [];

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param LoggerInterface $logger
     * @param ProcessorInterface[] $processors
     */
    public function __construct(
        LoggerInterface $logger,
        array $processors = []
    ) {
        $this->logger = $logger;
        $this->processors = $processors;
    }

    /**
     * @inheritDoc
     */
    public function route(array $payload, OrderInterface $order): array
    {
        foreach ($this->processors as $processor) {
            if ($processor->canProcess($payload, $order)) {
                return $processor->process($payload, $order);
            }
        }

        $this->logger->info(
            'Straumur webhook event type has no processor; ignored.',
            WebhookPayload::logContext($payload) + ['order_increment_id' => $order->getIncrementId()]
        );

        return ['status' => 'ignored', 'reason' => 'unsupported_event'];
    }

    /**
     * @inheritDoc
     */
    public function registerProcessor(ProcessorInterface $processor): void
    {
        $this->processors[] = $processor;
    }
}
