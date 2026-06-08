<?php
namespace Straumur\Payment\Model\Adminhtml\System\Config\Backend;

class ApiKey extends \Magento\Config\Model\Config\Backend\Encrypted
{
    public function beforeSave()
    {
        // API Key is just required, no specific format validation needed
        // The actual key format can vary
        return parent::beforeSave();
    }
}