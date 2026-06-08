<?php
declare(strict_types=1);

namespace Straumur\Payment\Gateway\Request;

use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Straumur\Payment\Helper\Data as StraumurHelper;

class CaptureRequest implements BuilderInterface
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

        $payfacReference = $payment->getParentTransactionId() ?: $payment->getLastTransId();
        
        if (!$payfacReference) {
            throw new \InvalidArgumentException('PayfacReference is required for capture');
        }

        return [
            'operation' => 'capture',
            'amount' => $this->straumurHelper->formatAmount($amount, $order->getCurrencyCode()),
            'currency' => $order->getCurrencyCode(),
            'reference' => $order->getOrderIncrementId(),
            'payfacReference' => $payfacReference
        ];
    }
}