<?php
declare(strict_types=1);

namespace Straumur\Payment\Gateway\Request;

use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Straumur\Payment\Helper\Data as StraumurHelper;

class RefundRequest implements BuilderInterface
{
    /**
     * @var StraumurHelper
     */
    private $straumurHelper;

    /**
     * @param StraumurHelper $straumurHelper
     */
    public function __construct(
        StraumurHelper $straumurHelper
    ) {
        $this->straumurHelper = $straumurHelper;
    }

    /**
     * @param array $buildSubject
     * @return array
     */
    public function build(array $buildSubject): array
    {
        $paymentDO = SubjectReader::readPayment($buildSubject);
        $payment = $paymentDO->getPayment();
        $order = $paymentDO->getOrder();
        $amount = SubjectReader::readAmount($buildSubject);

        // For refunds, we need the original authorization transaction ID
        // Use the explicitly stored authorization reference first
        $payfacReference = $payment->getAdditionalInformation('straumur_authorization_reference');

        // Fallback to old method for backward compatibility with orders before this fix
        if (!$payfacReference) {
            $payfacReference = $this->getAuthorizationTransactionId($payment);
        }

        if (!$payfacReference) {
            throw new \InvalidArgumentException('Authorization transaction ID is required for refund');
        }

        // Log which reference is being used for troubleshooting
        \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Psr\Log\LoggerInterface::class)
            ->info('Refund using authorization reference', [
                'order_id' => $order->getOrderIncrementId(),
                'payfac_reference' => $payfacReference,
                'source' => $payment->getAdditionalInformation('straumur_authorization_reference') ? 'explicit' : 'fallback'
            ]);

        return [
            'operation' => 'refund',
            'reference' => $order->getOrderIncrementId(),
            'payfacReference' => $payfacReference,
            'amount' => $this->straumurHelper->formatAmount($amount, $order->getCurrencyCode()),
            'currency' => $order->getCurrencyCode(),
            'refundReason' => 'CUSTOMER REQUEST'
        ];
    }

    /**
     * Get the authorization transaction ID from the payment
     *
     * Walks up the transaction chain to find the authorization transaction,
     * which is required by Straumur for refund operations.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @return string|null
     */
    private function getAuthorizationTransactionId($payment): ?string
    {
        // First, try to get the authorization transaction directly
        $authTransaction = $payment->getAuthorizationTransaction();
        if ($authTransaction) {
            $authTxnId = $authTransaction->getTxnId();
            \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Psr\Log\LoggerInterface::class)
                ->info('Found authorization transaction directly', ['txn_id' => $authTxnId]);
            return $authTxnId;
        }

        // If no direct authorization transaction, walk up the parent chain
        $lastTxnId = $payment->getLastTransId();
        \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Psr\Log\LoggerInterface::class)
            ->info('Walking transaction chain', ['last_txn_id' => $lastTxnId]);

        $transaction = $payment->getTransaction($lastTxnId);
        $visited = []; // Prevent infinite loops

        while ($transaction) {
            $currentTxnId = $transaction->getTxnId();
            $txnType = $transaction->getTxnType();
            $parentTxnId = $transaction->getParentTxnId();

            \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Psr\Log\LoggerInterface::class)
                ->info('Checking transaction', [
                    'txn_id' => $currentTxnId,
                    'txn_type' => $txnType,
                    'parent_txn_id' => $parentTxnId
                ]);

            if ($txnType === \Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH) {
                \Magento\Framework\App\ObjectManager::getInstance()
                    ->get(\Psr\Log\LoggerInterface::class)
                    ->info('Found authorization in chain', ['txn_id' => $currentTxnId]);
                return $currentTxnId;
            }

            // Move to parent transaction
            if (!$parentTxnId || isset($visited[$parentTxnId])) {
                break;
            }

            $visited[$parentTxnId] = true;
            $transaction = $payment->getTransaction($parentTxnId);
        }

        \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Psr\Log\LoggerInterface::class)
            ->warning('No authorization transaction found in chain');

        return null;
    }
}