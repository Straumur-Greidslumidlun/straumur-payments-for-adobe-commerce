<?php
declare(strict_types=1);

namespace Straumur\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Straumur\Payment\Helper\Data;

class AdminNotificationObserver implements ObserverInterface
{
    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @var Data
     */
    private $helper;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;
    
    /**
     * @var UrlInterface
     */
    private $_urlBuilder;
    
    /**
     * @var bool
     */
    private static $hasChecked = false;

    /**
     * @param ManagerInterface $messageManager
     * @param Data $helper
     * @param ScopeConfigInterface $scopeConfig
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        ManagerInterface $messageManager,
        Data $helper,
        ScopeConfigInterface $scopeConfig,
        UrlInterface $urlBuilder
    ) {
        $this->messageManager = $messageManager;
        $this->helper = $helper;
        $this->scopeConfig = $scopeConfig;
        $this->_urlBuilder = $urlBuilder;
    }

    /**
     * Execute observer to check for missing Straumur payment configuration
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        // Only check once per request to avoid duplicate messages
        if (self::$hasChecked) {
            return;
        }
        
        // Skip AJAX requests
        $request = $observer->getEvent()->getRequest();
        if ($request && $request->isAjax()) {
            return;
        }
        
        // Only check on the main dashboard or config pages
        $fullActionName = $request ? $request->getFullActionName() : '';
        if (!in_array($fullActionName, ['adminhtml_dashboard_index', 'adminhtml_system_config_edit'])) {
            return;
        }
        
        self::$hasChecked = true;

        // Check if Straumur payment method is enabled
        $isEnabled = $this->scopeConfig->isSetFlag(
            'payment/straumur_payment/active',
            ScopeInterface::SCOPE_STORE
        );

        if (!$isEnabled) {
            return;
        }

        $missingConfigs = [];
        
        // Check for required configurations
        $apiKey = $this->helper->getApiKey();
        if (empty($apiKey)) {
            $missingConfigs[] = __('API Key');
        }

        $terminalId = $this->helper->getTerminalId();
        if (empty($terminalId)) {
            $missingConfigs[] = __('Terminal Identifier');
        }

        $webhookSecret = $this->helper->getWebhookSecret();
        if (empty($webhookSecret)) {
            $missingConfigs[] = __('Webhook Secret');
        }
        
        // Show warning if there are missing required configurations
        if (!empty($missingConfigs)) {
            // Check if we've already shown this message in this request
            $messageCollection = $this->messageManager->getMessages(false);
            $existingMessages = $messageCollection->getItems();
            
            // Look for existing Straumur configuration message
            foreach ($existingMessages as $existingMessage) {
                if (strpos($existingMessage->getText(), 'Straumur Payment') !== false) {
                    return; // Message already shown
                }
            }
            
            $configUrl = $observer->getEvent()->getControllerAction()
                ? $observer->getEvent()->getControllerAction()->getUrl('adminhtml/system_config/edit/section/payment')
                : $this->_urlBuilder->getUrl('adminhtml/system_config/edit/section/payment');
            
            $message = __(
                'Straumur Payment is enabled but missing required configuration: %1. ' .
                'The payment method will not work until configured. ' .
                '<a href="%2">Configure Now</a>', 
                implode(', ', $missingConfigs),
                $configUrl
            );
            
            $this->messageManager->addComplexErrorMessage(
                'adminHtmlMessage',
                ['html' => (string) $message]
            );
        }
    }
}