<?php
declare(strict_types=1);

namespace Straumur\Payment\Test\Unit\Model\Security;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Straumur\Payment\Gateway\Config\Config;
use Straumur\Payment\Model\Security\HmacValidator;

/**
 * Expected signatures were computed outside PHP with the backend's algorithm
 * (HmacHelpers.GetHmacSignature: values joined with ":", null as "", HMAC-SHA256 with the hex key, base64).
 */
class HmacValidatorTest extends TestCase
{
    public const SECRET = '4a6f686e446f65536563726574313233';

    /** Signature of "c1a2b3:PF-1:000000123:12990:ISK::true" with SECRET. */
    public const ORDER_SIGNATURE = '7LQd1IO8szTYSNwmafyQ3IuTL06o82oQEGCkNM1kc0c=';

    /** Signature of ":PF-2::5000:ISK::true" with SECRET: no merchant or checkout reference. */
    public const TERMINAL_SIGNATURE = 'cbGxg+GYkyhLWHTSaYe2A7TgYsLWgrIbQmW9c3z0Tn4=';

    /** Signature of the order message with the odd-length key "abc", which the backend pads to "abc0". */
    private const ODD_KEY_SIGNATURE = 'jHfNw+dmCenZrIzf1NI0scyi4yZarGJtbVJmdJBtfIs=';

    /**
     * @var HmacValidator
     */
    private $validator;

    protected function setUp(): void
    {
        $this->validator = self::createValidator();
    }

    /**
     * HmacValidator with the module's real HMAC field configuration.
     *
     * @return HmacValidator
     */
    public static function createValidator(): HmacValidator
    {
        $config = new Config(
            self::createStub(ScopeConfigInterface::class),
            self::createStub(EncryptorInterface::class)
        );

        return new HmacValidator($config, new NullLogger());
    }

    /**
     * A payment for one of the shop's orders.
     *
     * @return array
     */
    public static function orderPayload(): array
    {
        return [
            'checkoutReference' => 'c1a2b3',
            'payfacReference' => 'PF-1',
            'merchantReference' => '000000123',
            'amount' => '12990',
            'currency' => 'ISK',
            'reason' => null,
            'success' => 'true',
            'hmacSignature' => self::ORDER_SIGNATURE,
            'additionalData' => ['eventType' => 'Authorization'],
        ];
    }

    /**
     * A card-present payment on the same contract: references are null.
     *
     * @return array
     */
    public static function terminalPayload(): array
    {
        return [
            'checkoutReference' => null,
            'payfacReference' => 'PF-2',
            'merchantReference' => null,
            'amount' => '5000',
            'currency' => 'ISK',
            'reason' => null,
            'success' => 'true',
            'hmacSignature' => self::TERMINAL_SIGNATURE,
            'additionalData' => ['eventType' => 'Authorization'],
        ];
    }

    public function testMatchesBackendSignature(): void
    {
        $payload = self::orderPayload();

        $this->assertSame(self::ORDER_SIGNATURE, $this->validator->calculateSignature($payload, self::SECRET));
        $this->assertTrue($this->validator->validateSignature($payload, self::ORDER_SIGNATURE, self::SECRET));
    }

    public function testNullReferencesAreSignedAsEmptyStrings(): void
    {
        $this->assertSame(':PF-2::5000:ISK::true', $this->validator->buildMessageString(self::terminalPayload()));
        $this->assertTrue(
            $this->validator->validateSignature(self::terminalPayload(), self::TERMINAL_SIGNATURE, self::SECRET)
        );
    }

    public function testMissingReferencesAreSignedLikeNullOnes(): void
    {
        $payload = self::terminalPayload();
        unset($payload['checkoutReference'], $payload['merchantReference'], $payload['reason']);

        $this->assertTrue($this->validator->validateSignature($payload, self::TERMINAL_SIGNATURE, self::SECRET));
    }

    public function testPascalCaseKeysAreSignedLikeCamelCase(): void
    {
        $pascalCase = [];
        foreach (self::orderPayload() as $key => $value) {
            $pascalCase[ucfirst($key)] = $value;
        }

        $this->assertTrue($this->validator->validateSignature($pascalCase, self::ORDER_SIGNATURE, self::SECRET));
    }

    public function testOddLengthKeyIsPaddedLikeTheBackend(): void
    {
        $this->assertSame(self::ODD_KEY_SIGNATURE, $this->validator->calculateSignature(self::orderPayload(), 'abc'));
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $payload = self::orderPayload();
        $payload['amount'] = '1';

        $this->assertFalse($this->validator->validateSignature($payload, self::ORDER_SIGNATURE, self::SECRET));
    }

    public function testWrongSecretIsRejected(): void
    {
        $this->assertFalse(
            $this->validator->validateSignature(self::orderPayload(), self::ORDER_SIGNATURE, 'ffffffffffffffff')
        );
    }

    public function testNonHexSecretRejectsEverySignature(): void
    {
        $this->assertSame('', $this->validator->calculateSignature(self::orderPayload(), 'not-hex'));
        $this->assertFalse($this->validator->validateSignature(self::orderPayload(), self::ORDER_SIGNATURE, 'not-hex'));
    }

    public function testEmptySignatureOrSecretIsRejected(): void
    {
        $this->assertFalse($this->validator->validateSignature(self::orderPayload(), '', self::SECRET));
        $this->assertFalse($this->validator->validateSignature(self::orderPayload(), self::ORDER_SIGNATURE, ''));
    }
}
