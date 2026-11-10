<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Webhook;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Straumur\Payment\Gateway\Config\Config;

/**
 * Finds the order a payment webhook belongs to, or decides it is not an order of this shop.
 *
 * Straumur sends a merchant's webhook every payment on the contracts it is subscribed to: terminal
 * payments, payment links and other integrations as well as this shop's checkouts. An order is only
 * returned when the merchantReference is exactly its increment ID and it was paid with Straumur.
 */
class OrderResolver
{
    public const STORED_CHECKOUT_REFERENCE = 'straumur_checkout_reference';

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param LoggerInterface $logger
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->logger = $logger;
    }

    /**
     * The order this webhook is about, or null when it is not an order of this shop.
     *
     * @param array $payload
     * @return OrderInterface|null
     */
    public function resolve(array $payload): ?OrderInterface
    {
        $reference = WebhookPayload::field($payload, 'merchantReference');

        if (!is_string($reference) || $reference === '') {
            return null;
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(OrderInterface::INCREMENT_ID, $reference)
            ->create();

        $candidates = [];
        foreach ($this->orderRepository->getList($searchCriteria)->getItems() as $order) {
            // The database comparison ignores case and, depending on collation, trailing spaces.
            if ($order->getIncrementId() === $reference && $this->isPaidWithStraumur($order)) {
                $candidates[] = $order;
            }
        }

        if (count($candidates) === 0) {
            return null;
        }

        $checkoutReference = WebhookPayload::string($payload, 'checkoutReference');

        if (count($candidates) > 1) {
            // Increment IDs are only unique per store. Pick the one this checkout belongs to.
            $matching = array_values(array_filter(
                $candidates,
                function (OrderInterface $order) use ($checkoutReference): bool {
                    return $checkoutReference !== ''
                        && $this->storedCheckoutReference($order) === $checkoutReference;
                }
            ));

            if (count($matching) !== 1) {
                $this->logger->warning('Straumur webhook matches orders in several stores; none was updated.', [
                    'order_increment_id' => $reference,
                    'orders' => count($candidates),
                ]);
                return null;
            }

            return $matching[0];
        }

        $order = $candidates[0];
        $this->warnOnCheckoutReferenceMismatch($order, $checkoutReference);

        return $order;
    }

    /**
     * Whether the order's payment method is Straumur.
     *
     * @param OrderInterface $order
     * @return bool
     */
    private function isPaidWithStraumur(OrderInterface $order): bool
    {
        $payment = $order->getPayment();

        return $payment !== null && $payment->getMethod() === Config::CODE;
    }

    /**
     * Checkout reference saved when the Straumur session was created, or "" when there is none.
     *
     * @param OrderInterface $order
     * @return string
     */
    private function storedCheckoutReference(OrderInterface $order): string
    {
        $payment = $order->getPayment();
        $additionalInformation = $payment !== null ? (array) $payment->getAdditionalInformation() : [];
        $stored = $additionalInformation[self::STORED_CHECKOUT_REFERENCE] ?? '';

        return is_string($stored) ? $stored : '';
    }

    /**
     * Soft check: log when the webhook's checkout reference differs from the stored one, but still process.
     *
     * A new session for the same cart overwrites the stored reference, so a payment made in an earlier
     * session legitimately carries a different one.
     *
     * @param OrderInterface $order
     * @param string $checkoutReference
     * @return void
     */
    private function warnOnCheckoutReferenceMismatch(OrderInterface $order, string $checkoutReference): void
    {
        $stored = $this->storedCheckoutReference($order);

        if ($checkoutReference !== '' && $stored !== '' && $stored !== $checkoutReference) {
            $this->logger->warning('Straumur webhook checkout reference differs from the order\'s session.', [
                'order_increment_id' => $order->getIncrementId(),
            ]);
        }
    }
}
