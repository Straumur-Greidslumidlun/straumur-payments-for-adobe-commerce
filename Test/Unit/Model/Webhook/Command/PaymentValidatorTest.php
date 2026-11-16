<?php
declare(strict_types=1);

namespace Straumur\Payment\Test\Unit\Model\Webhook\Command;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Straumur\Payment\Helper\Data as StraumurHelper;
use Straumur\Payment\Model\Webhook\Command\AbstractValidator;
use Straumur\Payment\Model\Webhook\Command\PaymentValidator;
use Straumur\Payment\Test\Unit\Model\Security\HmacValidatorTest;

class PaymentValidatorTest extends TestCase
{
    /**
     * Validator with the real HMAC implementation and the given configured secret.
     *
     * @param string $secret
     * @return PaymentValidator
     */
    private function validator(string $secret): PaymentValidator
    {
        $helper = self::createStub(StraumurHelper::class);
        $helper->method('getWebhookSecret')->willReturn($secret);

        return new PaymentValidator(HmacValidatorTest::createValidator(), $helper, new NullLogger());
    }

    public function testSignedOrderPaymentIsValid(): void
    {
        $validator = $this->validator(HmacValidatorTest::SECRET);

        $this->assertTrue($validator->validate(HmacValidatorTest::orderPayload(), []));
        $this->assertSame('', $validator->getErrorMessage());
    }

    public function testSignedPaymentWithoutReferencesIsValid(): void
    {
        // Terminal payments and payment links carry no merchant or checkout reference. They used to fail a
        // required-field check before the signature was even looked at.
        $validator = $this->validator(HmacValidatorTest::SECRET);

        $this->assertTrue($validator->validate(HmacValidatorTest::terminalPayload(), []));
    }

    public function testPascalCaseSignatureFieldIsRead(): void
    {
        $payload = HmacValidatorTest::orderPayload();
        $payload['HmacSignature'] = $payload['hmacSignature'];
        unset($payload['hmacSignature']);

        $this->assertTrue($this->validator(HmacValidatorTest::SECRET)->validate($payload, []));
    }

    public function testMissingSecretFails(): void
    {
        $validator = $this->validator('');

        $this->assertFalse($validator->validate(HmacValidatorTest::orderPayload(), []));
        $this->assertSame(AbstractValidator::ERROR_SECRET_NOT_CONFIGURED, $validator->getErrorMessage());
    }

    public function testUnsignedPayloadFails(): void
    {
        $payload = HmacValidatorTest::orderPayload();
        unset($payload['hmacSignature']);
        $validator = $this->validator(HmacValidatorTest::SECRET);

        $this->assertFalse($validator->validate($payload, []));
        $this->assertSame(AbstractValidator::ERROR_MISSING_SIGNATURE, $validator->getErrorMessage());
    }

    public function testWrongSignatureFails(): void
    {
        $payload = HmacValidatorTest::orderPayload();
        $payload['hmacSignature'] = HmacValidatorTest::TERMINAL_SIGNATURE;
        $validator = $this->validator(HmacValidatorTest::SECRET);

        $this->assertFalse($validator->validate($payload, []));
        $this->assertSame(AbstractValidator::ERROR_INVALID_SIGNATURE, $validator->getErrorMessage());
    }

    public function testErrorFromPreviousCallIsCleared(): void
    {
        $validator = $this->validator(HmacValidatorTest::SECRET);
        $unsigned = HmacValidatorTest::orderPayload();
        unset($unsigned['hmacSignature']);

        $validator->validate($unsigned, []);
        $this->assertTrue($validator->validate(HmacValidatorTest::orderPayload(), []));
        $this->assertSame('', $validator->getErrorMessage());
    }
}
