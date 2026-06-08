<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Adminhtml\System\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Active extends Value
{
    /**
     * Validate that required fields are configured before enabling
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = $this->getValue();
        
        // If enabling the payment method, validate required fields
        if ($value == '1') {
            $scope = $this->getScope() ?: ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
            $scopeId = $this->getScopeId() ?: 0;
            
            // Get the data from the current save request
            $groups = $this->getData('groups');
            $straumurGroup = $groups['straumur_payment']['fields'] ?? [];
            
            // Check API Key
            $apiKey = $straumurGroup['api_key']['value'] ?? null;
            if (!$apiKey) {
                // Try to get from existing config if not in current save
                $apiKey = $this->_config->getValue(
                    'payment/straumur_payment/api_key',
                    $scope,
                    $scopeId
                );
            }
            
            // Check Terminal ID
            $terminalId = $straumurGroup['terminal_id']['value'] ?? null;
            if (!$terminalId) {
                // Try to get from existing config if not in current save
                $terminalId = $this->_config->getValue(
                    'payment/straumur_payment/terminal_id',
                    $scope,
                    $scopeId
                );
            }
            
            // Check Webhook Secret
            $webhookSecret = $straumurGroup['webhook_secret']['value'] ?? null;
            if (!$webhookSecret) {
                // Try to get from existing config if not in current save
                $webhookSecret = $this->_config->getValue(
                    'payment/straumur_payment/webhook_secret',
                    $scope,
                    $scopeId
                );
            }
            
            // Validate all required fields are present
            $missingFields = [];
            if (empty($apiKey)) {
                $missingFields[] = 'API Key';
            }
            if (empty($terminalId)) {
                $missingFields[] = 'Terminal Identifier';
            }
            if (empty($webhookSecret)) {
                $missingFields[] = 'Webhook Secret';
            }
            
            if (!empty($missingFields)) {
                throw new LocalizedException(
                    __('Cannot enable Straumur Payment. Missing required configuration: %1', 
                       implode(', ', $missingFields))
                );
            }
        }
        
        return parent::beforeSave();
    }
}