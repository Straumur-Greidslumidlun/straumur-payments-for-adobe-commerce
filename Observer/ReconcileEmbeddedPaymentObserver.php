<?php
declare(strict_types=1);

namespace Straumur\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Straumur\Payment\Model\Service\EmbeddedPaymentReconciler;

/**
 * After an order is placed, applies a headless Straumur payment that was completed before it.
 */
class ReconcileEmbeddedPaymentObserver implements ObserverInterface
{
    /**
     * @var EmbeddedPaymentReconciler
     */
    private $reconciler;

    /**
     * @param EmbeddedPaymentReconciler $reconciler
     */
    public function __construct(EmbeddedPaymentReconciler $reconciler)
    {
        $this->reconciler = $reconciler;
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getData('order');

        if ($order instanceof OrderInterface) {
            $this->reconciler->reconcile($order);
        }
    }
}
