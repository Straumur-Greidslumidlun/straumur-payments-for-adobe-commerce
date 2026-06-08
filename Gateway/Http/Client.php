<?php
declare(strict_types=1);

namespace Straumur\Payment\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Exception\LocalizedException;
use Straumur\Payment\Gateway\Config\Config;
use Psr\Log\LoggerInterface;

class Client implements ClientInterface
{
    private const DEFAULT_MAX_RETRY_ATTEMPTS = 3;
    private const DEFAULT_TIMEOUT = 30;
    
    /**
     * @var Curl
     */
    private $curl;

    /**
     * @var Config
     */
    private $config;
    
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Curl $curl
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        Curl $curl,
        Config $config,
        LoggerInterface $logger
    ) {
        $this->curl = $curl;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @param TransferInterface $transferObject
     * @return array
     * @throws LocalizedException
     */
    public function placeRequest(TransferInterface $transferObject): array
    {
        $method = $transferObject->getMethod() ?? 'POST';
        $uri = $transferObject->getUri();
        $headers = $transferObject->getHeaders();
        $body = $transferObject->getBody();
        
        if ($this->config->isDebugMode()) {
            $this->logger->debug('Straumur API Request', [
                'method' => $method,
                'uri' => $uri,
                'headers' => $this->sanitizeHeaders($headers),
                'body' => $this->sanitizeBody($body)
            ]);
        }
        
        return $this->executeWithRetry(function() use ($method, $uri, $headers, $body) {
            return $this->executeRequest($method, $uri, $headers, $body);
        });
    }
    
    /**
     * Execute HTTP request
     *
     * @param string $method
     * @param string $uri
     * @param array $headers
     * @param array $body
     * @return array
     * @throws LocalizedException
     */
    private function executeRequest(string $method, string $uri, array $headers, array $body): array
    {
        try {
            $this->curl->setHeaders($headers);
            $this->curl->setTimeout($this->config->getApiTimeout() ?? self::DEFAULT_TIMEOUT);
            
            switch (strtoupper($method)) {
                case 'GET':
                    $this->curl->get($uri);
                    break;
                case 'POST':
                    $this->curl->post($uri, json_encode($body));
                    break;
                case 'PUT':
                    $this->curl->addHeader('Content-Type', 'application/json');
                    $this->curl->post($uri, json_encode($body));
                    break;
                default:
                    throw new LocalizedException(__('Unsupported HTTP method: %1', $method));
            }
            
            return $this->processResponse($uri);
            
        } catch (\Exception $e) {
            $this->logger->error('HTTP request failed', [
                'method' => $method,
                'uri' => $uri,
                'error' => $e->getMessage()
            ]);
            throw new LocalizedException(__('HTTP request failed: %1', $e->getMessage()));
        }
    }
    
    /**
     * Process HTTP response
     *
     * @param string $uri
     * @return array
     * @throws LocalizedException
     */
    private function processResponse(string $uri): array
    {
        $httpStatus = $this->curl->getStatus();
        $responseBody = $this->curl->getBody();
        
        if (empty($responseBody)) {
            if ($httpStatus >= 200 && $httpStatus < 300) {
                return ['success' => true, 'http_status' => $httpStatus];
            }
            throw new LocalizedException(__('Empty response received (HTTP %1)', $httpStatus));
        }
        
        $result = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new LocalizedException(__('Invalid JSON response: %1', json_last_error_msg()));
        }
        
        if ($this->config->isDebugMode()) {
            $this->logger->debug('Straumur API Response', [
                'uri' => $uri,
                'http_status' => $httpStatus,
                'response' => $this->sanitizeBody($result)
            ]);
        }
        
        if ($httpStatus >= 400) {
            $errorMessage = $result['errorMessage'] ?? 
                           $result['message'] ?? 
                           'HTTP Error ' . $httpStatus;
            $errorCode = $result['errorCode'] ?? $result['code'] ?? null;
            
            throw new LocalizedException(
                __('API Error [%1]: %2', $errorCode ?: $httpStatus, $errorMessage)
            );
        }
        
        return $result;
    }
    
    /**
     * Execute operation with exponential backoff retry logic
     *
     * @param callable $operation
     * @return mixed
     * @throws LocalizedException
     */
    private function executeWithRetry(callable $operation)
    {
        $maxRetries = (int) ($this->config->getValue('api_retry_attempts') ?? self::DEFAULT_MAX_RETRY_ATTEMPTS);
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < $maxRetries) {
            try {
                return $operation();
            } catch (LocalizedException $e) {
                $lastException = $e;
                $attempt++;
                
                if (!$this->isRetryableError($e) || $attempt >= $maxRetries) {
                    break;
                }
                
                // Exponential backoff: 500ms, 1s, 2s
                $backoffMs = pow(2, $attempt - 1) * 500;
                usleep($backoffMs * 1000);
                
                $this->logger->warning('Retrying API request', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                    'backoff_ms' => $backoffMs
                ]);
            }
        }
        
        throw $lastException;
    }
    
    /**
     * Check if an error is retryable
     *
     * @param LocalizedException $exception
     * @return bool
     */
    private function isRetryableError(LocalizedException $exception): bool
    {
        $message = $exception->getMessage();
        
        // Retryable patterns
        $retryablePatterns = [
            '/timeout/i', '/connection/i', '/network/i',
            '/HTTP Error 5\d\d/i', '/HTTP Error 408/i', '/HTTP Error 429/i'
        ];
        
        foreach ($retryablePatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Sanitize headers for logging
     *
     * @param array $headers
     * @return array
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sanitized = $headers;
        $sensitiveHeaders = ['X-API-KEY', 'Authorization', 'X-Auth-Token'];
        
        foreach ($sensitiveHeaders as $header) {
            if (isset($sanitized[$header])) {
                $sanitized[$header] = substr($sanitized[$header], 0, 4) . '****';
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize body for logging
     *
     * @param array $body
     * @return array
     */
    private function sanitizeBody(array $body): array
    {
        $sanitized = $body;
        $sensitiveFields = ['apiKey', 'password', 'secret', 'token', 'cvv'];
        
        array_walk_recursive($sanitized, function(&$value, $key) use ($sensitiveFields) {
            if (in_array($key, $sensitiveFields, true)) {
                $value = '****';
            }
        });
        
        return $sanitized;
    }
}
