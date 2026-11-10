<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Security;

use Psr\Log\LoggerInterface;
use Straumur\Payment\Api\Security\HmacValidatorInterface;
use Straumur\Payment\Gateway\Config\Config;
use Straumur\Payment\Model\Webhook\WebhookPayload;

/**
 * Straumur payment webhook signature.
 *
 * Matches the backend's HmacHelpers.GetHmacSignature: the values of checkoutReference, payfacReference,
 * merchantReference, amount, currency, reason and success joined with ":" (null or missing as ""),
 * HMAC-SHA256 with the hex-decoded key, base64-encoded.
 */
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
     * @param Config $gatewayConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $gatewayConfig,
        LoggerInterface $logger
    ) {
        $this->gatewayConfig = $gatewayConfig;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function validateSignature(array $payload, string $receivedSignature, string $secret): bool
    {
        if ($secret === '' || $receivedSignature === '') {
            return false;
        }

        $expectedSignature = $this->calculateSignature($payload, $secret);

        return $expectedSignature !== '' && hash_equals($expectedSignature, $receivedSignature);
    }

    /**
     * @inheritDoc
     */
    public function calculateSignature(array $payload, string $secret): string
    {
        $binarySecret = $this->decodeHexSecret($secret);

        if ($binarySecret === null) {
            $this->logger->error('Straumur webhook secret is not a hexadecimal string; signatures cannot be verified.');
            return '';
        }

        return base64_encode(hash_hmac('sha256', $this->buildMessageString($payload), $binarySecret, true));
    }

    /**
     * @inheritDoc
     */
    public function buildMessageString(array $payload): string
    {
        $values = [];

        foreach ($this->gatewayConfig->getHmacFields() as $field) {
            $values[] = WebhookPayload::string($payload, $field);
        }

        return implode(':', $values);
    }

    /**
     * Hex key to bytes. Like the backend, an odd-length key gets a trailing "0".
     *
     * @param string $secret
     * @return string|null null when the key is not hexadecimal
     */
    private function decodeHexSecret(string $secret): ?string
    {
        $secret = trim($secret);

        if ($secret === '' || !ctype_xdigit($secret)) {
            return null;
        }

        if (strlen($secret) % 2 === 1) {
            $secret .= '0';
        }

        $binary = hex2bin($secret);

        return $binary === false ? null : $binary;
    }
}
