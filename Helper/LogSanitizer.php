<?php
declare(strict_types=1);

namespace Straumur\Payment\Helper;

/**
 * Helper class for sanitizing sensitive data before logging
 */
class LogSanitizer
{
    /**
     * Sensitive field patterns to mask
     */
    private const SENSITIVE_FIELDS = [
        'X-API-KEY',
        'api_key',
        'apiKey',
        'secret',
        'webhook_secret',
        'hmacSignature',
        'signature',
        'payfacReference',
        'password',
        'pwd'
    ];

    /**
     * Sensitive field patterns for partial masking
     */
    private const PARTIAL_MASK_FIELDS = [
        'checkoutReference'
    ];

    /**
     * Replacement text for redacted data
     */
    private const REDACTED_TEXT = '***REDACTED***';
    
    /**
     * Sanitize array data for logging by masking sensitive fields
     *
     * @param array $data
     * @return array
     */
    public function sanitizeArray(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value);
            } elseif ($this->isSensitiveField($key)) {
                $sanitized[$key] = self::REDACTED_TEXT;
            } elseif ($this->isPartialMaskField($key)) {
                $sanitized[$key] = $this->partialMask((string)$value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }

    /**
     * Sanitize string data for logging
     *
     * @param string $data
     * @return string
     */
    public function sanitizeString(string $data): string
    {
        // Mask common patterns in strings
        $patterns = [
            // API keys (typically 32+ alphanumeric characters)
            '/([a-zA-Z0-9]{32,})/' => self::REDACTED_TEXT,
            // Credit card numbers
            '/\b\d{4}[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b/' => self::REDACTED_TEXT,
            // CVV codes
            '/\bcvv[\s:=]\d{3,4}\b/i' => 'cvv:' . self::REDACTED_TEXT,
        ];

        foreach ($patterns as $pattern => $replacement) {
            $data = preg_replace($pattern, $replacement, $data);
        }

        return $data;
    }

    /**
     * Check if field name indicates sensitive data
     *
     * @param string $fieldName
     * @return bool
     */
    private function isSensitiveField(string $fieldName): bool
    {
        $lowerFieldName = strtolower($fieldName);
        
        foreach (self::SENSITIVE_FIELDS as $sensitiveField) {
            if (stripos($fieldName, $sensitiveField) !== false || 
                stripos($lowerFieldName, strtolower($sensitiveField)) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if field should be partially masked
     *
     * @param string $fieldName
     * @return bool
     */
    private function isPartialMaskField(string $fieldName): bool
    {
        $lowerFieldName = strtolower($fieldName);
        
        foreach (self::PARTIAL_MASK_FIELDS as $partialField) {
            if (stripos($fieldName, $partialField) !== false || 
                stripos($lowerFieldName, strtolower($partialField)) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Partially mask a string value (show first 4 and last 4 characters)
     *
     * @param string $value
     * @return string
     */
    private function partialMask(string $value): string
    {
        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }
        
        return substr($value, 0, 4) . str_repeat('*', strlen($value) - 8) . substr($value, -4);
    }

    /**
     * Sanitize webhook payload specifically for logging
     *
     * @param array $payload
     * @return array
     */
    public function sanitizeWebhookPayload(array $payload): array
    {
        $sanitized = $this->sanitizeArray($payload);
        
        // Additional webhook-specific sanitization
        if (isset($payload['additionalData'])) {
            $sanitized['additionalData'] = $this->sanitizeArray($payload['additionalData']);
        }
        
        return $sanitized;
    }

    /**
     * Sanitize HTTP headers for logging
     *
     * @param array $headers
     * @return array
     */
    public function sanitizeHeaders(array $headers): array
    {
        return $this->sanitizeArray($headers);
    }
}