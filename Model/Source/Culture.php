<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Culture implements OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'is', 'label' => __('Icelandic (IS)')],
            ['value' => 'en', 'label' => __('English (EN)')]
        ];
    }
}