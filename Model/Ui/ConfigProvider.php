<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Straumur\Payment\Gateway\Config\Config;
use Straumur\Payment\Model\Source\PaymentFlow;

class ConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'straumur_payment';

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;
    
    /**
     * @var Config
     */
    private $config;
    
    /**
     * @var UrlInterface
     */
    private $urlBuilder;
    
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param PaymentHelper $paymentHelper
     * @param ScopeConfigInterface $scopeConfig
     * @param Config $config
     * @param UrlInterface $urlBuilder
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        PaymentHelper $paymentHelper,
        ScopeConfigInterface $scopeConfig,
        Config $config,
        UrlInterface $urlBuilder,
        StoreManagerInterface $storeManager
    ) {
        $this->paymentHelper = $paymentHelper;
        $this->scopeConfig = $scopeConfig;
        $this->config = $config;
        $this->urlBuilder = $urlBuilder;
        $this->storeManager = $storeManager;
    }

    /**
     * @return array
     * @throws LocalizedException
     */
    public function getConfig(): array
    {
        $storeId = $this->storeManager->getStore()->getId();

        return [
            'payment' => [
                self::CODE => [
                    'isActive' => $this->scopeConfig->isSetFlag(
                        'payment/' . self::CODE . '/active',
                        ScopeInterface::SCOPE_STORE,
                        $storeId
                    ),
                    'title' => $this->scopeConfig->getValue(
                        'payment/' . self::CODE . '/title',
                        ScopeInterface::SCOPE_STORE,
                        $storeId
                    ),
                    'description' => $this->scopeConfig->getValue(
                        'payment/' . self::CODE . '/description',
                        ScopeInterface::SCOPE_STORE,
                        $storeId
                    ),
                    'paymentFlow' => PaymentFlow::FLOW_HOSTED,
                    'environment' => $this->scopeConfig->getValue(
                        'payment/' . self::CODE . '/environment',
                        ScopeInterface::SCOPE_STORE,
                        $storeId
                    ) ?: 'sandbox',
                    'culture' => $this->config->getCulture($storeId),
                    'sessionTimeout' => (int) $this->scopeConfig->getValue(
                        'payment/' . self::CODE . '/session_timeout',
                        ScopeInterface::SCOPE_STORE,
                        $storeId
                    ) ?: 60,
                    'redirectUrl' => $this->urlBuilder->getUrl('straumur/checkout/getRedirectUrl'),
                    'isDebugMode' => $this->config->isDebugMode($storeId)
                ]
            ]
        ];
    }
}
