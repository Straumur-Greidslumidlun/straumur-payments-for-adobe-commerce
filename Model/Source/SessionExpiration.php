<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class SessionExpiration implements OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 5, 'label' => __('5 minutes')],
            ['value' => 10, 'label' => __('10 minutes')],
            ['value' => 15, 'label' => __('15 minutes')],
            ['value' => 30, 'label' => __('30 minutes')],
            ['value' => 60, 'label' => __('1 hour')],
            ['value' => 180, 'label' => __('3 hours')],
            ['value' => 360, 'label' => __('6 hours')],
            ['value' => 720, 'label' => __('12 hours')],
            ['value' => 1440, 'label' => __('24 hours')]
        ];
    }
}