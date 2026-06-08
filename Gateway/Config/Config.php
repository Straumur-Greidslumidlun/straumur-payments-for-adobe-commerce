<?php
declare(strict_types=1);

namespace Straumur\Payment\Gateway\Config;

use Magento\Payment\Gateway\ConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config implements ConfigInterface
{
    public const CODE = 'straumur_payment';
    
    // Configuration paths
    private const DEFAULT_PATH_PATTERN = 'payment/%s/%s';
    private const XML_PATH_API_KEY = 'api_key';
    private const XML_PATH_TERMINAL_ID = 'terminal_id';
    private const XML_PATH_ENVIRONMENT = 'environment';
    private const XML_PATH_DEBUG = 'debug';
    private const XML_PATH_WEBHOOK_SECRET = 'webhook_secret';
    private const XML_PATH_MANUAL_CAPTURE = 'manual_capture';
    private const XML_PATH_SESSION_EXPIRES = 'session_timeout';
    private const XML_PATH_CULTURE = 'culture';
    private const XML_PATH_SEND_ITEMS = 'send_items';
    private const XML_PATH_HC_THEME_ID = 'hc_theme_id';
    private const XML_PATH_USE_QUOTE_SESSION = 'use_quote_session';
    private const XML_PATH_API_TIMEOUT = 'api_timeout';
    private const XML_PATH_API_RETRY_ATTEMPTS = 'api_retry_attempts';
    private const XML_PATH_WEBHOOK_ALLOWED_IPS = 'webhook_allowed_ips';

    // API endpoints
    private const SANDBOX_URL = 'https://checkout-api.staging.straumur.is/api/v1/';
    private const PRODUCTION_URL = 'https://greidslugatt.straumur.is/api/v1/';

    // Endpoint configuration
    private const ENDPOINTS = [
        'create_session' => [
            'method' => 'POST',
            'endpoint' => 'hostedcheckout',
            'required_fields' => ['amount', 'currency', 'returnUrl', 'reference', 'terminalIdentifier']
        ],
        'create_embedded_session' => [
            'method' => 'POST',
            'endpoint' => 'embeddedcheckout/session',
            'required_fields' => [
                'amount',
                'currency',
                'origin',
                'reference',
                'terminalIdentifier',
                'threeDsReturnUrl'
            ]
        ],
        'embedded_status' => [
            'method' => 'GET',
            'endpoint' => 'embeddedcheckout/status/{checkoutReference}',
            'required_fields' => ['checkoutReference']
        ],
        'capture' => [
            'method' => 'POST',
            'endpoint' => 'modification/capture',
            'required_fields' => ['amount', 'currency', 'reference', 'payfacReference']
        ],
        'status' => [
            'method' => 'GET',
            'endpoint' => 'hostedcheckout/status/{checkoutReference}',
            'required_fields' => ['checkoutReference']
        ],
        'refund' => [
            'method' => 'POST',
            'endpoint' => 'modification/refund',
            'required_fields' => ['reference', 'payfacReference', 'amount', 'currency', 'refundReason']
        ],
        'reverse' => [
            'method' => 'POST',
            'endpoint' => 'modification/reverse',
            'required_fields' => ['reference', 'payfacReference']
        ]
    ];

    // Webhook event configuration
    private const WEBHOOK_EVENTS = [
        'authorization' => [
            'processor' => 'authorization_processor',
            'required_fields' => ['checkoutReference', 'payfacReference', 'merchantReference', 'amount', 'currency', 'success'],
            'status_mapping' => [
                true => 'authorized',
                false => 'authorization_failed'
            ]
        ],
        'capture' => [
            'processor' => 'capture_processor',
            'required_fields' => ['checkoutReference', 'payfacReference', 'merchantReference', 'amount', 'currency', 'success'],
            'status_mapping' => [
                true => 'captured',
                false => 'capture_failed'
            ]
        ],
        'adjustment' => [
            'processor' => 'adjustment_processor',
            'required_fields' => ['checkoutReference', 'payfacReference', 'merchantReference', 'amount', 'currency', 'reason', 'success'],
            'status_mapping' => [
                true => 'adjusted',
                false => 'adjustment_failed'
            ]
        ],
        'refund' => [
            'processor' => 'refund_processor',
            'required_fields' => ['checkoutReference', 'payfacReference', 'merchantReference', 'amount', 'currency', 'reason', 'success'],
            'status_mapping' => [
                true => 'refunded',
                false => 'refund_failed'
            ]
        ],
        'tokenization' => [
            'processor' => 'tokenization_processor',
            'required_fields' => ['checkoutReference', 'payfacReference', 'merchantReference', 'success'],
            'status_mapping' => [
                true => 'tokenized',
                false => 'tokenization_failed'
            ]
        ]
    ];

    // HMAC validation field order as per Straumur documentation
    private const HMAC_FIELDS = [
        'checkoutReference',
        'payfacReference', 
        'merchantReference',
        'amount',
        'currency',
        'reason',
        'success'
    ];

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;
    
    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var string|null
     */
    private $methodCode;

    /**
     * @var string
     */
    private $pathPattern;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param string|null $methodCode
     * @param string $pathPattern
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        ?string $methodCode = null,
        string $pathPattern = self::DEFAULT_PATH_PATTERN
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->methodCode = $methodCode ?? self::CODE;
        $this->pathPattern = $pathPattern;
    }

    /**
     * @inheritdoc
     */
    public function setMethodCode($methodCode): void
    {
        $this->methodCode = $methodCode;
    }

    /**
     * @inheritdoc
     */
    public function setPathPattern($pathPattern): void
    {
        $this->pathPattern = $pathPattern;
    }

    /**
     * @inheritdoc
     */
    public function getValue($field, $storeId = null)
    {
        return $this->scopeConfig->getValue(
            sprintf($this->pathPattern, $this->methodCode, $field),
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
    
    /**
     * Get API Key
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiKey(?int $storeId = null): string
    {
        $value = (string) $this->getValue(self::XML_PATH_API_KEY, $storeId);
        
        // Handle encrypted values
        if ($value && strpos($value, ':') !== false) {
            try {
                $value = $this->encryptor->decrypt($value);
            } catch (\Exception $e) {
                // Value might not be encrypted
            }
        }
        
        return $value;
    }
    
    /**
     * Get Terminal ID
     *
     * @param int|null $storeId
     * @return string
     */
    public function getTerminalId(?int $storeId = null): string
    {
        return (string) $this->getValue(self::XML_PATH_TERMINAL_ID, $storeId);
    }
    
    /**
     * Get API URL based on environment
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiUrl(?int $storeId = null): string
    {
        $environment = $this->getValue(self::XML_PATH_ENVIRONMENT, $storeId);
        return $environment === 'production' ? self::PRODUCTION_URL : self::SANDBOX_URL;
    }
    
    /**
     * Check if debug mode is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isDebugMode(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::XML_PATH_DEBUG, $storeId);
    }
    
    /**
     * Get webhook secret
     *
     * @param int|null $storeId
     * @return string
     */
    public function getWebhookSecret(?int $storeId = null): string
    {
        $value = (string) $this->getValue(self::XML_PATH_WEBHOOK_SECRET, $storeId);
        
        // Handle encrypted values
        if ($value && strpos($value, ':') !== false) {
            try {
                $value = $this->encryptor->decrypt($value);
            } catch (\Exception $e) {
                // Value might not be encrypted
            }
        }
        
        return $value;
    }
    
    /**
     * Check if manual capture is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isManualCapture(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::XML_PATH_MANUAL_CAPTURE, $storeId);
    }
    
    /**
     * Get session expiration in minutes
     *
     * @param int|null $storeId
     * @return int
     */
    public function getSessionExpiration(?int $storeId = null): int
    {
        return (int) ($this->getValue(self::XML_PATH_SESSION_EXPIRES, $storeId) ?? 60);
    }
    
    /**
     * Get culture/language setting
     *
     * @param int|null $storeId
     * @return string
     */
    public function getCulture(?int $storeId = null): string
    {
        return (string) ($this->getValue(self::XML_PATH_CULTURE, $storeId) ?? 'is');
    }
    
    /**
     * Check if line items should be sent
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldSendLineItems(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::XML_PATH_SEND_ITEMS, $storeId);
    }
    
    /**
     * Get hosted checkout theme ID
     *
     * @param int|null $storeId
     * @return string|null
     */
    public function getThemeId(?int $storeId = null): ?string
    {
        $value = $this->getValue(self::XML_PATH_HC_THEME_ID, $storeId);
        return $value ? (string) $value : null;
    }

    /**
     * Determine if quote-context session creation should be used (headless mode)
     *
     * @param int|null $storeId
     * @return bool
     */
    public function useQuoteSession(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::XML_PATH_USE_QUOTE_SESSION, $storeId);
    }
    
    /**
     * Get API timeout in seconds
     *
     * @param int|null $storeId
     * @return int
     */
    public function getApiTimeout(?int $storeId = null): int
    {
        return (int) ($this->getValue(self::XML_PATH_API_TIMEOUT, $storeId) ?? 60);
    }
    
    /**
     * Get API retry attempts
     *
     * @param int|null $storeId
     * @return int
     */
    public function getApiRetryAttempts(?int $storeId = null): int
    {
        return (int) ($this->getValue(self::XML_PATH_API_RETRY_ATTEMPTS, $storeId) ?? 3);
    }
    
    /**
     * Get endpoint configuration
     *
     * @param string $operation
     * @return array
     */
    public function getEndpointConfig(string $operation): array
    {
        return self::ENDPOINTS[$operation] ?? [];
    }
    
    /**
     * Get all endpoint configurations
     *
     * @return array
     */
    public function getAllEndpoints(): array
    {
        return self::ENDPOINTS;
    }
    
    /**
     * Build full endpoint URL
     *
     * @param string $endpoint
     * @param array $params
     * @param int|null $storeId
     * @return string
     */
    public function buildEndpointUrl(string $endpoint, array $params = [], ?int $storeId = null): string
    {
        $baseUrl = rtrim($this->getApiUrl($storeId), '/');
        
        // Replace placeholders in endpoint
        $endpoint = preg_replace_callback('/\{(\w+)\}/', function ($matches) use ($params) {
            return $params[$matches[1]] ?? $matches[0];
        }, $endpoint);
        
        return $baseUrl . '/' . ltrim($endpoint, '/');
    }
    
    /**
     * Get webhook allowed IPs for CSRF protection
     *
     * @param int|null $storeId
     * @return array
     */
    public function getWebhookAllowedIps(?int $storeId = null): array
    {
        $ips = $this->getValue(self::XML_PATH_WEBHOOK_ALLOWED_IPS, $storeId);
        if (empty($ips)) {
            return [];
        }
        
        // Split by comma and trim whitespace
        return array_map('trim', explode(',', (string) $ips));
    }
    
    /**
     * Get webhook event configuration
     *
     * @param string $eventType
     * @return array
     */
    public function getEventConfig(string $eventType): array
    {
        return self::WEBHOOK_EVENTS[$eventType] ?? [];
    }
    
    /**
     * Get all webhook event configurations
     *
     * @return array
     */
    public function getAllEvents(): array
    {
        return self::WEBHOOK_EVENTS;
    }
    
    /**
     * Get HMAC validation fields in correct order
     *
     * @return array
     */
    public function getHmacFields(): array
    {
        return self::HMAC_FIELDS;
    }
    
    /**
     * Get supported webhook event types
     *
     * @return array
     */
    public function getSupportedEvents(): array
    {
        return array_keys(self::WEBHOOK_EVENTS);
    }
}
