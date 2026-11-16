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
     * Payment reference of the authorization, saved by PaymentStateManager::markPaymentAuthorized().
     */
    public const STORED_AUTHORIZATION_REFERENCE = 'straumur_authorization_reference';

    /**
     * Event types whose checkoutReference is the checkout session's (lower-case).
     */
    private const CHECKOUT_EVENTS = ['authorization', 'tokenization'];

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

        if (count($candidates) > 1) {
            // Increment IDs are only unique per store. Pick the one this payment belongs to.
            $matching = array_values(array_filter(
                $candidates,
                function (OrderInterface $order) use ($payload): bool {
                    return $this->belongsToPayment($order, $payload);
                }
            ));

            if (count($matching) !== 1) {
                $this->logger->warning('Straumur webhook matches orders in several stores; none was updated.', [
                    'order_increment_id' => $reference,
                    'event_type' => WebhookPayload::eventType($payload),
                    'orders' => count($candidates),
                ]);
                return null;
            }

            return $matching[0];
        }

        $order = $candidates[0];
        if ($this->isCheckoutEvent($payload)) {
            $this->warnOnCheckoutReferenceMismatch($order, WebhookPayload::string($payload, 'checkoutReference'));
        }

        return $order;
    }

    /**
     * Whether the event is about the checkout session itself, so its checkoutReference is the session's.
     *
     * For Capture, Refund and Adjustment Straumur sends the checkoutReference of that operation instead,
     * and the original payment's reference in additionalData.originalPayfacReference.
     *
     * @param array $payload
     * @return bool
     */
    private function isCheckoutEvent(array $payload): bool
    {
        return in_array(strtolower(WebhookPayload::eventType($payload)), self::CHECKOUT_EVENTS, true);
    }

    /**
     * Whether the order is the one this payment was made for, judged by a reference the order stored.
     *
     * @param OrderInterface $order
     * @param array $payload
     * @return bool
     */
    private function belongsToPayment(OrderInterface $order, array $payload): bool
    {
        if ($this->isCheckoutEvent($payload)) {
            $checkoutReference = WebhookPayload::string($payload, 'checkoutReference');

            return $checkoutReference !== ''
                && $this->storedReference($order, self::STORED_CHECKOUT_REFERENCE) === $checkoutReference;
        }

        $additionalData = WebhookPayload::field($payload, 'additionalData');
        $originalPayfacReference = is_array($additionalData)
            ? WebhookPayload::string($additionalData, 'originalPayfacReference')
            : '';

        return $originalPayfacReference !== ''
            && $this->storedReference($order, self::STORED_AUTHORIZATION_REFERENCE) === $originalPayfacReference;
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
     * A reference saved on the order's payment, or "" when there is none.
     *
     * @param OrderInterface $order
     * @param string $key
     * @return string
     */
    private function storedReference(OrderInterface $order, string $key): string
    {
        $payment = $order->getPayment();
        $additionalInformation = $payment !== null ? (array) $payment->getAdditionalInformation() : [];
        $stored = $additionalInformation[$key] ?? '';

        return is_string($stored) ? $stored : '';
    }

    /**
     * Soft check for checkout events: log a missing or different checkout reference, but still process.
     *
     * A new session for the same cart overwrites the stored reference, so a payment made in an earlier
     * session legitimately carries a different one. A missing one means the payment did not come from a
     * Straumur checkout (a terminal sale or payment link that used the order number as its reference).
     *
     * @param OrderInterface $order
     * @param string $checkoutReference
     * @return void
     */
    private function warnOnCheckoutReferenceMismatch(OrderInterface $order, string $checkoutReference): void
    {
        if ($checkoutReference === '') {
            $this->logger->warning(
                'Straumur webhook without a checkout reference matched a Straumur order; applied to it.',
                ['order_increment_id' => $order->getIncrementId()]
            );
            return;
        }

        $stored = $this->storedReference($order, self::STORED_CHECKOUT_REFERENCE);

        if ($stored !== '' && $stored !== $checkoutReference) {
            $this->logger->warning('Straumur webhook checkout reference differs from the order\'s session.', [
                'order_increment_id' => $order->getIncrementId(),
            ]);
        }
    }
}
