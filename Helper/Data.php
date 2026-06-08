<?php
declare(strict_types=1);

namespace Straumur\Payment\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Sales\Model\Order;
use Magento\Quote\Model\Quote;
use Straumur\Payment\Gateway\Config\Config;
use Psr\Log\LoggerInterface;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    private const XML_PATH_DEBUG = 'payment/straumur_payment/debug';
    private const XML_PATH_TITLE = 'payment/straumur_payment/title';
    private const XML_PATH_ENVIRONMENT = 'payment/straumur_payment/environment';
    /**
     * @var Config
     */
    private $config;
    
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Context $context
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        Config $config,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getApiKey(?int $storeId = null): string
    {
        return $this->config->getApiKey($storeId);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getTerminalId(?int $storeId = null): string
    {
        return $this->config->getTerminalId($storeId);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getHcThemeId(?int $storeId = null): string
    {
        return (string) ($this->config->getThemeId($storeId) ?? '');
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getApiUrl(?int $storeId = null): string
    {
        return $this->config->getApiUrl($storeId);
    }

    /**
     * @param float|string $amount
     * @param string $currencyCode
     * @return int
     */
    public function formatAmount($amount, string $currencyCode): int
    {
        // Convert to float if string is provided
        $amount = (float)$amount;

        // ISO 4217 currency minor units
        $minorUnits = [
            // Zero decimal currencies
            'ISK' => 0,  // Icelandic Krona
            'JPY' => 0,  // Japanese Yen
            'KRW' => 0,  // South Korean Won

            // Two decimal currencies
            'EUR' => 2, 'USD' => 2, 'GBP' => 2, 'CAD' => 2,
            'DKK' => 2, 'NOK' => 2, 'SEK' => 2,

            // Three decimal currencies
            'BHD' => 3,  // Bahraini Dinar
            'JOD' => 3,  // Jordanian Dinar
            'KWD' => 3,  // Kuwaiti Dinar
            'OMR' => 3,  // Omani Rial
            'TND' => 3,  // Tunisian Dinar
        ];

        $decimals = $minorUnits[$currencyCode] ?? 2;  // Default to 2

        // For zero-decimal currencies (ISK, JPY, KRW), round first then multiply by 100
        // API still expects format with 2 trailing zeros (e.g., ISK 67 → 6700)
        if ($decimals === 0) {
            return (int)round($amount) * 100;
        }

        $multiplier = pow(10, $decimals);
        return (int)round($amount * $multiplier);
    }

    /**
     * Convert amount from minor units to major units (currency-aware)
     *
     * @param int|float $minorAmount Amount in minor units (from webhook/API)
     * @param string $currencyCode Currency code
     * @return float Amount in major units
     */
    public function convertFromMinorUnits($minorAmount, string $currencyCode): float
    {
        $minorUnits = [
            'ISK' => 0, 'JPY' => 0, 'KRW' => 0,
            'BHD' => 3, 'JOD' => 3, 'KWD' => 3, 'OMR' => 3, 'TND' => 3,
            'EUR' => 2, 'USD' => 2, 'GBP' => 2, 'CAD' => 2,
            'DKK' => 2, 'NOK' => 2, 'SEK' => 2,
        ];

        $decimals = $minorUnits[$currencyCode] ?? 2;

        if ($decimals === 0) {
            // ISK, JPY, KRW: API sends with 2 trailing zeros (e.g., 5400 for ISK 54)
            // Divide by 100 to get actual amount
            return (float)$minorAmount / 100;
        }

        $divisor = pow(10, $decimals);
        return round((float)$minorAmount / $divisor, $decimals);
    }

    /**
     * Get currency decimal places
     *
     * @param string $currencyCode
     * @return int
     */
    public function getCurrencyDecimals(string $currencyCode): int
    {
        $minorUnits = [
            'ISK' => 0, 'JPY' => 0, 'KRW' => 0,
            'BHD' => 3, 'JOD' => 3, 'KWD' => 3, 'OMR' => 3, 'TND' => 3,
        ];

        return $minorUnits[$currencyCode] ?? 2;
    }

    /**
     * @param Order $order
     * @return array
     */
    public function getOrderItems(Order $order): array
    {
        $items = [];
        $currencyCode = $order->getOrderCurrencyCode();
        foreach ($order->getAllVisibleItems() as $item) {
            // Use row total including tax to ensure amounts match grand total
            $itemAmount = (float)$item->getRowTotalInclTax();
            $items[] = [
                'name' => $item->getName(),
                'amount' => $this->formatAmount($itemAmount, $currencyCode)
            ];
        }
        return $items;
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getWebhookSecret(?int $storeId = null): string
    {
        return $this->config->getWebhookSecret($storeId);
    }

    /**
     * Check if manual capture is enabled
     * 
     * @param int|null $storeId
     * @return bool
     */
    public function isManualCapture(?int $storeId = null): bool
    {
        return $this->config->isManualCapture($storeId);
    }

    /**
     * Get session expiration datetime in ISO8601 format
     * 
     * @param int|null $storeId
     * @return string
     */
    public function getSessionExpiration(?int $storeId = null): string
    {
        $minutes = $this->config->getSessionExpiration($storeId);
        
        // Enforce min 5 minutes, max 1440 minutes (24 hours)
        $minutes = max(5, min(1440, $minutes));
        
        return (new \DateTime())
            ->add(new \DateInterval("PT{$minutes}M"))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Get culture/locale mapping for Straumur checkout
     * 
     * @param int|null $storeId
     * @return string
     */
    public function getCulture(?int $storeId = null): string
    {
        return $this->config->getCulture($storeId);
    }

    /**
     * Check if line items should be sent to Straumur
     * 
     * @param int|null $storeId
     * @return bool
     */
    public function shouldSendItems(?int $storeId = null): bool
    {
        return $this->config->shouldSendLineItems($storeId);
    }

    /**
     * Determine if quote-context sessions (headless) are enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function useQuoteSession(?int $storeId = null): bool
    {
        return $this->config->useQuoteSession($storeId);
    }

    /**
     * Build Straumur return URL for a given reference
     *
     * @param string $reference
     * @return string
     */
    public function getReturnUrl(string $reference): string
    {
        return $this->_getUrl('straumur/payment/return', [
            'reference' => $reference,
            '_secure' => true
        ]);
    }

    /**
     * Build Straumur cancellation URL for a given reference
     *
     * @param string $reference
     * @return string
     */
    public function getCancelUrl(string $reference): string
    {
        return $this->_getUrl('straumur/payment/cancel', [
            'reference' => $reference,
            '_secure' => true
        ]);
    }

    /**
     * Get quote items payload if enabled
     *
     * @param Quote $quote
     * @param int|null $storeId
     * @return array
     */
    public function getQuoteItems(Quote $quote, ?int $storeId = null): array
    {
        if (!$this->shouldSendItems($storeId)) {
            return [];
        }

        $items = [];
        $currencyCode = $quote->getQuoteCurrencyCode();

        foreach ($quote->getAllVisibleItems() as $item) {
            $rowTotal = (float) $item->getRowTotalInclTax();
            if ($rowTotal <= 0) {
                continue;
            }

            $items[] = [
                'name' => substr((string) $item->getName(), 0, 255),
                'quantity' => (int) $item->getQty(),
                'unitPrice' => $this->formatAmount(
                    (float) $item->getBasePriceInclTax(),
                    $currencyCode
                ),
                'totalAmount' => $this->formatAmount($rowTotal, $currencyCode),
                'taxAmount' => $this->formatAmount(
                    (float) $item->getTaxAmount(),
                    $currencyCode
                ),
                'sku' => substr((string) $item->getSku(), 0, 64)
            ];
        }

        return $items;
    }

    /**
     * Check if line items are enabled (alias for shouldSendItems for consistency with issue requirements)
     * 
     * @param int|null $storeId
     * @return bool
     */
    public function isLineItemsEnabled(?int $storeId = null): bool
    {
        return $this->shouldSendItems($storeId);
    }

    /**
     * Get order items for Straumur request (only if enabled in config)
     *
     * @param Order $order
     * @param int|null $storeId
     * @return array
     */
    public function getOrderItemsIfEnabled(Order $order, ?int $storeId = null): array
    {
        // Set a time limit to prevent hanging
        $startTime = microtime(true);
        $maxExecutionTime = 5; // 5 seconds max
        
        try {
            $this->logger->debug('getOrderItemsIfEnabled: Starting', ['store_id' => $storeId, 'order_id' => $order->getIncrementId()]);
            
            // Check if we should send items
            if (!$this->shouldSendItems($storeId)) {
                $this->logger->debug('getOrderItemsIfEnabled: Items disabled, returning empty array');
                return [];
            }
            
            // Check execution time
            if ((microtime(true) - $startTime) > $maxExecutionTime) {
                $this->logger->warning('getOrderItemsIfEnabled: Execution time exceeded');
                return [];
            }
            
            $items = [];
            $this->logger->debug('getOrderItemsIfEnabled: Getting order currency');
            $currencyCode = $order->getOrderCurrencyCode();
            $this->logger->debug('getOrderItemsIfEnabled: Currency retrieved', ['currency' => $currencyCode]);
            
            // Add product items (skip child items for configurable/bundle products)
            $this->logger->debug('getOrderItemsIfEnabled: About to get order items');
            
            // Try to get items with timeout protection
            $orderItems = null;
            try {
                // Check if order has items collection loaded
                if (!$order->hasData('items')) {
                    $this->logger->warning('getOrderItemsIfEnabled: Order items not loaded, skipping');
                    return [];
                }
                $orderItems = $order->getItems();
            } catch (\Exception $itemsError) {
                $this->logger->error('getOrderItemsIfEnabled: Failed to get order items', ['error' => $itemsError->getMessage()]);
                return [];
            }
            
            if (!$orderItems || !is_iterable($orderItems)) {
                $this->logger->warning('getOrderItemsIfEnabled: Order items is null or not iterable');
                return [];
            }
            
            $this->logger->debug('getOrderItemsIfEnabled: Starting to iterate items');
            $itemCount = 0;
            foreach ($orderItems as $item) {
                // Prevent infinite loops
                if (++$itemCount > 1000) {
                    $this->logger->error('getOrderItemsIfEnabled: Too many items, stopping');
                    break;
                }

                // Check execution time
                if ((microtime(true) - $startTime) > $maxExecutionTime) {
                    $this->logger->warning('getOrderItemsIfEnabled: Execution time exceeded in loop');
                    break;
                }
                try {
                    if (!$item->getParentItem()) {
                        // Use row total including tax to match grand total
                        $itemAmount = (float)$item->getRowTotalInclTax();
                        if ($itemAmount > 0) {
                            $itemName = $item->getName() ?: 'Product';
                            $items[] = [
                                'name' => substr((string)$itemName, 0, 255), // Limit name length
                                'amount' => $this->formatAmount($itemAmount, $currencyCode)
                            ];
                            $this->logger->debug('getOrderItemsIfEnabled: Added item', [
                                'name' => $itemName,
                                'amount_incl_tax' => $itemAmount,
                                'tax_amount' => (float)$item->getTaxAmount()
                            ]);
                        }
                    }
                } catch (\Exception $itemError) {
                    $this->logger->warning('getOrderItemsIfEnabled: Error processing item', ['error' => $itemError->getMessage()]);
                    // Skip this item and continue
                }
            }
            $this->logger->debug('getOrderItemsIfEnabled: Finished processing items', ['items_count' => count($items)]);
            
            // Add shipping as line item if present (including tax)
            $this->logger->debug('getOrderItemsIfEnabled: Checking shipping amount');
            $shippingAmount = (float)$order->getShippingAmount();
            $shippingTaxAmount = (float)$order->getShippingTaxAmount();
            $shippingInclTax = $shippingAmount + $shippingTaxAmount;

            if ($shippingInclTax > 0) {
                $items[] = [
                    'name' => __('Shipping')->__toString(),
                    'amount' => $this->formatAmount($shippingInclTax, $currencyCode)
                ];
                $this->logger->debug('getOrderItemsIfEnabled: Added shipping', [
                    'shipping_excl_tax' => $shippingAmount,
                    'shipping_tax' => $shippingTaxAmount,
                    'shipping_incl_tax' => $shippingInclTax
                ]);
            }

            // Add discount as negative line item if present
            $discountAmount = (float)$order->getDiscountAmount();
            if ($discountAmount < 0) {
                // Discount is stored as negative value in Magento
                $discountDescription = $order->getDiscountDescription();
                $discountName = !empty($discountDescription)
                    ? __('Discount (%1)', $discountDescription)->__toString()
                    : __('Discount')->__toString();

                $items[] = [
                    'name' => substr($discountName, 0, 255),
                    'amount' => $this->formatAmount($discountAmount, $currencyCode)
                ];
                $this->logger->debug('getOrderItemsIfEnabled: Added discount', [
                    'discount_amount' => $discountAmount,
                    'discount_description' => $discountDescription
                ]);
            }

            // Validate that line items sum matches grand total
            $grandTotal = (float)$order->getGrandTotal();
            $isValid = $this->validateLineItemsTotal($items, $grandTotal, $currencyCode);

            if (!$isValid) {
                $this->logger->warning('getOrderItemsIfEnabled: Line items sum does not match grand total', [
                    'order_id' => $order->getIncrementId(),
                    'grand_total' => $grandTotal,
                    'items_count' => count($items)
                ]);
            }

            $this->logger->debug('getOrderItemsIfEnabled: Returning items', [
                'total_items' => count($items),
                'validation_passed' => $isValid
            ]);
            return $items;
        } catch (\Exception $e) {
            // Log error but don't fail - items are optional
            $this->logger->error('getOrderItemsIfEnabled: Error getting order items', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'order_id' => $order->getIncrementId()
            ]);
            return [];
        }
    }

    /**
     * Validate that line items total matches the grand total
     *
     * @param array $items Array of line items with 'amount' in minor units
     * @param float $grandTotal Order grand total
     * @param string $currencyCode Currency code
     * @return bool True if totals match within acceptable tolerance
     */
    private function validateLineItemsTotal(array $items, float $grandTotal, string $currencyCode): bool
    {
        // Calculate sum of all line items (already in minor units)
        $itemsSum = 0;
        foreach ($items as $item) {
            $itemsSum += (int)$item['amount'];
        }

        // Convert grand total to minor units for comparison
        $grandTotalMinorUnits = $this->formatAmount($grandTotal, $currencyCode);

        // Log the comparison
        $this->logger->debug('validateLineItemsTotal: Comparing totals', [
            'items_sum' => $itemsSum,
            'grand_total_minor_units' => $grandTotalMinorUnits,
            'difference' => abs($itemsSum - $grandTotalMinorUnits),
            'currency' => $currencyCode
        ]);

        // Allow for 1 unit difference due to rounding (e.g., 1 cent/øre)
        $difference = abs($itemsSum - $grandTotalMinorUnits);
        return $difference <= 1;
    }

    /**
     * Log message with context
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function log(string $message, array $context = []): void
    {
        if ($this->scopeConfig->isSetFlag(self::XML_PATH_DEBUG, ScopeInterface::SCOPE_STORE)) {
            $this->logger->info($message, $context);
        }
    }

    /**
     * Get payment method title
     *
     * @param int|null $storeId
     * @return string
     */
    public function getTitle(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_TITLE, ScopeInterface::SCOPE_STORE, $storeId) ?: 'Straumur Payment';
    }

    /**
     * Get environment setting
     *
     * @param int|null $storeId
     * @return string
     */
    public function getEnvironment(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_ENVIRONMENT, ScopeInterface::SCOPE_STORE, $storeId) ?: 'sandbox';
    }

    /**
     * Get hosted checkout theme ID
     * 
     * @param int|null $storeId
     * @return string|null
     */
    public function getThemeId(?int $storeId = null): ?string
    {
        $themeId = $this->getHcThemeId($storeId);
        return !empty($themeId) ? $themeId : null;
    }


    /**
     * Check if debug mode is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isDebugEnabled(?int $storeId = null): bool
    {
        return $this->config->isDebugMode($storeId);
    }

    /**
     * Get webhook allowed IPs for CSRF protection
     *
     * @param int|null $storeId
     * @return array
     */
    public function getWebhookAllowedIps(?int $storeId = null): array
    {
        return $this->config->getWebhookAllowedIps($storeId);
    }

    /**
     * Get API request timeout in seconds
     *
     * @param int|null $storeId
     * @return int
     */
    public function getApiTimeout(?int $storeId = null): int
    {
        return $this->config->getApiTimeout($storeId);
    }

    /**
     * Get API retry attempts configuration
     *
     * @param int|null $storeId
     * @return int|null
     */
    public function getApiRetryAttempts(?int $storeId = null): ?int
    {
        return $this->config->getApiRetryAttempts($storeId);
    }
}
