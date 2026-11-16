<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Service;

use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use Straumur\Payment\Api\StraumurApiInterface;
use Straumur\Payment\Gateway\Config\Config;
use Straumur\Payment\Helper\Data as StraumurHelper;
use Straumur\Payment\Model\Webhook\Command\AuthorizationProcessor;
use Straumur\Payment\Model\Webhook\OrderResolver;

/**
 * Applies an embedded (headless) payment that was completed before its order was placed.
 *
 * In headless mode the Straumur session carries the quote's reserved order number before the order
 * exists. When the shopper pays first, the Authorization webhook finds no order and is acknowledged as
 * ignored, and Straumur does not send it again. So when the order is placed, Straumur is asked for the
 * session's status, and a payment it reports is applied as the webhook would have applied it.
 */
class EmbeddedPaymentReconciler
{
    /**
     * @var StraumurHelper
     */
    private $helper;

    /**
     * @var StraumurApiInterface
     */
    private $straumurApi;

    /**
     * @var AuthorizationProcessor
     */
    private $authorizationProcessor;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param StraumurHelper $helper
     * @param StraumurApiInterface $straumurApi
     * @param AuthorizationProcessor $authorizationProcessor
     * @param LoggerInterface $logger
     */
    public function __construct(
        StraumurHelper $helper,
        StraumurApiInterface $straumurApi,
        AuthorizationProcessor $authorizationProcessor,
        LoggerInterface $logger
    ) {
        $this->helper = $helper;
        $this->straumurApi = $straumurApi;
        $this->authorizationProcessor = $authorizationProcessor;
        $this->logger = $logger;
    }

    /**
     * Apply the session's payment to a just-placed headless order, if Straumur already has one.
     *
     * Never throws: the order is placed either way, and a payment that is not found here still arrives
     * by webhook if it happens later.
     *
     * @param OrderInterface $order
     * @return bool Whether a payment was applied
     */
    public function reconcile(OrderInterface $order): bool
    {
        $payment = $order->getPayment();
        if ($payment === null || $payment->getMethod() !== Config::CODE) {
            return false;
        }

        $storeId = (int) $order->getStoreId();
        if (!$this->helper->useQuoteSession($storeId)
            || $payment->getAdditionalInformation(PaymentStateManager::FLAG_PAYMENT_AUTHORIZED)
        ) {
            return false;
        }

        $checkoutReference = $payment->getAdditionalInformation(OrderResolver::STORED_CHECKOUT_REFERENCE);
        if (!is_string($checkoutReference) || $checkoutReference === '') {
            return false;
        }

        try {
            $status = $this->straumurApi->getEmbeddedStatus($checkoutReference, $storeId);
        } catch (\Throwable $e) {
            $this->logger->warning('Straumur session status unavailable at order placement; waiting for webhook.', [
                'order_increment_id' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        // Straumur fills payfacReference with the first approved authorization of the session.
        $payfacReference = $status['payfacReference'] ?? null;
        if (!is_string($payfacReference) || $payfacReference === '') {
            return false;
        }

        try {
            $this->authorizationProcessor->process([
                'merchantReference' => $order->getIncrementId(),
                'checkoutReference' => $checkoutReference,
                'payfacReference' => $payfacReference,
                'amount' => $payment->getAdditionalInformation('straumur_session_amount'),
                'currency' => $payment->getAdditionalInformation('straumur_session_currency'),
                'success' => 'true',
                'additionalData' => ['eventType' => 'Authorization'],
            ], $order);
        } catch (\Throwable $e) {
            $this->logger->error('Straumur payment found at order placement could not be applied.', [
                'order_increment_id' => $order->getIncrementId(),
                'payfac_reference' => $payfacReference,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        $this->logger->info('Straumur payment completed before order placement was applied.', [
            'order_increment_id' => $order->getIncrementId(),
            'payfac_reference' => $payfacReference,
        ]);

        return true;
    }
}
