<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Resolver;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\Resolver\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Straumur\Payment\Api\StraumurApiInterface;
use Straumur\Payment\Model\Service\SessionManager;
use Psr\Log\LoggerInterface;

class GetEmbeddedSessionStatus implements ResolverInterface
{
    /**
     * @var SessionManager
     */
    private $sessionManager;

    /**
     * @var StraumurApiInterface
     */
    private $straumurApi;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param SessionManager $sessionManager
     * @param StraumurApiInterface $straumurApi
     * @param LoggerInterface $logger
     */
    public function __construct(
        SessionManager $sessionManager,
        StraumurApiInterface $straumurApi,
        LoggerInterface $logger
    ) {
        $this->sessionManager = $sessionManager;
        $this->straumurApi = $straumurApi;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        if (!$context instanceof ContextInterface) {
            throw new GraphQlAuthorizationException(__('Invalid GraphQL context.'));
        }

        if (!isset($args['sessionToken']) || trim((string) $args['sessionToken']) === '') {
            throw new GraphQlInputException(__('Session token is required.'));
        }

        $sessionToken = (string) $args['sessionToken'];

        $sessionData = $this->sessionManager->validateSession($sessionToken);
        if (!$sessionData || empty($sessionData['checkout_reference'])) {
            throw new GraphQlInputException(__('Session token is invalid or has expired.'));
        }

        $checkoutReference = (string) $sessionData['checkout_reference'];

        $store = $context->getExtensionAttributes()->getStore();
        $storeId = $store ? (int) $store->getId() : null;

        try {
            $status = $this->straumurApi->getEmbeddedStatus($checkoutReference, $storeId);
        } catch (LocalizedException $exception) {
            $this->logger->error('Failed to fetch Straumur embedded status', [
                'checkout_reference' => $checkoutReference,
                'session_token' => $sessionToken,
                'error' => $exception->getMessage()
            ]);
            throw new GraphQlInputException(
                __('Unable to fetch Straumur status: %1', $exception->getMessage())
            );
        } catch (\Exception $exception) {
            $this->logger->error('Unexpected error fetching Straumur embedded status', [
                'checkout_reference' => $checkoutReference,
                'session_token' => $sessionToken,
                'error' => $exception->getMessage()
            ]);
            throw new GraphQlInputException(__('Unable to fetch Straumur status.'));
        }

        $rawPayload = null;
        if (!empty($status)) {
            $encoded = json_encode($status);
            if ($encoded !== false) {
                $rawPayload = $encoded;
            }
        }

        return [
            'checkoutReference' => $checkoutReference,
            'status' => $status['status'] ?? null,
            'payfacReference' => $status['payfacReference'] ?? null,
            'responseDateTime' => $status['responseDateTime'] ?? null,
            'responseIdentifier' => $status['responseIdentifier'] ?? null,
            'rawPayload' => $rawPayload
        ];
    }
}
