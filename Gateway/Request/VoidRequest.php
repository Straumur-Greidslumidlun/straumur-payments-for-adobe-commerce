<?php
declare(strict_types=1);

namespace Straumur\Payment\Gateway\Request;

use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;

class VoidRequest implements BuilderInterface
{
    /**
     * @param array $buildSubject
     * @return array
     */
    public function build(array $buildSubject): array
    {
        $paymentDO = SubjectReader::readPayment($buildSubject);
        $payment = $paymentDO->getPayment();
        $order = $paymentDO->getOrder();

        $payfacReference = $payment->getParentTransactionId() ?: $payment->getLastTransId();
        
        if (!$payfacReference) {
            throw new \InvalidArgumentException('PayfacReference is required for void');
        }

        return [
            'operation' => 'reverse',
            'reference' => $order->getOrderIncrementId(),
            'payfacReference' => $payfacReference
        ];
    }
}