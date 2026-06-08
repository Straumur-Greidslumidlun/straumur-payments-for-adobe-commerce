<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\Resolver\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;
use Straumur\Payment\Model\Service\QuoteSessionCreator;

class CreateEmbeddedSession implements ResolverInterface
{
    /**
     * @var GetCartForUser
     */
    private $getCartForUser;

    /**
     * @var QuoteSessionCreator
     */
    private $quoteSessionCreator;

    /**
     * @param GetCartForUser $getCartForUser
     * @param QuoteSessionCreator $quoteSessionCreator
     */
    public function __construct(
        GetCartForUser $getCartForUser,
        QuoteSessionCreator $quoteSessionCreator
    ) {
        $this->getCartForUser = $getCartForUser;
        $this->quoteSessionCreator = $quoteSessionCreator;
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

        if (!isset($args['input'])) {
            throw new GraphQlInputException(__('Input is required to create a Straumur session.'));
        }

        $input = $args['input'];
        $this->assertRequiredInput($input);

        $store = $context->getExtensionAttributes()->getStore();
        $storeId = $store ? (int) $store->getId() : null;

        $quote = $this->getCartForUser->execute(
            $input['cartId'],
            $context->getUserId(),
            $storeId,
            $context->getUserType()
        );

        $options = [
            'culture' => $input['culture'] ?? null,
            'expiresAt' => $input['expiresAt'] ?? null,
            'isManualCapture' => $input['manualCapture'] ?? null,
            'sendItems' => $input['sendItems'] ?? null
        ];

        $result = $this->quoteSessionCreator->create(
            $quote,
            $input['origin'],
            $input['threeDsReturnUrl'],
            $options
        );

        return [
            'checkoutReference' => $result['checkout_reference'],
            'sessionId' => $result['session_id'],
            'sessionToken' => $result['session_token'],
            'responseIdentifier' => $result['response_identifier'] ?? null,
            'responseDateTime' => $result['response_date_time'] ?? null,
            'expiresAt' => $result['expires_at'] ?? null
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return void
     * @throws GraphQlInputException
     */
    private function assertRequiredInput(array $input): void
    {
        foreach (['cartId', 'origin', 'threeDsReturnUrl'] as $requiredField) {
            if (empty($input[$requiredField])) {
                throw new GraphQlInputException(
                    __('Field "%1" is required to create a Straumur session.', $requiredField)
                );
            }
        }

        if (!filter_var($input['origin'], FILTER_VALIDATE_URL)) {
            throw new GraphQlInputException(__('The origin must be a valid URL.'));
        }

        if (!filter_var($input['threeDsReturnUrl'], FILTER_VALIDATE_URL)) {
            throw new GraphQlInputException(__('The threeDsReturnUrl must be a valid URL.'));
        }
    }
}
