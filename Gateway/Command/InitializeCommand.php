<?php
declare(strict_types=1);

namespace Straumur\Payment\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Straumur\Payment\Helper\Data as StraumurHelper;

/**
 * Initialize command - called during order placement for redirect payments
 * Does NOT authorize - just creates session and keeps order in pending state
 */
class InitializeCommand implements CommandInterface
{
    /**
     * @var AuthorizeCommand
     */
    private $authorizeCommand;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var StraumurHelper
     */
    private $helper;

    /**
     * @param AuthorizeCommand $authorizeCommand
     * @param LoggerInterface $logger
     * @param StraumurHelper $helper
     */
    public function __construct(
        AuthorizeCommand $authorizeCommand,
        LoggerInterface $logger,
        StraumurHelper $helper
    ) {
        $this->authorizeCommand = $authorizeCommand;
        $this->logger = $logger;
        $this->helper = $helper;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $commandSubject): void
    {
        $this->logger->info('[FLOW_TRACKER] InitializeCommand starting');

        $stateObject = SubjectReader::readStateObject($commandSubject);
        $paymentDO = SubjectReader::readPayment($commandSubject);
        $payment = $paymentDO->getPayment();
        $order = $payment->getOrder();
        $storeId = (int) $order->getStoreId();

        $useQuoteSession = $this->helper->useQuoteSession($storeId);
        $checkoutReference = $payment->getAdditionalInformation('straumur_checkout_reference');
        $sessionId = $payment->getAdditionalInformation('straumur_session_id');

        if ($useQuoteSession) {
            $this->logger->info('[FLOW_TRACKER] InitializeCommand using existing quote session', [
                'order_id' => $order->getIncrementId(),
                'checkout_reference' => $checkoutReference,
                'has_session_id' => !empty($sessionId)
            ]);

            if (!$checkoutReference || !$sessionId) {
                throw new LocalizedException(
                    __('Straumur session not initialized for this order. Please create a new session and try again.')
                );
            }
        } else {
            $this->logger->info('[FLOW_TRACKER] InitializeCommand delegating to authorize command');
            $this->authorizeCommand->execute($commandSubject);
        }

        $stateObject->setState(Order::STATE_NEW);
        $stateObject->setStatus('pending');
        $stateObject->setIsNotified(false);

        $payment->setAdditionalInformation('straumur_payment_authorized', false);
        $payment->setAdditionalInformation('straumur_customer_returned', false);

        $this->logger->info('[FLOW_TRACKER] InitializeCommand completed - order will stay in NEW/pending state');
    }
}
