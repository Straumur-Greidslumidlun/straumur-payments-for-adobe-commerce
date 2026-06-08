<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Webhook;

use Straumur\Payment\Api\Webhook\RouterInterface;
use Straumur\Payment\Api\Webhook\ProcessorInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

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
    public function route(array $payload, array $headers = []): array
    {
        $this->logger->info('Routing webhook', ['payload' => $payload]);

        foreach ($this->processors as $processor) {
            if ($processor->canProcess($payload)) {
                $this->logger->info('Found processor', ['processor' => get_class($processor)]);
                return $processor->process($payload, $headers);
            }
        }

        throw new LocalizedException(__('No processor found for webhook payload'));
    }

    /**
     * @inheritDoc
     */
    public function registerProcessor(ProcessorInterface $processor): void
    {
        $this->processors[] = $processor;
    }
}