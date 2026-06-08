<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Adminhtml\System\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class ApiUrl extends Value
{
    public function beforeSave()
    {
        // API URL is preset by environment selection, minimal validation needed
        $value = $this->getValue();
        
        if (!empty($value)) {
            // Just ensure it's a valid URL
            if (!filter_var($value, FILTER_VALIDATE_URL)) {
                throw new LocalizedException(__('API URL must be a valid URL'));
            }
        }
        
        return parent::beforeSave();
    }
}