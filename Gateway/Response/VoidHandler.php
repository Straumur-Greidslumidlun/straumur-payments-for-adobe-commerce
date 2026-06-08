<?php
declare(strict_types=1);

namespace Straumur\Payment\Gateway\Response;

use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class VoidHandler implements HandlerInterface
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function handle(array $handlingSubject, array $response): void
    {
        $this->logger->info('Processing void response', ['response' => $response]);

        if (empty($response)) {
            throw new LocalizedException(__('Empty response from Straumur API'));
        }

        $paymentDO = SubjectReader::readPayment($handlingSubject);
        $payment = $paymentDO->getPayment();

        if (!$payment instanceof OrderPaymentInterface) {
            throw new LocalizedException(__('Invalid payment object'));
        }

        $status = $response['status'] ?? '';
        $payfacReference = $response['payfacReference'] ?? '';
        $responseDateTime = $response['responseDateTime'] ?? '';

        if ($status !== 'Received') {
            throw new LocalizedException(__('Void request was not accepted by Straumur'));
        }

        $payment->setTransactionId($payfacReference . '-void');
        $payment->setIsTransactionClosed(true);
        $payment->setShouldCloseParentTransaction(true);

        $order = $payment->getOrder();
        $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED);
        $order->setStatus('canceled');
        
        $order->addCommentToStatusHistory(
            __('Payment cancellation request sent to Straumur. Transaction ID: %1', $payfacReference)
        );

        $payment->setAdditionalInformation('straumur_void_datetime', $responseDateTime);

        $this->logger->info('Void handler completed', [
            'payfac_reference' => $payfacReference,
            'order_id' => $order->getId()
        ]);
    }
}