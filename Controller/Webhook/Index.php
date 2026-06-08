<?php
declare(strict_types=1);

namespace Straumur\Payment\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Psr\Log\LoggerInterface;
use Straumur\Payment\Api\Webhook\RouterInterface;
use Straumur\Payment\Helper\Data as StraumurHelper;

class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * Maximum webhook payload size (10MB as per Straumur API documentation)
     */
    private const MAX_PAYLOAD_SIZE = 10 * 1024 * 1024; // 10MB
    
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    /**
     * @var RouterInterface
     */
    private $webhookRouter;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var RemoteAddress
     */
    private $remoteAddress;

    /**
     * @var StraumurHelper
     */
    private $straumurHelper;

    /**
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param RouterInterface $webhookRouter
     * @param LoggerInterface $logger
     * @param RemoteAddress $remoteAddress
     * @param StraumurHelper $straumurHelper
     */
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        RouterInterface $webhookRouter,
        LoggerInterface $logger,
        RemoteAddress $remoteAddress,
        StraumurHelper $straumurHelper
    ) {
        $this->request = $request;
        $this->jsonFactory = $jsonFactory;
        $this->webhookRouter = $webhookRouter;
        $this->logger = $logger;
        $this->remoteAddress = $remoteAddress;
        $this->straumurHelper = $straumurHelper;
    }

    /**
     * @inheritDoc
     */
    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        try {
            // Basic security validation
            $this->validateWebhookSecurity();
            
            // Log incoming request details
            $this->logger->info('=== STRAUMUR WEBHOOK REQUEST START ===');
            
            // Get raw payload
            $rawPayload = $this->request->getContent();
            if (empty($rawPayload)) {
                $rawPayload = file_get_contents('php://input');
            }
            
            // Validate payload size to prevent memory issues
            $payloadSize = strlen($rawPayload);
            if ($payloadSize > self::MAX_PAYLOAD_SIZE) {
                $this->logger->error('Webhook payload too large', [
                    'payload_size' => $payloadSize,
                    'max_size' => self::MAX_PAYLOAD_SIZE
                ]);
                throw new \InvalidArgumentException('Payload too large: ' . round($payloadSize / 1024 / 1024, 2) . 'MB exceeds maximum of ' . (self::MAX_PAYLOAD_SIZE / 1024 / 1024) . 'MB');
            }
            
            $this->logger->info('Raw Payload: ' . $rawPayload);
            
            // Parse JSON
            $payload = json_decode($rawPayload, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('JSON Parse Error: ' . json_last_error_msg());
                throw new \InvalidArgumentException('Invalid JSON payload: ' . json_last_error_msg());
            }

            // Get headers
            $headers = $this->getHeaders();
            $this->logger->info('Request Headers: ' . json_encode($headers));
            
            $this->logger->info('Parsed Payload: ' . json_encode($payload, JSON_PRETTY_PRINT));
            
   

            $processingResult = $this->webhookRouter->route($payload, $headers);

            $this->logger->info('=== STRAUMUR WEBHOOK REQUEST END ===');

            // Return 200 OK with no body (production-ready response)
            return $result->setHttpResponseCode(200)->setData([]);

        } catch (\Exception $e) {
            $this->logger->error('=== STRAUMUR WEBHOOK ERROR ===');
            $this->logger->error('Error Message: ' . $e->getMessage());
            $this->logger->error('Error Code: ' . $e->getCode());
            $this->logger->error('Error File: ' . $e->getFile() . ':' . $e->getLine());
            $this->logger->error('Stack Trace: ' . $e->getTraceAsString());
            $this->logger->error('=== STRAUMUR WEBHOOK ERROR END ===');

            // Return 400 Bad Request with no body
            return $result->setHttpResponseCode(400)->setData([]);
        }
    }


    /**
     * Get request headers using Magento's request object
     *
     * @return array
     */
    private function getHeaders(): array
    {
        $headers = [];
        
        // Get all headers from Magento's request object
        foreach ($this->request->getHeaders() as $header) {
            if ($header) {
                $headers[$header->getFieldName()] = $header->getFieldValue();
            }
        }

        return $headers;
    }

    /**
     * Validate webhook security (basic CSRF protection)
     *
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function validateWebhookSecurity(): void
    {
        // Get client IP address
        $clientIp = $this->remoteAddress->getRemoteAddress();
        $this->logger->info('Webhook request from IP: ' . $clientIp);

        // Check if webhook IP filtering is enabled in config
        $allowedIps = $this->straumurHelper->getWebhookAllowedIps();
        
        if (!empty($allowedIps) && !$this->isIpAllowed($clientIp, $allowedIps)) {
            $this->logger->error('Webhook request rejected: IP not in allowed list', [
                'client_ip' => $clientIp,
                'allowed_ips' => $allowedIps
            ]);
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Access denied: Invalid source IP')
            );
        }

        // Additional security: Check User-Agent to prevent basic bot attacks
        $userAgent = $this->request->getHeader('User-Agent');
        if (empty($userAgent) || $this->isSuspiciousUserAgent($userAgent)) {
            $this->logger->warning('Suspicious webhook request: Invalid User-Agent', [
                'user_agent' => $userAgent ?: 'EMPTY',
                'client_ip' => $clientIp
            ]);
        }

        // Log successful security validation
        $this->logger->info('Webhook security validation passed', [
            'client_ip' => $clientIp,
            'user_agent' => $userAgent ?: 'EMPTY'
        ]);
    }

    /**
     * Check if client IP is in allowed list
     *
     * @param string $clientIp
     * @param array $allowedIps
     * @return bool
     */
    private function isIpAllowed(string $clientIp, array $allowedIps): bool
    {
        foreach ($allowedIps as $allowedIp) {
            $allowedIp = trim($allowedIp);
            
            // Support CIDR notation and exact matches
            if ($allowedIp === $clientIp) {
                return true;
            }
            
            // Basic CIDR support for common cases like 192.168.1.0/24
            if (strpos($allowedIp, '/') !== false) {
                if ($this->ipInCidr($clientIp, $allowedIp)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Check if IP is in CIDR range
     *
     * @param string $ip
     * @param string $cidr
     * @return bool
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        list($network, $mask) = explode('/', $cidr);
        
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
            !filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        
        $mask = (int) $mask;
        if ($mask < 0 || $mask > 32) {
            return false;
        }
        
        return (ip2long($ip) & ~((1 << (32 - $mask)) - 1)) === (ip2long($network) & ~((1 << (32 - $mask)) - 1));
    }

    /**
     * Check if User-Agent looks suspicious
     *
     * @param string $userAgent
     * @return bool
     */
    private function isSuspiciousUserAgent(string $userAgent): bool
    {
        $suspiciousPatterns = [
            '/bot/i',
            '/crawler/i',
            '/spider/i',
            '/curl/i',
            '/wget/i',
            '/python/i',
            '/perl/i'
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create exception in case CSRF validation failed.
     * Return null if default exception will suffice.
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Perform custom request validation.
     * Return null if default validation is needed.
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // Bypass CSRF validation for webhook endpoints
        // Webhooks use HMAC signature validation instead
        return true;
    }
}
