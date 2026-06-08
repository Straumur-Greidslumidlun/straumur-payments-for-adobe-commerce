<?php
declare(strict_types=1);

namespace Straumur\Payment\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Http\TransferBuilder;
use Straumur\Payment\Gateway\Config\Config;
use Psr\Log\LoggerInterface;

class TransferFactory implements TransferFactoryInterface
{
    /**
     * @var TransferBuilder
     */
    private $transferBuilder;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param TransferBuilder $transferBuilder
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        TransferBuilder $transferBuilder,
        Config $config,
        LoggerInterface $logger
    ) {
        $this->transferBuilder = $transferBuilder;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @param array $request
     * @return \Magento\Payment\Gateway\Http\TransferInterface
     */
    public function create(array $request): \Magento\Payment\Gateway\Http\TransferInterface
    {
        try {
            $this->logger->debug('TransferFactory::create started', ['request' => $request]);
            
            $storeId = $request['storeId'] ?? null;
            $operation = $request['operation'] ?? null;
            $endpoint = $request['endpoint'] ?? '';
            $method = 'POST';
            
            // Get endpoint configuration if operation is provided
            if ($operation) {
                $endpointConfig = $this->config->getEndpointConfig($operation);
                if ($endpointConfig) {
                    $method = $endpointConfig['method'] ?? 'POST';
                    $endpoint = $endpointConfig['endpoint'] ?? $endpoint;
                }
            }
            
            // Remove metadata fields from request body
            $body = $request;
            unset($body['endpoint'], $body['storeId'], $body['operation'], $body['method']);
            
            // Handle URL parameters (e.g., {checkoutReference})
            if (strpos($endpoint, '{') !== false) {
                foreach ($body as $key => $value) {
                    $placeholder = '{' . $key . '}';
                    if (strpos($endpoint, $placeholder) !== false) {
                        $endpoint = str_replace($placeholder, $value, $endpoint);
                        unset($body[$key]);
                    }
                }
            }
            
            $apiKey = $this->config->getApiKey($storeId);
            $apiUrl = $this->config->getApiUrl($storeId);
            $fullUri = rtrim($apiUrl, '/') . '/' . ltrim($endpoint, '/');
            
            $this->logger->debug('TransferFactory building transfer', [
                'api_url' => $apiUrl,
                'endpoint' => $endpoint,
                'full_uri' => $fullUri,
                'api_key_configured' => !empty($apiKey),
                'request_body' => json_encode($body),
                'method' => $method
            ]);

            $transfer = $this->transferBuilder
                ->setClientConfig([
                    'timeout' => $this->config->getApiTimeout($storeId)
                ])
                ->setHeaders([
                    'X-API-KEY' => $apiKey,
                    'Content-Type' => 'application/json'
                ])
                ->setBody($body)
                ->setUri($fullUri)
                ->setMethod($method)
                ->build();
                
            $this->logger->debug('TransferFactory transfer built successfully');
            
            return $transfer;
        } catch (\Exception $e) {
            $this->logger->error('TransferFactory::create failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
