<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Adminhtml\System\Config\Backend;

use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Framework\Exception\LocalizedException;

class WebhookSecret extends Encrypted
{
    public function beforeSave()
    {
        // Webhook secret is just required, no specific format validation needed
        // The actual secret format can vary
        return parent::beforeSave();
    }
}