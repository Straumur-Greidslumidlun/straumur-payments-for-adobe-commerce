<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Adminhtml\System\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class TerminalId extends Value
{
    public function beforeSave()
    {
        $value = $this->getValue();
        
        // Terminal ID is just required, no format validation needed
        // The actual terminal ID format can vary
        
        return parent::beforeSave();
    }
}