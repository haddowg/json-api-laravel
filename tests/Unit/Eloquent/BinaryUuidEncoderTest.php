<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\Eloquent;

use haddowg\JsonApiLaravel\Eloquent\BinaryUuidEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BinaryUuidEncoder::class)]
final class BinaryUuidEncoderTest extends TestCase
{
    private const string UUID = '0b6a1c2e-6a8f-4d55-9a6e-1f2d3c4b5a61';

    #[Test]
    public function itRoundTripsTheBytesThroughTheCanonicalForm(): void
    {
        $encoder = new BinaryUuidEncoder();
        $bytes = (string) \hex2bin('0b6a1c2e6a8f4d559a6e1f2d3c4b5a61');

        self::assertSame(self::UUID, $encoder->encode($bytes));
        self::assertSame($bytes, $encoder->decode(self::UUID));
        self::assertSame($bytes, $encoder->decode(\strtoupper(self::UUID)));
    }

    #[Test]
    public function aKeyAlreadyInCanonicalFormEncodesLowercase(): void
    {
        self::assertSame(self::UUID, (new BinaryUuidEncoder())->encode(\strtoupper(self::UUID)));
    }

    #[Test]
    public function aWireIdThatIsNotAUuidDoesNotDecode(): void
    {
        $encoder = new BinaryUuidEncoder();

        self::assertNull($encoder->decode('42'));
        self::assertNull($encoder->decode('0b6a1c2e6a8f4d559a6e1f2d3c4b5a61'));
        self::assertNull($encoder->decode(self::UUID . 'x'));
    }
}
