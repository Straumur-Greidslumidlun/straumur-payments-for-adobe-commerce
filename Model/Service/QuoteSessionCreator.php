<?php
declare(strict_types=1);

namespace Straumur\Payment\Model\Service;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;
use Straumur\Payment\Api\StraumurApiInterface;
use Straumur\Payment\Helper\Data as StraumurHelper;
use Straumur\Payment\Model\Ui\ConfigProvider;

/**
 * Creates Straumur payment sessions in quote context for headless storefronts.
 */
class QuoteSessionCreator
{
    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var StraumurApiInterface
     */
    private $straumurApi;

    /**
     * @var SessionManager
     */
    private $sessionManager;

    /**
     * @var StraumurHelper
     */
    private $helper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CartRepositoryInterface $cartRepository
     * @param StraumurApiInterface $straumurApi
     * @param SessionManager $sessionManager
     * @param StraumurHelper $helper
     * @param LoggerInterface $logger
     */
    public function __construct(
        CartRepositoryInterface $cartRepository,
        StraumurApiInterface $straumurApi,
        SessionManager $sessionManager,
        StraumurHelper $helper,
        LoggerInterface $logger
    ) {
        $this->cartRepository = $cartRepository;
        $this->straumurApi = $straumurApi;
        $this->sessionManager = $sessionManager;
        $this->helper = $helper;
        $this->logger = $logger;
    }

    /**
     * Create an embedded checkout session for the supplied quote.
     *
     * @param Quote $quote
     * @param string $origin
     * @param string $threeDsReturnUrl
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    public function create(Quote $quote, string $origin, string $threeDsReturnUrl, array $options = []): array
    {
        if (!$quote->getId() || !$quote->hasItems()) {
            throw new LocalizedException(__('Cannot create Straumur session for an empty cart.'));
        }

        if (!$quote->getGrandTotal() || $quote->getGrandTotal() <= 0) {
            throw new LocalizedException(__('Cart total is zero. Straumur requires a payable amount.'));
        }

        $storeId = (int) $quote->getStoreId();

        if (!$this->helper->useQuoteSession($storeId)) {
            throw new LocalizedException(__('Quote session mode is not enabled for Straumur.'));
        }

        if (!$quote->getReservedOrderId()) {
            $quote->reserveOrderId();
        }

        $currencyCode = $quote->getQuoteCurrencyCode();
        if (!$currencyCode) {
            throw new LocalizedException(__('Cart currency could not be determined.'));
        }

        $amountMinorUnits = (string) $this->helper->formatAmount(
            (float) $quote->getGrandTotal(),
            $currencyCode
        );

        $sessionRequest = [
            'storeId' => $storeId,
            'amount' => $amountMinorUnits,
            'currency' => $currencyCode,
            'origin' => $origin,
            'reference' => (string) $quote->getReservedOrderId(),
            'terminalIdentifier' => $this->helper->getTerminalId($storeId),
            'threeDsReturnUrl' => $threeDsReturnUrl,
            'isManualCapture' => $options['isManualCapture'] ?? $this->helper->isManualCapture($storeId),
            'culture' => $options['culture'] ?? $this->helper->getCulture($storeId),
            'expiresAt' => $options['expiresAt'] ?? $this->helper->getSessionExpiration($storeId)
        ];

        if (!empty($options['sendItems']) || $this->helper->shouldSendItems($storeId)) {
            $items = $this->helper->getQuoteItems($quote, $storeId);
            if (!empty($items)) {
                $sessionRequest['items'] = $items;
            }
        }

        try {
            $response = $this->straumurApi->createEmbeddedSession($sessionRequest);
        } catch (\Exception $exception) {
            $this->logger->error('Straumur embedded session creation failed', [
                'error' => $exception->getMessage(),
                'quote_id' => $quote->getId()
            ]);
            throw new LocalizedException(__('Failed to create Straumur session: %1', $exception->getMessage()));
        }

        $sessionId = $response['sessionId'] ?? null;
        $checkoutReference = $response['checkoutReference'] ?? null;
        if (!$sessionId || !$checkoutReference) {
            $this->logger->error('Straumur embedded session response missing identifiers', [
                'response' => $response,
                'quote_id' => $quote->getId()
            ]);
            throw new LocalizedException(__('Straumur did not return a valid session.'));
        }

        $sessionToken = $this->sessionManager->createSession(
            $checkoutReference,
            $response['expiresAt'] ?? ($sessionRequest['expiresAt'] ?? null)
        );

        $payment = $quote->getPayment();
        $payment->setMethod(ConfigProvider::CODE);
        $payment->setAdditionalInformation('straumur_session_id', $sessionId);
        $payment->setAdditionalInformation('straumur_checkout_reference', $checkoutReference);
        $payment->setAdditionalInformation('straumur_session_token', $sessionToken);
        $payment->setAdditionalInformation('straumur_session_created_at', time());
        $payment->setAdditionalInformation('straumur_response_datetime', $response['responseDateTime'] ?? '');
        $payment->setAdditionalInformation('straumur_response_identifier', $response['responseIdentifier'] ?? '');
        $payment->setAdditionalInformation('straumur_origin', $origin);
        $payment->setAdditionalInformation('straumur_three_ds_return_url', $threeDsReturnUrl);
        $payment->setAdditionalInformation('straumur_session_amount', $amountMinorUnits);
        $payment->setAdditionalInformation('straumur_session_currency', $currencyCode);

        $this->cartRepository->save($quote);

        $this->logger->info('Straumur embedded session created for quote', [
            'quote_id' => $quote->getId(),
            'reserved_order_id' => $quote->getReservedOrderId(),
            'checkout_reference' => $checkoutReference
        ]);

        return [
            'checkout_reference' => $checkoutReference,
            'session_id' => $sessionId,
            'session_token' => $sessionToken,
            'response_identifier' => $response['responseIdentifier'] ?? null,
            'response_date_time' => $response['responseDateTime'] ?? null,
            'expires_at' => $response['expiresAt'] ?? ($sessionRequest['expiresAt'] ?? null)
        ];
    }
}
