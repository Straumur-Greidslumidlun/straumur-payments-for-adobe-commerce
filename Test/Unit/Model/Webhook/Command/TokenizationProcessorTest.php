<?php
declare(strict_types=1);

namespace Straumur\Payment\Test\Unit\Model\Webhook\Command;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Vault\Api\Data\PaymentTokenFactoryInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Straumur\Payment\Gateway\Config\Config;
use Straumur\Payment\Model\Webhook\Command\TokenizationProcessor;

class TokenizationProcessorTest extends ProcessorTestCase
{
    /**
     * @var PaymentTokenFactoryInterface
     */
    private $tokenFactory;

    /**
     * @var PaymentTokenRepositoryInterface
     */
    private $tokenRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenFactory = self::createStub(PaymentTokenFactoryInterface::class);
        $this->tokenRepository = $this->createMock(PaymentTokenRepositoryInterface::class);
    }

    /**
     * @return TokenizationProcessor
     */
    private function processor(): TokenizationProcessor
    {
        return new TokenizationProcessor(
            self::createStub(Config::class),
            $this->orderRepository,
            $this->logger,
            $this->tokenFactory,
            $this->tokenRepository
        );
    }

    /**
     * @param int|null $customerId null for a guest order
     * @return Order
     */
    private function order(?int $customerId): Order
    {
        $order = self::createStub(Order::class);
        $order->method('getCustomerId')->willReturn($customerId);
        $order->method('getPayment')->willReturn(self::createStub(Payment::class));

        return $order;
    }

    public function testApprovedTokenizationSavesTheCardForTheCustomer(): void
    {
        $token = $this->createMock(PaymentTokenInterface::class);
        $token->expects($this->once())->method('setCustomerId')->with(42);
        $token->expects($this->once())->method('setGatewayToken')->with('PF-OP');
        $token->expects($this->once())->method('setPaymentMethodCode')->with('straumur_payment');
        $this->tokenFactory = $this->createMock(PaymentTokenFactoryInterface::class);
        $this->tokenFactory->expects($this->once())->method('create')->with('card')->willReturn($token);
        $this->tokenRepository->expects($this->once())->method('save')->with($token);
        $order = $this->order(42);
        $this->expectOrderSaved($order);

        $result = $this->processor()->process(self::payload('Tokenization'), $order);

        $this->assertSame('success', $result['status']);
    }

    public function testGuestOrderGetsNoToken(): void
    {
        $this->tokenRepository->expects($this->never())->method('save');

        $this->processor()->process(self::payload('Tokenization'), $this->order(null));
    }

    public function testDeclinedTokenizationSavesNoToken(): void
    {
        $this->tokenRepository->expects($this->never())->method('save');

        $result = $this->processor()->process(self::payload('Tokenization', 'false'), $this->order(42));

        $this->assertSame('failed', $result['status']);
    }

    public function testUnsignedPascalCaseSuccessSavesNoToken(): void
    {
        $this->tokenRepository->expects($this->never())->method('save');

        $this->processor()->process(
            self::withUnsignedSuccess(self::payload('Tokenization', 'false')),
            $this->order(42)
        );
    }
}
