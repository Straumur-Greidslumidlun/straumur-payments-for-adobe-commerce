<?php
declare(strict_types=1);

namespace Straumur\Payment\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Psr\Log\LoggerInterface;
use Straumur\Payment\Api\Webhook\RouterInterface;
use Straumur\Payment\Api\Webhook\ValidatorInterface;
use Straumur\Payment\Helper\Data as StraumurHelper;
use Straumur\Payment\Model\Webhook\OrderResolver;
use Straumur\Payment\Model\Webhook\WebhookPayload;

/**
 * Straumur payment webhook endpoint: POST /straumur/webhook
 *
 * Straumur sends a merchant's webhook every payment on the contracts it is subscribed to, and treats any
 * non-2xx answer as the endpoint failing (events are held and the webhook is eventually disabled). So:
 * - a request that does not carry a valid signature for this shop's secret gets 403 (or 400 if it is not JSON);
 * - every correctly signed request gets 200: events for other orders are acknowledged as ignored, and
 *   processing errors are logged and acknowledged.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * Maximum webhook payload size (10MB as per Straumur API documentation)
     */
    private const MAX_PAYLOAD_SIZE = 10 * 1024 * 1024;

    public const MESSAGE_PROCESSED = 'Processed.';
    public const MESSAGE_IGNORED_FOREIGN = 'Ignored: not an order of this shop.';
    public const MESSAGE_IGNORED_EVENT = 'Ignored: unsupported event type.';
    public const MESSAGE_FAILED = 'Received; processing failed and was logged.';

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
     * @var ValidatorInterface
     */
    private $signatureValidator;

    /**
     * @var OrderResolver
     */
    private $orderResolver;

    /**
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param RouterInterface $webhookRouter
     * @param LoggerInterface $logger
     * @param RemoteAddress $remoteAddress
     * @param StraumurHelper $straumurHelper
     * @param ValidatorInterface $signatureValidator
     * @param OrderResolver $orderResolver
     */
    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        RouterInterface $webhookRouter,
        LoggerInterface $logger,
        RemoteAddress $remoteAddress,
        StraumurHelper $straumurHelper,
        ValidatorInterface $signatureValidator,
        OrderResolver $orderResolver
    ) {
        $this->request = $request;
        $this->jsonFactory = $jsonFactory;
        $this->webhookRouter = $webhookRouter;
        $this->logger = $logger;
        $this->remoteAddress = $remoteAddress;
        $this->straumurHelper = $straumurHelper;
        $this->signatureValidator = $signatureValidator;
        $this->orderResolver = $orderResolver;
    }

    /**
     * @inheritDoc
     */
    public function execute(): ResultInterface
    {
        if (!$this->isSourceAllowed()) {
            return $this->respond(403, ['message' => 'Forbidden.']);
        }

        $payload = $this->readPayload();
        if ($payload === null) {
            return $this->respond(400, ['message' => 'Invalid payload.']);
        }

        // Nothing in the payload is trusted until the signature is verified.
        if (!$this->signatureValidator->validate($payload, [])) {
            $this->logger->warning('Straumur webhook rejected: ' . $this->signatureValidator->getErrorMessage(), [
                'client_ip' => $this->remoteAddress->getRemoteAddress(),
            ]);
            return $this->respond(403, ['message' => 'Invalid signature.']);
        }

        // From here on the request is from Straumur, and the answer is always 200.
        try {
            $order = $this->orderResolver->resolve($payload);

            if ($order === null) {
                $this->logger->info(
                    'Straumur webhook ignored: not an order of this shop.',
                    WebhookPayload::logContext($payload)
                );
                return $this->respond(200, ['message' => self::MESSAGE_IGNORED_FOREIGN, 'ignored' => true]);
            }

            $result = $this->webhookRouter->route($payload, $order);

            if (($result['status'] ?? null) === 'ignored') {
                return $this->respond(200, ['message' => self::MESSAGE_IGNORED_EVENT, 'ignored' => true]);
            }

            return $this->respond(200, ['message' => self::MESSAGE_PROCESSED]);
        } catch (\Throwable $e) {
            $this->logger->error('Straumur webhook processing failed', WebhookPayload::logContext($payload) + [
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return $this->respond(200, ['message' => self::MESSAGE_FAILED, 'processed' => false]);
        }
    }

    /**
     * JSON response with a status code.
     *
     * @param int $statusCode
     * @param array $data
     * @return Json
     */
    private function respond(int $statusCode, array $data): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode($statusCode);
        $result->setData($data);

        return $result;
    }

    /**
     * Decoded JSON object from the request body, or null when it is missing, too large or not a JSON object.
     *
     * @return array|null
     */
    private function readPayload(): ?array
    {
        $rawPayload = (string) $this->request->getContent();

        if ($rawPayload === '' || strlen($rawPayload) > self::MAX_PAYLOAD_SIZE) {
            $this->logger->warning('Straumur webhook rejected: empty or oversized body', [
                'payload_size' => strlen($rawPayload),
            ]);
            return null;
        }

        $payload = json_decode($rawPayload, true);

        if (!is_array($payload)) {
            $this->logger->warning('Straumur webhook rejected: body is not a JSON object', [
                'json_error' => json_last_error_msg(),
            ]);
            return null;
        }

        return $payload;
    }

    /**
     * Whether the client IP passes the optional allow-list from configuration.
     *
     * @return bool
     */
    private function isSourceAllowed(): bool
    {
        $allowedIps = $this->straumurHelper->getWebhookAllowedIps();

        if (empty($allowedIps)) {
            return true;
        }

        $clientIp = (string) $this->remoteAddress->getRemoteAddress();

        if ($this->isIpAllowed($clientIp, $allowedIps)) {
            return true;
        }

        $this->logger->error('Straumur webhook rejected: IP not in allowed list', ['client_ip' => $clientIp]);

        return false;
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
            $allowedIp = trim((string) $allowedIp);

            if ($allowedIp === $clientIp) {
                return true;
            }

            if (strpos($allowedIp, '/') !== false && $this->ipInCidr($clientIp, $allowedIp)) {
                return true;
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
        // Webhooks are authenticated by their HMAC signature instead.
        return true;
    }
}
