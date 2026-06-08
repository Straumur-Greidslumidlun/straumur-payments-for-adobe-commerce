<?php
declare(strict_types=1);

namespace Straumur\Payment\Block;

use Magento\Payment\Block\ConfigurableInfo;
use Straumur\Payment\Model\Ui\ConfigProvider;
use Straumur\Payment\Model\Service\TransactionCalculator;
use Magento\Framework\App\State;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Straumur\Payment\Helper\Data as StraumurHelper;

class Info extends ConfigurableInfo
{
    /**
     * @var string
     */
    protected $_template = 'Straumur_Payment::info/default.phtml';

    /**
     * @var TransactionCalculator
     */
    private $transactionCalculator;

    /**
     * @var State
     */
    private $appState;

    /**
     * @var PriceCurrencyInterface
     */
    private $priceCurrency;

    /**
     * @var StraumurHelper
     */
    private $straumurHelper;

    /**
     * Payment information fields to display
     *
     * @var array
     */
    protected $_paymentSpecificInformation = [
        'straumur_payfac_reference' => 'Transaction ID',
        'straumur_checkout_reference' => 'Checkout Reference',
        'transaction_summary' => 'Transaction Summary',
        'authorization_total' => 'Total Authorized',
        'captured_total' => 'Total Captured',
        'refunded_total' => 'Total Refunded',
        'adjustment_total' => 'Total Adjustments',
        'remaining_capturable' => 'Remaining Capturable',
        'transaction_history' => 'Transaction History'
    ];

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Payment\Gateway\ConfigInterface $config
     * @param TransactionCalculator $transactionCalculator
     * @param State $appState
     * @param PriceCurrencyInterface $priceCurrency
     * @param StraumurHelper $straumurHelper
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Gateway\ConfigInterface $config,
        TransactionCalculator $transactionCalculator,
        State $appState,
        PriceCurrencyInterface $priceCurrency,
        StraumurHelper $straumurHelper,
        array $data = []
    ) {
        parent::__construct($context, $config, $data);
        $this->transactionCalculator = $transactionCalculator;
        $this->appState = $appState;
        $this->priceCurrency = $priceCurrency;
        $this->straumurHelper = $straumurHelper;
    }

    /**
     * @return string
     */
    public function getMethodCode(): string
    {
        return ConfigProvider::CODE;
    }

    /**
     * Get payment-specific information
     *
     * @param string|null $transport
     * @return array
     */
    public function getSpecificInformation($transport = null): array
    {
        $data = [];
        $info = $this->getInfo();
        $order = $info->getOrder();

        // Get transaction summary from new calculator
        if ($order) {
            $summary = $this->transactionCalculator->getTransactionSummary($order);
            $currency = $order->getOrderCurrencyCode();
            $isAdminArea = $this->isAdminArea();
            
            // Get all payment information
            // Show authorization reference (use explicit field first, then fall back)
            $authorizationReference = $info->getAdditionalInformation('straumur_authorization_reference')
                ?? $info->getAdditionalInformation('straumur_payfac_reference'); // Backward compatibility
            $captureReference = $info->getAdditionalInformation('straumur_capture_reference');
            $checkoutReference = $info->getAdditionalInformation('straumur_checkout_reference');
            $authAmount = $info->getAdditionalInformation('straumur_authorization_amount');
            $authCode = $info->getAdditionalInformation('straumur_auth_code');
            $cardNumber = $info->getAdditionalInformation('straumur_card_number');
            $authDate = $info->getAdditionalInformation('straumur_authorization_date');
            $captureDate = $info->getAdditionalInformation('straumur_capture_date');
            $threeDAuthenticated = $info->getAdditionalInformation('straumur_3ds_authenticated');
            $cardUsage = $info->getAdditionalInformation('straumur_card_usage');

            // Get store ID once and cast to int
            $storeId = $order->getStoreId() ? (int)$order->getStoreId() : null;

            // Payment Status - Always show first
            $status = $this->getPaymentStatus($summary);
            if ($status) {
                $data[__('Payment Status')->render()] = __($status);
            }

            // Transaction References - Always visible for support
            if ($authorizationReference) {
                $data[__('Authorization Reference')->render()] = $authorizationReference;
            }

            // Show capture reference if different from authorization (manual capture case)
            if ($captureReference && $captureReference !== $authorizationReference) {
                $data[__('Capture Reference')->render()] = $captureReference;
            }
            
            if ($authCode) {
                $data[__('Authorization Code')->render()] = $authCode;
            }
            
            // Card Information - Admin only for security
            if ($isAdminArea && $cardNumber) {
                $data[__('Card')->render()] = $this->formatCardNumber($cardNumber);
            }

            // Card Usage Type (Credit/Debit)
            if ($cardUsage) {
                $data[__('Card Type')->render()] = __($cardUsage);
            }

            // 3D Secure Authentication
            if ($threeDAuthenticated === 'true') {
                $data[__('3D Secure')->render()] = __('Authenticated');
            } elseif ($threeDAuthenticated === 'false') {
                $data[__('3D Secure')->render()] = __('Not Authenticated');
            }
            
            // Transaction Amounts - Properly formatted
            if ($summary['total_authorized'] > 0) {
                $data[__('Authorized Amount')->render()] = $this->formatCurrencyAmount($summary['total_authorized'], $currency, $storeId);
            }

            if ($summary['total_captured'] > 0) {
                $data[__('Captured Amount')->render()] = $this->formatCurrencyAmount($summary['total_captured'], $currency, $storeId);
            }

            // Show Straumur's actual refunded amount (what was sent to Straumur after rounding)
            $straumurRefundAmount = $info->getAdditionalInformation('straumur_refund_amount');
            if ($straumurRefundAmount > 0) {
                $data[__('Refunded Amount')->render()] = $this->formatCurrencyAmount((float)$straumurRefundAmount, $currency, $storeId);
            } elseif ($order->getTotalRefunded() > 0) {
                // Fallback to Magento amount if Straumur amount not available
                $data[__('Refunded Amount')->render()] = $this->formatCurrencyAmount((float)$order->getTotalRefunded(), $currency, $storeId);
            }

            if ($summary['total_adjustments'] != 0) {
                $data[__('Adjustment Amount')->render()] = $this->formatCurrencyAmount($summary['total_adjustments'], $currency, $storeId);
            }

            // Remaining amounts - useful for merchants
            if ($summary['remaining_capturable'] > 0) {
                $data[__('Remaining Capturable')->render()] = $this->formatCurrencyAmount($summary['remaining_capturable'], $currency, $storeId);
            }
            
            // Timestamps if available
            if ($authDate) {
                $data[__('Authorized At')->render()] = $this->formatPaymentDate($authDate);
            }
            
            if ($captureDate) {
                $data[__('Captured At')->render()] = $this->formatPaymentDate($captureDate);
            }
            
            // 3DS/Risk info if available (future enhancement)
            $threeDsResult = $info->getAdditionalInformation('straumur_3ds_result');
            if ($threeDsResult) {
                $data[__('3DS Result')->render()] = __($threeDsResult);
            }
            
        } else {
            // Fallback to old method if no order available
            foreach ($this->_paymentSpecificInformation as $key => $label) {
                $value = $info->getAdditionalInformation($key);
                if ($value) {
                    $data[__($label)->render()] = $this->formatValue($key, $value);
                }
            }
        }

        return $data;
    }

    /**
     * Format display value based on field type
     *
     * @param string $key
     * @param mixed $value
     * @return string
     */
    private function formatValue(string $key, $value): string
    {
        // Format dates
        if (strpos($key, '_date') !== false) {
            return date('M j, Y g:i A', strtotime($value));
        }

        // Format amounts (assuming they're in minor units)
        if (strpos($key, '_amount') !== false) {
            $currency = $this->getInfo()->getOrder()->getOrderCurrencyCode();
            return $this->formatAmount((float)$value / 100, $currency);
        }

        return (string)$value;
    }

    /**
     * Format amount with currency
     *
     * @param float $amount
     * @param string $currency
     * @return string
     */
    private function formatAmount(float $amount, string $currency): string
    {
        return number_format($amount, 2) . ' ' . $currency;
    }

    /**
     * Format amount with proper currency symbol and formatting
     *
     * @param float $amount
     * @param string $currency
     * @param int|null $storeId
     * @return string
     */
    private function formatCurrencyAmount(float $amount, string $currency, ?int $storeId = null): string
    {
        try {
            return $this->priceCurrency->format(
                $amount,
                false,
                PriceCurrencyInterface::DEFAULT_PRECISION,
                $storeId,
                $currency
            );
        } catch (\Exception $e) {
            // Fallback to simple formatting if currency formatter fails
            return $this->formatAmount($amount, $currency);
        }
    }

    /**
     * Format card number with masking for security
     *
     * @param string $cardNumber
     * @return string
     */
    private function formatCardNumber(string $cardNumber): string
    {
        // Extract card brand and last 4 digits if full number provided
        if (strlen($cardNumber) > 4) {
            $last4 = substr($cardNumber, -4);
            $brand = $this->detectCardBrand($cardNumber);
            return $brand . ' •••• ' . $last4;
        }
        
        // If already masked or partial, return as is
        return $cardNumber;
    }

    /**
     * Detect card brand from number
     *
     * @param string $cardNumber
     * @return string
     */
    private function detectCardBrand(string $cardNumber): string
    {
        $firstDigits = substr($cardNumber, 0, 6);
        
        if (strpos($cardNumber, '4') === 0) {
            return 'Visa';
        } elseif (preg_match('/^5[1-5]/', $firstDigits)) {
            return 'Mastercard';
        } elseif (preg_match('/^3[47]/', $firstDigits)) {
            return 'American Express';
        } elseif (preg_match('/^6(?:011|5)/', $firstDigits)) {
            return 'Discover';
        }
        
        return 'Card';
    }

    /**
     * Get payment status based on transaction summary
     *
     * @param array $summary
     * @return string
     */
    private function getPaymentStatus(array $summary): string
    {
        if ($summary['is_fully_refunded']) {
            return 'Refunded';
        } elseif ($summary['total_refunded'] > 0) {
            return 'Partially Refunded';
        } elseif ($summary['is_fully_captured']) {
            return 'Captured';
        } elseif ($summary['total_captured'] > 0) {
            return 'Partially Captured';
        } elseif ($summary['total_authorized'] > 0) {
            return 'Authorized';
        }
        
        return 'Pending';
    }

    /**
     * Check if current area is admin
     *
     * @return bool
     */
    private function isAdminArea(): bool
    {
        try {
            return $this->appState->getAreaCode() === \Magento\Framework\App\Area::AREA_ADMINHTML;
        } catch (\Exception $e) {
            // If area code is not set, check alternative methods
            return $this->_request->getModuleName() === 'admin' 
                || strpos($this->_request->getPathInfo(), '/admin') !== false;
        }
    }

    /**
     * Format date for display
     *
     * @param string $date
     * @return string
     */
    public function formatPaymentDate(string $date): string
    {
        try {
            return $this->_localeDate->formatDateTime(
                new \DateTime($date),
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::SHORT
            );
        } catch (\Exception $e) {
            // Fallback to simple formatting
            return date('M j, Y g:i A', strtotime($date));
        }
    }

    /**
     * Get detailed transaction history for admin display
     *
     * @return array
     */
    public function getTransactionHistory(): array
    {
        $info = $this->getInfo();
        $order = $info->getOrder();
        
        if (!$order) {
            return [];
        }

        $history = $this->transactionCalculator->getCompleteTransactionHistory($info);
        $currency = $order->getOrderCurrencyCode();
        $formatted = [];

        // Format each transaction type for display
        foreach (['authorizations', 'captures', 'refunds', 'adjustments'] as $type) {
            if (!empty($history[$type])) {
                $formatted[$type] = [];
                foreach ($history[$type] as $transaction) {
                    $amountInMinorUnits = $transaction['amount'];
                    $amountInMajorUnits = $this->straumurHelper->convertFromMinorUnits($amountInMinorUnits, $currency);

                    $formatted[$type][] = [
                        'amount' => $this->formatCurrencyAmount($amountInMajorUnits, $currency, $order->getStoreId()),
                        'reference' => $transaction['reference'] ?? 'N/A',
                        'date' => isset($transaction['date']) ? date('M j, Y g:i A', strtotime($transaction['date'])) : 'N/A',
                        'reason' => $transaction['reason'] ?? null,
                        'success' => $transaction['success'] ?? true
                    ];
                }
            }
        }

        return $formatted;
    }

    /**
     * Check if order has transaction history
     *
     * @return bool
     */
    public function hasTransactionHistory(): bool
    {
        $history = $this->getTransactionHistory();
        return !empty($history['authorizations']) || !empty($history['captures']) || 
               !empty($history['refunds']) || !empty($history['adjustments']);
    }

    /**
     * Get transaction validation status
     *
     * @return array
     */
    public function getValidationStatus(): array
    {
        $info = $this->getInfo();
        $order = $info->getOrder();
        
        if (!$order) {
            return [];
        }

        $summary = $this->transactionCalculator->getTransactionSummary($order);
        
        return [
            'can_capture_more' => $summary['remaining_capturable'] > 0,
            'can_refund_more' => $summary['remaining_refundable'] > 0,
            'is_manual_capture' => (bool) $info->getAdditionalInformation('straumur_is_manual_capture'),
            'fully_captured' => $summary['is_fully_captured'],
            'fully_refunded' => $summary['is_fully_refunded']
        ];
    }
}
