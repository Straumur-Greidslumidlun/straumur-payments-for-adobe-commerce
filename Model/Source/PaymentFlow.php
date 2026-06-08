<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Payment flow type source model
 */
class PaymentFlow implements OptionSourceInterface
{
    public const FLOW_HOSTED = 'hosted';

    /**
     * Get payment flow options
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => self::FLOW_HOSTED,
                'label' => __('Hosted Checkout (Redirect)')
            ]
        ];
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            self::FLOW_HOSTED => __('Hosted Checkout (Redirect)')
        ];
    }
}