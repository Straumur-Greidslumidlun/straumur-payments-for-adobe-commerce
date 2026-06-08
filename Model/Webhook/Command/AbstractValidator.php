<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Webhook\Command;

use Straumur\Payment\Api\Webhook\ValidatorInterface;
use Straumur\Payment\Gateway\Config\Config;
use Straumur\Payment\Api\Security\HmacValidatorInterface;
use Straumur\Payment\Helper\Data as StraumurHelper;
use Magento\Framework\Exception\LocalizedException;

abstract class AbstractValidator implements ValidatorInterface
{
    /**
     * @var Config
     */
    protected $gatewayConfig;

    /**
     * @var HmacValidatorInterface
     */
    protected $hmacValidator;

    /**
     * @var StraumurHelper
     */
    protected $straumurHelper;

    /**
     * @var string
     */
    protected $errorMessage = '';

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @param Config $gatewayConfig
     * @param HmacValidatorInterface $hmacValidator
     * @param StraumurHelper $straumurHelper
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        Config $gatewayConfig,
        HmacValidatorInterface $hmacValidator,
        StraumurHelper $straumurHelper,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->gatewayConfig = $gatewayConfig;
        $this->hmacValidator = $hmacValidator;
        $this->straumurHelper = $straumurHelper;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function validate(array $payload, array $headers): bool
    {
        try {
            $this->logger->info('=== WEBHOOK VALIDATION START ===');
            $this->logger->info('Validating webhook structure...');
            $this->validateStructure($payload);
            $this->logger->info('Structure validation passed');
            
            $this->logger->info('Validating webhook signature...');
            $this->validateSignature($payload, $headers);
            $this->logger->info('Signature validation passed');
            $this->logger->info('=== WEBHOOK VALIDATION END ===');
            
            return true;
        } catch (\Exception $e) {
            $this->errorMessage = $e->getMessage();
            $this->logger->error('Webhook validation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * Validate payload structure
     *
     * @param array $payload
     * @throws LocalizedException
     */
    protected function validateStructure(array $payload): void
    {
        $requiredFields = $this->getRequiredFields();
        
        foreach ($requiredFields as $field) {
            if (!isset($payload[$field]) && !isset($payload[ucfirst($field)])) {
                throw new LocalizedException(__('Missing required field: %1', $field));
            }
        }
    }

    /**
     * Validate HMAC signature
     *
     * @param array $payload
     * @param array $headers
     * @throws LocalizedException
     */
    protected function validateSignature(array $payload, array $headers): void
    {
        $this->logger->info('Validating HMAC signature from payload body...');
        
        // Extract signature from payload body (Straumur format)
        $signature = $this->extractSignatureFromPayload($payload);
        
        if (empty($signature)) {
            $this->logger->error('No HMAC signature found in payload. Available fields: ' . json_encode(array_keys($payload)));
            throw new LocalizedException(__('Missing webhook signature in payload'));
        }
        
        $this->logger->info('Found HMAC signature in payload: ' . $signature);

        $secret = $this->getWebhookSecret();
        
        if (empty($secret)) {
            $this->logger->error('Webhook secret not configured in system configuration');
            throw new LocalizedException(__('Webhook secret not configured'));
        }
        
        $this->logger->info('Using webhook secret configured: YES');
        $this->logger->info('Validating HMAC signature...');

        if (!$this->hmacValidator->validateSignature($payload, $signature, $secret)) {
            $this->logger->error('HMAC validation failed');
            $this->logger->error('Expected signature does not match provided signature');
            throw new LocalizedException(__('Invalid webhook signature - HMAC validation failed'));
        }
        
        $this->logger->info('HMAC signature validation successful');
    }

    /**
     * Extract HMAC signature from payload body (Straumur format)
     *
     * @param array $payload
     * @return string
     */
    protected function extractSignatureFromPayload(array $payload): string
    {
        // Straumur sends HMAC signature in payload body as 'hmacSignature' field
        return trim((string) ($payload['hmacSignature'] ?? $payload['HmacSignature'] ?? ''));

    /**
     * Get webhook secret from configuration
     *
     * @return string
     */
    protected function getWebhookSecret(): string
    {
        return $this->straumurHelper->getWebhookSecret();
    }

    /**
     * Get required fields for validation
     *
     * @return array
     */
    abstract protected function getRequiredFields(): array;
}