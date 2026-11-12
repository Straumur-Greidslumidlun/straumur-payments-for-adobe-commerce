<?php
declare(strict_types=1);

namespace Straumur\Payment\Test\Unit\Model\Service;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Straumur\Payment\Api\StraumurApiInterface;
use Straumur\Payment\Helper\Data as StraumurHelper;
use Straumur\Payment\Model\Service\EmbeddedPaymentReconciler;
use Straumur\Payment\Model\Service\PaymentStateManager;
use Straumur\Payment\Model\Webhook\Command\AuthorizationProcessor;
use Straumur\Payment\Test\Unit\RecordingLogger;

class EmbeddedPaymentReconcilerTest extends TestCase
{
    /**
     * @var bool
     */
    private $headless = true;

    /**
     * @var array Payment additional information of the placed order
     */
    private $paymentInformation = [];

    /**
     * @var string
     */
    private $method = 'straumur_payment';

    /**
     * @var StraumurApiInterface
     */
    private $api;

    /**
     * @var AuthorizationProcessor
     */
    private $processor;

    /**
     * @var RecordingLogger
     */
    private $logger;

    protected function setUp(): void
    {
        $this->paymentInformation = [
            'straumur_checkout_reference' => 'chk-1',
            'straumur_session_amount' => '12990',
            'straumur_session_currency' => 'ISK',
            PaymentStateManager::FLAG_PAYMENT_AUTHORIZED => false,
        ];
        $this->api = self::createStub(StraumurApiInterface::class);
        $this->processor = self::createStub(AuthorizationProcessor::class);
        $this->logger = new RecordingLogger();
    }

    /**
     * @return bool
     */
    private function reconcile(): bool
    {
        $helper = self::createStub(StraumurHelper::class);
        $helper->method('useQuoteSession')->willReturn($this->headless);

        $payment = self::createStub(Payment::class);
        $payment->method('getMethod')->willReturn($this->method);
        $payment->method('getAdditionalInformation')->willReturnCallback(function ($key = null) {
            return $key === null ? $this->paymentInformation : ($this->paymentInformation[$key] ?? null);
        });

        $order = self::createStub(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getStoreId')->willReturn(1);

        return (new EmbeddedPaymentReconciler($helper, $this->api, $this->processor, $this->logger))
            ->reconcile($order);
    }

    public function testPaymentCompletedBeforePlacementIsAppliedAsAnAuthorization(): void
    {
        $this->api = $this->createMock(StraumurApiInterface::class);
        $this->api->expects($this->once())->method('getEmbeddedStatus')->with('chk-1', 1)
            ->willReturn(['status' => 'Completed', 'payfacReference' => 'PF-1']);
        $this->processor = $this->createMock(AuthorizationProcessor::class);
        $this->processor->expects($this->once())->method('process')->with(
            [
                'merchantReference' => '000000123',
                'checkoutReference' => 'chk-1',
                'payfacReference' => 'PF-1',
                'amount' => '12990',
                'currency' => 'ISK',
                'success' => 'true',
                'additionalData' => ['eventType' => 'Authorization'],
            ],
            $this->isInstanceOf(Order::class)
        );

        $this->assertTrue($this->reconcile());
        $this->assertCount(1, $this->logger->messages('info'));
    }

    public function testSessionNotPaidYetLeavesTheOrderForTheWebhook(): void
    {
        $this->api->method('getEmbeddedStatus')->willReturn(['status' => 'New', 'payfacReference' => null]);
        $this->processor = $this->createMock(AuthorizationProcessor::class);
        $this->processor->expects($this->never())->method('process');

        $this->assertFalse($this->reconcile());
    }

    /**
     * @return array
     */
    public static function ordersThatAreNotChecked(): array
    {
        return [
            'hosted checkout' => ['headless' => false, 'method' => 'straumur_payment', 'set' => []],
            'other payment method' => ['headless' => true, 'method' => 'checkmo', 'set' => []],
            'already authorized' => [
                'headless' => true,
                'method' => 'straumur_payment',
                'set' => [PaymentStateManager::FLAG_PAYMENT_AUTHORIZED => true],
            ],
            'no session' => [
                'headless' => true,
                'method' => 'straumur_payment',
                'set' => ['straumur_checkout_reference' => null],
            ],
        ];
    }

    /**
     * @param bool $headless
     * @param string $method
     * @param array $set
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ordersThatAreNotChecked')]
    public function testStatusIsOnlyAskedForUnpaidHeadlessStraumurOrders(
        bool $headless,
        string $method,
        array $set
    ): void {
        $this->headless = $headless;
        $this->method = $method;
        $this->paymentInformation = array_merge($this->paymentInformation, $set);
        $this->api = $this->createMock(StraumurApiInterface::class);
        $this->api->expects($this->never())->method('getEmbeddedStatus');

        $this->assertFalse($this->reconcile());
    }

    public function testStatusErrorDoesNotFailOrderPlacement(): void
    {
        $this->api->method('getEmbeddedStatus')->willThrowException(new \RuntimeException('timeout'));

        $this->assertFalse($this->reconcile());
        $this->assertCount(1, $this->logger->messages('warning'));
    }

    public function testProcessingErrorDoesNotFailOrderPlacement(): void
    {
        $this->api->method('getEmbeddedStatus')->willReturn(['status' => 'Completed', 'payfacReference' => 'PF-1']);
        $this->processor->method('process')->willThrowException(new \InvalidArgumentException('cannot authorize'));

        $this->assertFalse($this->reconcile());
        $this->assertCount(1, $this->logger->messages('error'));
    }
}
