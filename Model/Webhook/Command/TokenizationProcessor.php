<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Webhook\Command;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\Data\PaymentTokenFactoryInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;

class TokenizationProcessor extends AbstractProcessor
{
    /**
     * @var PaymentTokenFactoryInterface
     */
    private $paymentTokenFactory;

    /**
     * @var PaymentTokenRepositoryInterface
     */
    private $paymentTokenRepository;

    /**
     * @param \Straumur\Payment\Api\Webhook\ValidatorInterface $validator
     * @param \Straumur\Payment\Gateway\Config\Config $gatewayConfig
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
     * @param \Psr\Log\LoggerInterface $logger
     * @param PaymentTokenFactoryInterface $paymentTokenFactory
     * @param PaymentTokenRepositoryInterface $paymentTokenRepository
     */
    public function __construct(
        \Straumur\Payment\Api\Webhook\ValidatorInterface $validator,
        \Straumur\Payment\Gateway\Config\Config $gatewayConfig,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Psr\Log\LoggerInterface $logger,
        PaymentTokenFactoryInterface $paymentTokenFactory,
        PaymentTokenRepositoryInterface $paymentTokenRepository
    ) {
        parent::__construct($validator, $gatewayConfig, $orderRepository, $searchCriteriaBuilder, $logger);
        $this->paymentTokenFactory = $paymentTokenFactory;
        $this->paymentTokenRepository = $paymentTokenRepository;
    }

    /**
     * @inheritDoc
     */
    protected function processWebhook(array $payload, OrderInterface $order): array
    {
        $successValue = $payload['success'] ?? $payload['Success'] ?? 'false';
        $success = ($successValue === 'true' || $successValue === true);
        $payfacReference = (string) ($payload['payfacReference'] ?? $payload['PayfacReference'] ?? '');
        $checkoutReference = (string) ($payload['checkoutReference'] ?? $payload['CheckoutReference'] ?? '');
        
        if ($success && $order->getCustomerId()) {
            $payment = $order->getPayment();
            
            try {
                $paymentToken = $this->paymentTokenFactory->create(PaymentTokenInterface::TYPE_CREDIT_CARD);
                $paymentToken->setCustomerId($order->getCustomerId());
                $paymentToken->setPaymentMethodCode('straumur_payment');
                $paymentToken->setGatewayToken($payfacReference);
                $paymentToken->setTokenDetails(json_encode([
                    'checkout_reference' => $checkoutReference,
                    'payfac_reference' => $payfacReference,
                    'type' => 'credit_card'
                ]));
                $paymentToken->setIsActive(true);
                $paymentToken->setIsVisible(true);
                
                $this->paymentTokenRepository->save($paymentToken);
                
                $order->addCommentToStatusHistory(
                    __('Payment method tokenized successfully. Token ID: %1', $payfacReference)
                );
                
                $payment->setAdditionalInformation('vault_payment_token', $paymentToken->getEntityId());
                
            } catch (\Exception $e) {
                $this->logger->error('Failed to save payment token', [
                    'order_id' => $order->getId(),
                    'customer_id' => $order->getCustomerId(),
                    'error' => $e->getMessage()
                ]);
                
                $order->addCommentToStatusHistory(
                    __('Payment tokenization failed: %1', $e->getMessage())
                );
            }
        } else {
            $reason = !$success ? 'Tokenization failed' : 'Guest order - no tokenization';
            $order->addCommentToStatusHistory(__('Payment tokenization: %1', $reason));
        }

        $this->orderRepository->save($order);

        return [
            'status' => $success ? 'success' : 'failed',
            'event_type' => 'tokenization',
            'order_id' => $order->getId(),
            'transaction_id' => $payfacReference,
            'checkout_reference' => $checkoutReference,
            'customer_id' => $order->getCustomerId()
        ];
    }

    /**
     * @inheritDoc
     */
    protected function getEventType(): string
    {
        return 'tokenization';
    }
}