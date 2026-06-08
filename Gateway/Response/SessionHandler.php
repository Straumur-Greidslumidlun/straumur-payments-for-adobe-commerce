<?php
declare(strict_types=1);

namespace Straumur\Payment\Gateway\Response;

use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Straumur\Payment\Model\Service\SessionManager;
use Magento\Store\Model\StoreManagerInterface;
use Straumur\Payment\Gateway\Config\Config;
use Psr\Log\LoggerInterface;

class SessionHandler implements HandlerInterface
{
    /**
     * @var SubjectReader
     */
    private $subjectReader;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var SessionManager
     */
    private $sessionManager;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var Config
     */
    private $config;

    /**
     * @param SubjectReader $subjectReader
     * @param SessionManager $sessionManager
     * @param StoreManagerInterface $storeManager
     * @param OrderRepositoryInterface $orderRepository
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        SubjectReader $subjectReader,
        SessionManager $sessionManager,
        StoreManagerInterface $storeManager,
        OrderRepositoryInterface $orderRepository,
        Config $config,
        LoggerInterface $logger
    ) {
        $this->subjectReader = $subjectReader;
        $this->sessionManager = $sessionManager;
        $this->storeManager = $storeManager;
        $this->orderRepository = $orderRepository;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function handle(array $handlingSubject, array $response): void
    {
        $this->logger->info('Processing session response', [
            'response' => $response,
            'has_url' => isset($response['url']),
            'has_reference' => isset($response['checkoutReference']),
            'response_keys' => array_keys($response)
        ]);

        if (empty($response)) {
            $this->logger->error('Empty response from Straumur API');
            throw new LocalizedException(__('Empty response from Straumur API'));
        }

        $paymentDO = $this->subjectReader->readPayment($handlingSubject);
        $payment = $paymentDO->getPayment();

        if (!$payment instanceof OrderPaymentInterface) {
            throw new LocalizedException(__('Invalid payment object'));
        }

        // Extract session data from API response
        $checkoutUrl = $response['url'] ?? '';
        $checkoutReference = $response['checkoutReference'] ?? '';
        $responseDateTime = $response['responseDateTime'] ?? '';
        $responseIdentifier = $response['responseIdentifier'] ?? '';

        if (empty($checkoutUrl)) {
            $this->logger->error('No checkout URL in Straumur API response', [
                'response' => $response,
                'keys' => array_keys($response)
            ]);
            throw new LocalizedException(__('No checkout URL received from Straumur API. Please check your configuration and try again.'));
        }

        if (empty($checkoutReference)) {
            throw new LocalizedException(__('No checkout reference received from Straumur'));
        }

        // Create secure session token for this checkout reference
        $expiresAt = null;
        // Extract expiresAt from original request if available
        if (isset($handlingSubject['original_request']['expiresAt'])) {
            $expiresAt = $handlingSubject['original_request']['expiresAt'];
        }
        
        $sessionToken = $this->sessionManager->createSession($checkoutReference, $expiresAt);
        
        // Generate secure URLs with session token
        $secureReturnUrl = $this->storeManager->getStore()->getUrl('straumur/payment/return', [
            'session_id' => $sessionToken
        ]);
        $secureAbandonUrl = $this->storeManager->getStore()->getUrl('straumur/payment/cancel', [
            'session_id' => $sessionToken
        ]);

        $this->logger->info('Secure URLs generated', [
            'session_token' => $sessionToken,
            'return_url' => $secureReturnUrl,
            'abandon_url' => $secureAbandonUrl
        ]);

        // Store session data in payment additional information
        $payment->setAdditionalInformation('straumur_checkout_url', $checkoutUrl);
        $payment->setAdditionalInformation('straumur_checkout_reference', $checkoutReference);
        $payment->setAdditionalInformation('straumur_session_token', $sessionToken);
        $payment->setAdditionalInformation('straumur_secure_return_url', $secureReturnUrl);
        $payment->setAdditionalInformation('straumur_secure_abandon_url', $secureAbandonUrl);
        $payment->setAdditionalInformation('straumur_response_datetime', $responseDateTime);
        $payment->setAdditionalInformation('straumur_response_identifier', $responseIdentifier);

        // Explicitly prevent any authorization flags being set during session creation
        $payment->setAdditionalInformation('straumur_session_created', true);
        $payment->setAdditionalInformation('straumur_payment_authorized', false);  // Explicitly false

        // Prevent Magento from adding "Authorized amount" message by not setting base amount
        // The amount will be set properly when webhook confirms authorization
        $payment->setBaseAmountAuthorized(0);
        $payment->setAmountAuthorized(0);

        // Get order
        $order = $payment->getOrder();

        // Only add session comment in debug mode
        if ($this->config->isDebugMode($order->getStoreId())) {
            $order->addCommentToStatusHistory(
                __('Straumur session created. Checkout Reference: %1', $checkoutReference),
                false  // Don't notify customer
            );
        }

        // CRITICAL: Save the order FIRST to persist checkout URL and session data
        // This ensures GetRedirectUrl can retrieve the checkout URL
        $this->orderRepository->save($order);

        // THEN force the order back to NEW/Pending state AFTER Magento's processing
        // This prevents Magento from auto-moving to Processing after successful command
        $order->setState(\Magento\Sales\Model\Order::STATE_NEW);
        $order->setStatus('pending');

        // Save again to persist the state/status change
        $this->orderRepository->save($order);

        $this->logger->info('[FLOW_TRACKER] SessionHandler - set order to new/pending', [
            'order_id' => $order->getIncrementId(),
            'state' => $order->getState(),
            'status' => $order->getStatus(),
            'checkout_reference' => $checkoutReference
        ]);

        $this->logger->info('Session handler completed - session created, payment pending', [
            'checkout_reference' => $checkoutReference,
            'checkout_url' => $checkoutUrl,
            'order_id' => $order->getId(),
            'order_increment_id' => $order->getIncrementId(),
            'note' => 'Order saved with checkout URL, awaiting customer payment'
        ]);

        // IMPORTANT: We must NOT let Magento think this payment is authorized
        // The session creation is just preparation - not actual authorization
        // Actual authorization happens via webhook after customer pays
    }
}
