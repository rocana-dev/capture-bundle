<?php

namespace QaTracking\CaptureBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QaTracking\CaptureBundle\Collector\ValueNormalizer;
use QaTracking\CaptureBundle\Masking\FieldMasker;
use QaTracking\CaptureBundle\Tests\Fixtures\Entity\Customer;
use QaTracking\CaptureBundle\Tests\Fixtures\Entity\Invoice;

final class FieldMaskerTest extends TestCase
{
    /**
     * @param array<string, list<string>> $masked
     * @param array<string, list<string>> $only
     * @param list<string>                $excluded
     */
    private function masker(array $masked = [], array $only = [], array $excluded = []): FieldMasker
    {
        return new FieldMasker(new ValueNormalizer(['password', 'token', 'secret']), $masked, $only, $excluded);
    }

    public function testFieldsThatLookLikeCredentialsAreAlwaysMasked(): void
    {
        $row = $this->masker()->mask(Customer::class, ['id' => 4, 'name' => 'Ana', 'api_token' => 'abc', 'password_hash' => 'xyz']);

        self::assertSame(['id' => 4, 'name' => 'Ana', 'api_token' => '***', 'password_hash' => '***'], $row);
    }

    public function testEntityRulesMatchTheFieldOrTheColumnName(): void
    {
        $masker = $this->masker([Customer::class => ['taxId', 'email']]);
        $fieldOf = static fn (string $column) => ['tax_id' => 'taxId'][$column] ?? null;

        $row = $masker->mask(Customer::class, ['tax_id' => '10407654321', 'EMAIL' => 'ana@example.com', 'name' => 'Ana'], $fieldOf);

        self::assertSame(['tax_id' => '***', 'EMAIL' => '***', 'name' => 'Ana'], $row);
        self::assertFalse($masker->isMasked(Invoice::class, 'taxId'), 'The rule belongs to Customer, not to other entities.');
    }

    public function testAnAllowListHidesEverythingElse(): void
    {
        $masker = $this->masker(only: [Customer::class => ['id', 'name']]);

        self::assertSame(
            ['id' => 4, 'name' => 'Ana', 'tax_id' => '***', 'created_at' => '***'],
            $masker->mask(Customer::class, ['id' => 4, 'name' => 'Ana', 'tax_id' => '10407654321', 'created_at' => '2026-01-01']),
        );
    }

    public function testRulesApplyToSubclasses(): void
    {
        $child = new class('x', 'y') extends Customer {};
        $masker = $this->masker([Customer::class => ['taxId']], excluded: [Invoice::class]);

        self::assertTrue($masker->isMasked($child::class, 'taxId'));
        self::assertFalse($masker->isExcluded($child::class));
        self::assertTrue($masker->isExcluded(Invoice::class));
    }

    public function testValuesAreMadeSafeForJson(): void
    {
        $row = $this->masker()->mask(Customer::class, [
            'notes' => str_repeat('a', 3000),
            'photo' => "\xff\xd8\xff\xe0 binary",
            'created_at' => new \DateTimeImmutable('2026-11-14T10:00:00+00:00'),
        ]);

        self::assertStringContainsString('truncated, 3000 characters', $row['notes']);
        self::assertSame('[binary, 11 bytes]', $row['photo']);
        self::assertSame('2026-11-14T10:00:00+00:00', $row['created_at']);
    }
}
