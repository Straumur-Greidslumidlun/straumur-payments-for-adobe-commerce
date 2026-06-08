<?php
declare(strict_types=1);

namespace Straumur\Payment\Model;

use Straumur\Payment\Api\StraumurApiInterface;
use Straumur\Payment\Gateway\Http\Client;
use Straumur\Payment\Gateway\Http\TransferFactory;
use Straumur\Payment\Gateway\Config\Config;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class StraumurApi implements StraumurApiInterface
{
    /**
     * @var Client
     */
    private $httpClient;
    
    /**
     * @var TransferFactory
     */
    private $transferFactory;
    
    /**
     * @var Config
     */
    private $config;
    
    /**
     * @var LoggerInterface
     */
    private $logger;
    
    /**
     * @param Client $httpClient
     * @param TransferFactory $transferFactory
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        Client $httpClient,
        TransferFactory $transferFactory,
        Config $config,
        LoggerInterface $logger
    ) {
        $this->httpClient = $httpClient;
        $this->transferFactory = $transferFactory;
        $this->config = $config;
        $this->logger = $logger;
    }
    
    /**
     * @inheritDoc
     */
    public function createSession(array $sessionData): array
    {
        $this->validateRequiredFields($sessionData, 'create_session');
        
        $request = array_merge($sessionData, [
            'operation' => 'create_session',
            'terminalIdentifier' => $sessionData['terminalIdentifier'] ?? $this->config->getTerminalId(),
            'isManualCapture' => $sessionData['isManualCapture'] ?? $this->config->isManualCapture(),
            'culture' => $sessionData['culture'] ?? $this->config->getCulture(),
            'expiresAt' => $sessionData['expiresAt'] ?? $this->getExpirationTime($sessionData['storeId'] ?? null)
        ]);
        
        // Remove empty items array
        if (isset($request['items']) && empty($request['items'])) {
            unset($request['items']);
        }
        // Also handle uppercase Items for backward compatibility
        if (isset($request['Items']) && empty($request['Items'])) {
            unset($request['Items']);
        }
        
        $transfer = $this->transferFactory->create($request);
        return $this->httpClient->placeRequest($transfer);
    }

    /**
     * @inheritDoc
     */
    public function createEmbeddedSession(array $sessionData): array
    {
        $this->validateRequiredFields($sessionData, 'create_embedded_session');

        $request = array_merge($sessionData, [
            'operation' => 'create_embedded_session',
            'terminalIdentifier' => $sessionData['terminalIdentifier'] ?? $this->config->getTerminalId($sessionData['storeId'] ?? null),
            'isManualCapture' => $sessionData['isManualCapture'] ?? $this->config->isManualCapture($sessionData['storeId'] ?? null),
            'culture' => $sessionData['culture'] ?? $this->config->getCulture($sessionData['storeId'] ?? null),
            'expiresAt' => $sessionData['expiresAt'] ?? $this->getExpirationTime($sessionData['storeId'] ?? null)
        ]);

        if (isset($request['items']) && empty($request['items'])) {
            unset($request['items']);
        }

        $transfer = $this->transferFactory->create($request);
        return $this->httpClient->placeRequest($transfer);
    }
    
    /**
     * @inheritDoc
     */
    public function capture(array $captureData): array
    {
        $this->validateRequiredFields($captureData, 'capture');
        
        $request = array_merge($captureData, ['operation' => 'capture']);
        $transfer = $this->transferFactory->create($request);
        
        return $this->httpClient->placeRequest($transfer);
    }
    
    /**
     * @inheritDoc
     */
    public function getStatus(string $checkoutReference): array
    {
        $request = [
            'operation' => 'status',
            'checkoutReference' => $checkoutReference
        ];
        
        $transfer = $this->transferFactory->create($request);
        return $this->httpClient->placeRequest($transfer);
    }
    
    /**
     * @inheritDoc
     */
    public function getEmbeddedStatus(string $checkoutReference, ?int $storeId = null): array
    {
        $request = [
            'operation' => 'embedded_status',
            'checkoutReference' => $checkoutReference
        ];

        if ($storeId !== null) {
            $request['storeId'] = $storeId;
        }

        $transfer = $this->transferFactory->create($request);
        return $this->httpClient->placeRequest($transfer);
    }
    
    /**
     * @inheritDoc
     */
    public function refund(array $refundData): array
    {
        $this->validateRequiredFields($refundData, 'refund');
        
        $request = array_merge($refundData, [
            'operation' => 'refund',
            'refundReason' => $refundData['refundReason'] ?? 'Customer request'
        ]);
        
        $transfer = $this->transferFactory->create($request);
        return $this->httpClient->placeRequest($transfer);
    }
    
    /**
     * @inheritDoc
     */
    public function reverse(array $reverseData): array
    {
        $this->validateRequiredFields($reverseData, 'reverse');
        
        $request = array_merge($reverseData, ['operation' => 'reverse']);
        $transfer = $this->transferFactory->create($request);
        
        return $this->httpClient->placeRequest($transfer);
    }
    
    /**
     * Validate required fields for operation
     *
     * @param array $data
     * @param string $operation
     * @throws LocalizedException
     */
    private function validateRequiredFields(array $data, string $operation): void
    {
        $config = $this->config->getEndpointConfig($operation);
        if (empty($config)) {
            throw new LocalizedException(__('Invalid operation: %1', $operation));
        }
        
        $requiredFields = $config['required_fields'] ?? [];
        $missingFields = [];
        
        foreach ($requiredFields as $field) {
            // Skip fields that have defaults
            if (in_array($field, ['terminalIdentifier', 'isManualCapture', 'culture', 'expiresAt', 'refundReason'])) {
                continue;
            }
            
            if (!isset($data[$field]) || $data[$field] === '') {
                $missingFields[] = $field;
            }
        }
        
        if (!empty($missingFields)) {
            throw new LocalizedException(
                __('Required fields missing: %1', implode(', ', $missingFields))
            );
        }
    }
    
    /**
     * Get expiration time for session
     *
     * @return string
     */
    private function getExpirationTime(?int $storeId = null): string
    {
        $minutes = $this->config->getSessionExpiration($storeId);
        return (new \DateTime())
            ->add(new \DateInterval("PT{$minutes}M"))
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
