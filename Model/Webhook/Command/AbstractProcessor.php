<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Webhook\Command;

use Straumur\Payment\Api\Webhook\ProcessorInterface;
use Straumur\Payment\Api\Webhook\ValidatorInterface;
use Straumur\Payment\Gateway\Config\Config;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

abstract class AbstractProcessor implements ProcessorInterface
{
    /**
     * @var ValidatorInterface
     */
    protected $validator;

    /**
     * @var Config
     */
    protected $gatewayConfig;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param ValidatorInterface $validator
     * @param Config $gatewayConfig
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param LoggerInterface $logger
     */
    public function __construct(
        ValidatorInterface $validator,
        Config $gatewayConfig,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        LoggerInterface $logger
    ) {
        $this->validator = $validator;
        $this->gatewayConfig = $gatewayConfig;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function process(array $payload, array $headers = []): array
    {
        $this->logger->info('Processing webhook', ['event' => $this->getEventType(), 'payload' => $payload]);

        if (!$this->validator->validate($payload, $headers)) {
            throw new LocalizedException(__('Webhook validation failed: %1', $this->validator->getErrorMessage()));
        }

        // Handle both lowercase and uppercase field names for backward compatibility
        $merchantReference = $payload['merchantReference'] ?? $payload['MerchantReference'] ?? null;
        
        if (!$merchantReference) {
            throw new LocalizedException(__('Merchant reference not found in webhook payload'));
        }
        
        $order = $this->getOrderByReference($merchantReference);
        
        $result = $this->processWebhook($payload, $order);
        
        $this->logger->info('Webhook processed successfully', [
            'event' => $this->getEventType(),
            'order_id' => $order->getId(),
            'result' => $result
        ]);

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function canProcess(array $payload): bool
    {
        $eventType = $this->detectEventType($payload);
        return $eventType === $this->getEventType();
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
     * Detect event type from payload
     *
     * @param array $payload
     * @return string
     */
    protected function detectEventType(array $payload): string
    {
        // Check for event type in additionalData first (actual webhook structure)
        if (isset($payload['additionalData']['eventType'])) {
            return strtolower($payload['additionalData']['eventType']);
        }
        
        // Fallback to root level EventType (backward compatibility)
        if (isset($payload['EventType'])) {
            return strtolower($payload['EventType']);
        }
        
        // Also check lowercase version at root
        if (isset($payload['eventType'])) {
            return strtolower($payload['eventType']);
        }

        // Try to detect based on payload structure
        if (isset($payload['reason']) && !empty($payload['reason'])) {
            $success = isset($payload['success']) ? ($payload['success'] === 'true' || $payload['success'] === true) : false;
            return $success ? 'refund' : 'refund_failed';
        }

        if (isset($payload['amount']) && isset($payload['success'])) {
            $success = $payload['success'] === 'true' || $payload['success'] === true;
            return $success ? 'capture' : 'authorization';
        }

        return 'unknown';
    }

    /**
     * Get order by merchant reference (increment ID)
     *
     * @param string $incrementId
     * @return OrderInterface
     * @throws NoSuchEntityException
     */
    protected function getOrderByReference(string $incrementId): OrderInterface
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->create();

        $orders = $this->orderRepository->getList($searchCriteria)->getItems();
        
        if (empty($orders)) {
            throw new NoSuchEntityException(__('Order not found: %1', $incrementId));
        }

        return reset($orders);
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
            $status = $statusMapping[$success];
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