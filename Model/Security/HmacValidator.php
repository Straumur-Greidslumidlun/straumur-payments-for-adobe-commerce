<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Security;

use Straumur\Payment\Api\Security\HmacValidatorInterface;
use Straumur\Payment\Gateway\Config\Config;
use Straumur\Payment\Helper\LogSanitizer;
use Psr\Log\LoggerInterface;

class HmacValidator implements HmacValidatorInterface
{
    /**
     * @var Config
     */
    private $gatewayConfig;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var LogSanitizer
     */
    private $logSanitizer;

    /**
     * @param Config $gatewayConfig
     * @param LoggerInterface $logger
     * @param LogSanitizer $logSanitizer
     */
    public function __construct(
        Config $gatewayConfig,
        LoggerInterface $logger,
        LogSanitizer $logSanitizer
    ) {
        $this->gatewayConfig = $gatewayConfig;
        $this->logger = $logger;
        $this->logSanitizer = $logSanitizer;
    }

    /**
     * @inheritDoc
     */
    public function validateSignature(array $payload, string $receivedSignature, string $secret): bool
    {
        $this->logger->info('=== HMAC SIGNATURE VALIDATION START ===');
        
        if (empty($secret)) {
            $this->logger->error('HMAC validation failed: Empty webhook secret');
            return false;
        }

        if (empty($receivedSignature)) {
            $this->logger->error('HMAC validation failed: Empty signature');
            return false;
        }

        $this->logger->info('Received signature: ***REDACTED***');
        $this->logger->info('Secret configured: ' . (!empty($secret) ? 'YES' : 'NO'));
        $this->logger->info('Secret length: ' . strlen($secret));

        $expectedSignature = $this->calculateSignature($payload, $secret);
        
        $sanitizedPayload = $this->logSanitizer->sanitizeWebhookPayload($payload);
        $this->logger->info('HMAC Validation Details:');
        $this->logger->info('- Payload: ' . json_encode($sanitizedPayload));
        $this->logger->info('- Message string length: ' . strlen($this->buildMessageString($payload)));
        $this->logger->info('- Expected signature: ***REDACTED***');
        $this->logger->info('- Received signature: ***REDACTED***');

        $isValid = hash_equals($expectedSignature, $receivedSignature);
        
        if (!$isValid) {
            $this->logger->error('HMAC validation failed: Signature mismatch');
            $this->logger->error('Expected: ***REDACTED***');
            $this->logger->error('Received: ***REDACTED***');
            $this->logger->error('Message used for HMAC length: ' . strlen($this->buildMessageString($payload)));
        } else {
            $this->logger->info('HMAC signature validation PASSED');
        }
        
        $this->logger->info('=== HMAC SIGNATURE VALIDATION END ===');

        return $isValid;
    }

    /**
     * @inheritDoc
     */
    public function calculateSignature(array $payload, string $secret): string
    {
        $message = $this->buildMessageString($payload);
        
        // Convert hex secret to binary as per Straumur documentation
        $binarySecret = hex2bin($secret);
        if ($binarySecret === false) {
            $this->logger->error('Invalid webhook secret: expected hexadecimal string');
            return '';
        }

        $hash = hash_hmac('sha256', $message, $binarySecret, true);
        return base64_encode($hash);
    }

    /**
     * @inheritDoc
     */
    public function buildMessageString(array $payload): string
    {
        $hmacFields = $this->gatewayConfig->getHmacFields();
        $values = [];

        $this->logger->info('Building HMAC message string with fields: ' . json_encode($hmacFields));

        foreach ($hmacFields as $field) {
            if (array_key_exists($field, $payload)) {
                $value = $payload[$field];
                
                // Convert boolean values to string as per Straumur spec
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                } elseif (is_null($value)) {
                    $value = '';
                } else {
                    $value = (string)$value;
                }
                
                $values[] = $value;
                
                // Log field with sanitization for sensitive data
                $logValue = $this->isSensitiveHmacField($field) ? '***REDACTED***' : $value;
                $this->logger->info('HMAC field added: ' . $field . ' = "' . $logValue . '" (type: ' . gettype($payload[$field]) . ')');
            } else {
                // Field not present in payload - add empty string
                $values[] = '';
                
                $this->logger->info('HMAC field missing: ' . $field . ' - using empty string');
            }
        }

        // Join values with colon separator as per Straumur documentation
        $message = implode(':', $values);
        
        $sanitizedValues = [];
        foreach ($values as $i => $value) {
            $field = $hmacFields[$i] ?? 'unknown';
            $sanitizedValues[] = $this->isSensitiveHmacField($field) ? '***REDACTED***' : $value;
        }
        
        $this->logger->info('HMAC message built:');
        $this->logger->info('- Fields: ' . json_encode($hmacFields));
        $this->logger->info('- Values: ' . json_encode($sanitizedValues));
        $this->logger->info('- Message length: ' . strlen($message));

        return $message;
    }

    /**
     * Check if HMAC field contains sensitive data that should not be logged
     *
     * @param string $fieldName
     * @return bool
     */
    private function isSensitiveHmacField(string $fieldName): bool
    {
        $sensitiveFields = [
            'payfacReference',
            'signature',
            'hmacSignature',
            'token',
            'authorization'
        ];
        
        return in_array(strtolower($fieldName), array_map('strtolower', $sensitiveFields));
    }
}