<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Webhook\Command;

use Psr\Log\LoggerInterface;
use Straumur\Payment\Api\Security\HmacValidatorInterface;
use Straumur\Payment\Api\Webhook\ValidatorInterface;
use Straumur\Payment\Helper\Data as StraumurHelper;
use Straumur\Payment\Model\Webhook\WebhookPayload;

/**
 * Verifies that a webhook was signed by Straumur with this shop's webhook secret.
 *
 * Only the signature is checked. Straumur sends every payment on the merchant's contracts, including
 * terminal payments and payment links where merchantReference and checkoutReference are null, so
 * requiring business fields here would reject correctly signed events.
 */
abstract class AbstractValidator implements ValidatorInterface
{
    public const ERROR_SECRET_NOT_CONFIGURED = 'Webhook secret not configured';
    public const ERROR_MISSING_SIGNATURE = 'Missing webhook signature';
    public const ERROR_INVALID_SIGNATURE = 'Invalid webhook signature';

    /**
     * @var HmacValidatorInterface
     */
    protected $hmacValidator;

    /**
     * @var StraumurHelper
     */
    protected $straumurHelper;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var string
     */
    protected $errorMessage = '';

    /**
     * @param HmacValidatorInterface $hmacValidator
     * @param StraumurHelper $straumurHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        HmacValidatorInterface $hmacValidator,
        StraumurHelper $straumurHelper,
        LoggerInterface $logger
    ) {
        $this->hmacValidator = $hmacValidator;
        $this->straumurHelper = $straumurHelper;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function validate(array $payload, array $headers): bool
    {
        $this->errorMessage = '';

        $secret = trim($this->straumurHelper->getWebhookSecret());
        if ($secret === '') {
            return $this->fail(self::ERROR_SECRET_NOT_CONFIGURED);
        }

        $signature = trim(WebhookPayload::string($payload, 'hmacSignature'));
        if ($signature === '') {
            return $this->fail(self::ERROR_MISSING_SIGNATURE);
        }

        if (!$this->hmacValidator->validateSignature($payload, $signature, $secret)) {
            return $this->fail(self::ERROR_INVALID_SIGNATURE);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * Record the reason and report failure.
     *
     * @param string $message
     * @return bool
     */
    private function fail(string $message): bool
    {
        $this->errorMessage = $message;

        return false;
    }
}
