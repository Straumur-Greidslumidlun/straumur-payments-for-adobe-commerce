<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Webhook\Command;

class PaymentValidator extends AbstractValidator
{
    /**
     * @inheritDoc
     */
    protected function getRequiredFields(): array
    {
        return [
            'checkoutReference',
            'payfacReference',
            'merchantReference',
            'amount',
            'currency',
            'success'
        ];
    }
}