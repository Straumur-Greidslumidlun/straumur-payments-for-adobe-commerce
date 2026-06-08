<?php
declare(strict_types=1);

namespace Straumur\Payment\Gateway\Response;

use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Straumur\Payment\Helper\Data as StraumurHelper;

class RefundHandler implements HandlerInterface
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var StraumurHelper
     */
    private $straumurHelper;

    /**
     * @param LoggerInterface $logger
     * @param StraumurHelper $straumurHelper
     */
    public function __construct(
        LoggerInterface $logger,
        StraumurHelper $straumurHelper
    ) {
        $this->logger = $logger;
        $this->straumurHelper = $straumurHelper;
    }

    /**
     * @inheritDoc
     */
    public function handle(array $handlingSubject, array $response): void
    {
        $this->logger->info('Processing refund response', ['response' => $response]);

        if (empty($response)) {
            throw new LocalizedException(__('Empty response from Straumur API'));
        }

        $paymentDO = SubjectReader::readPayment($handlingSubject);
        $payment = $paymentDO->getPayment();

        if (!$payment instanceof OrderPaymentInterface) {
            throw new LocalizedException(__('Invalid payment object'));
        }

        // Extract refund data from API response
        $status = $response['status'] ?? '';
        $payfacReference = $response['payfacReference'] ?? '';
        $responseDateTime = $response['responseDateTime'] ?? '';
        $responseIdentifier = $response['responseIdentifier'] ?? '';

        if (empty($payfacReference)) {
            throw new LocalizedException(__('No payfac reference received from Straumur'));
        }

        // Get refund amount from subject (what Magento requested)
        $amount = SubjectReader::readAmount($handlingSubject);

        // Calculate the actual amount sent to Straumur (after rounding for ISK, etc.)
        $order = $payment->getOrder();
        $currency = $order->getOrderCurrencyCode();
        $straumurAmountMinorUnits = $this->straumurHelper->formatAmount($amount, $currency);
        $straumurActualAmount = $this->straumurHelper->convertFromMinorUnits($straumurAmountMinorUnits, $currency);

        // Store refund data in payment additional information
        $payment->setAdditionalInformation('straumur_refund_status', $status);
        $payment->setAdditionalInformation('straumur_refund_payfac_reference', $payfacReference);
        $payment->setAdditionalInformation('straumur_refund_datetime', $responseDateTime);
        $payment->setAdditionalInformation('straumur_refund_identifier', $responseIdentifier);
        $payment->setAdditionalInformation('straumur_refund_amount', $straumurActualAmount);

        // Create refund transaction ID
        $refundTransactionId = $payfacReference . '-refund-' . time();
        $payment->setTransactionId($refundTransactionId);
        $payment->setIsTransactionClosed(true);

        // Update order status
        $order = $payment->getOrder();
        
        if (strtolower($status) === 'received') {
            $order->addCommentToStatusHistory(
                __('Refund request submitted. Amount: %1, Payfac Reference: %2', $straumurActualAmount, $payfacReference)
            );
        } else {
            $order->addCommentToStatusHistory(
                __('Refund request submitted. Status: %1, Amount: %2, Payfac Reference: %3', $status, $straumurActualAmount, $payfacReference)
            );
        }

        $this->logger->info('Refund handler completed', [
            'payfac_reference' => $payfacReference,
            'status' => $status,
            'amount_requested' => $amount,
            'amount_sent_to_straumur' => $straumurActualAmount,
            'order_id' => $order->getId()
        ]);
    }
}