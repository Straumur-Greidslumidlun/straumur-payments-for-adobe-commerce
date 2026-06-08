<?php
declare(strict_types=1);

namespace Straumur\Payment\Gateway\Request;

use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Straumur\Payment\Helper\Data as StraumurHelper;
use Straumur\Payment\Model\Service\SessionManager;
use Psr\Log\LoggerInterface;

class SessionRequest implements BuilderInterface
{
    /**
     * @var SubjectReader
     */
    private $subjectReader;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var StraumurHelper
     */
    private $straumurHelper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var SessionManager
     */
    private $sessionManager;

    /**
     * @param SubjectReader $subjectReader
     * @param StoreManagerInterface $storeManager
     * @param StraumurHelper $straumurHelper
     * @param SessionManager $sessionManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        SubjectReader $subjectReader,
        StoreManagerInterface $storeManager,
        StraumurHelper $straumurHelper,
        SessionManager $sessionManager,
        LoggerInterface $logger
    ) {
        $this->subjectReader = $subjectReader;
        $this->storeManager = $storeManager;
        $this->straumurHelper = $straumurHelper;
        $this->sessionManager = $sessionManager;
        $this->logger = $logger;
    }

    /**
     * @param array $buildSubject
     * @return array
     */
    public function build(array $buildSubject): array
    {
        $paymentDO = $this->subjectReader->readPayment($buildSubject);
        $order = $paymentDO->getOrder();
        $storeId = $order->getStoreId();
        $currencyCode = $order->getCurrencyCode();
        $orderReference = $order->getOrderIncrementId();
        
        // Build return and abandon URLs with explicit secure flag
        $returnUrl = $this->storeManager->getStore()->getUrl('straumur/payment/return', [
            'reference' => $orderReference,
            '_secure' => true
        ]);
        $abandonUrl = $this->storeManager->getStore()->getUrl('straumur/payment/cancel', [
            'reference' => $orderReference,
            '_secure' => true
        ]);

        $this->logger->info('Building session request URLs', [
            'return_url' => $returnUrl,
            'abandon_url' => $abandonUrl,
            'store_id' => $storeId,
            'base_url' => $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB, true)
        ]);

        // Build core request data
        $requestData = [
            'amount' => (string)$this->straumurHelper->formatAmount(
                (float)$order->getGrandTotalAmount(),
                $currencyCode
            ),
            'currency' => $currencyCode,
            'reference' => $orderReference,
            'terminalIdentifier' => $this->straumurHelper->getTerminalId($storeId),
            'returnUrl' => $returnUrl,
            'abandonUrl' => $abandonUrl,
            'isManualCapture' => $this->straumurHelper->isManualCapture($storeId),
            'expiresAt' => $this->straumurHelper->getSessionExpiration($storeId),
            'culture' => $this->straumurHelper->getCulture($storeId)
        ];
        
        // Add optional theme key if configured
        $themeKey = $this->straumurHelper->getThemeId($storeId);
        if ($themeKey !== null) {
            $requestData['themeKey'] = $themeKey;
        }
        
        // Add order items if enabled in admin configuration
        if ($paymentDO->getPayment() && $paymentDO->getPayment()->getOrder()) {
            $items = $this->straumurHelper->getOrderItemsIfEnabled(
                $paymentDO->getPayment()->getOrder(), 
                $storeId
            );
            if (!empty($items)) {
                $requestData['items'] = $items;
            }
        }
        
        // Add required fields for TransferFactory
        $requestData['endpoint'] = 'hostedcheckout';
        $requestData['storeId'] = $storeId;
        
        return $requestData;
    }
}
