<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Webhook\Command;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Straumur\Payment\Api\Webhook\ProcessorInterface;
use Straumur\Payment\Gateway\Config\Config;
use Straumur\Payment\Model\Webhook\WebhookPayload;

/**
 * Base for webhook processors.
 *
 * The signature has been verified and the order resolved before a processor runs
 * (see Controller\Webhook\Index and Model\Webhook\OrderResolver).
 */
abstract class AbstractProcessor implements ProcessorInterface
{
    /**
     * @var Config
     */
    protected $gatewayConfig;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Config $gatewayConfig
     * @param OrderRepositoryInterface $orderRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $gatewayConfig,
        OrderRepositoryInterface $orderRepository,
        LoggerInterface $logger
    ) {
        $this->gatewayConfig = $gatewayConfig;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function process(array $payload, OrderInterface $order): array
    {
        $result = $this->processWebhook($payload, $order);

        $this->logger->info('Straumur webhook processed', [
            'event' => $this->getEventType(),
            'order_increment_id' => $order->getIncrementId(),
            'status' => $result['status'] ?? null,
        ]);

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function canProcess(array $payload, OrderInterface $order): bool
    {
        return $this->detectEventType($payload) === $this->getEventType();
    }

    /**
     * Process the webhook for specific event type
     *
     * @param array $payload
     * @param OrderInterface $order
     * @return array
     */
    abstract protected function processWebhook(array $payload, OrderInterface $order): array;

    /**
     * Get the event type this processor handles
     *
     * @return string
     */
    abstract protected function getEventType(): string;

    /**
     * Event type from additionalData.eventType, lower-cased.
     *
     * Straumur always sends it; without it the event is not guessed from the payload shape.
     *
     * @param array $payload
     * @return string
     */
    protected function detectEventType(array $payload): string
    {
        $eventType = WebhookPayload::eventType($payload);

        return $eventType === '' ? 'unknown' : strtolower($eventType);
    }

    /**
     * Whether the webhook reports success ("true" from Straumur).
     *
     * @param array $payload
     * @return bool
     */
    protected function isSuccess(array $payload): bool
    {
        return WebhookPayload::string($payload, 'success') === 'true';
    }

    /**
     * Update order status based on webhook result
     *
     * @param OrderInterface $order
     * @param bool $success
     * @param string $eventType
     * @return void
     */
    protected function updateOrderStatus(OrderInterface $order, bool $success, string $eventType): void
    {
        $config = $this->gatewayConfig->getEventConfig($eventType);
        $statusMapping = $config['status_mapping'] ?? [];

        if (isset($statusMapping[$success])) {
            $order->addCommentToStatusHistory(
                __('Payment %1 via webhook: %2', $eventType, $success ? 'successful' : 'failed')
            );

            if ($success) {
                $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
            } else {
                $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED);
            }
        }
    }
}
