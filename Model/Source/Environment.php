<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Source;

use Magento\Framework\Option\ArrayInterface;

class Environment implements ArrayInterface
{
    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => 'sandbox',
                'label' => __('Sandbox'),
            ],
            [
                'value' => 'production',
                'label' => __('Production'),
            ],
        ];
    }
}
