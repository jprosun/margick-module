<?php
/**
 * Money — immutable value object (PURE DOMAIN, no WordPress).
 * =========================================================
 * Stores an integer amount in minor units + currency, with a per-currency
 * scale (decimal places). Integer math removes float drift — the epsilon
 * hacks in the old float-based mgk_quote() disappear.
 *
 * Fixes the latent bug from the edu review: VND (0 decimals) and SGD/USD
 * (2 decimals) are both handled correctly by scaleFor().
 */

declare(strict_types=1);

namespace Margick\Commerce\Domain;

final class Money
{
    /** Currencies whose minor unit == major unit (no decimals). Default = 2. */
    private const ZERO_DECIMAL = ['VND' => 0, 'JPY' => 0, 'KRW' => 0];

    private function __construct(
        private readonly int $minor,
        private readonly string $currency,
        private readonly int $scale
    ) {}

    public static function scaleFor(string $currency): int
    {
        return self::ZERO_DECIMAL[strtoupper($currency)] ?? 2;
    }

    public static function ofMinor(int $minor, string $currency): self
    {
        $c = strtoupper($currency);
        return new self($minor, $c, self::scaleFor($c));
    }

    public static function ofMajor(float $major, string $currency): self
    {
        $c     = strtoupper($currency);
        $scale = self::scaleFor($c);
        return new self((int) round($major * (10 ** $scale)), $c, $scale);
    }

    public static function zero(string $currency): self
    {
        return self::ofMinor(0, $currency);
    }

    public function minor(): int    { return $this->minor; }
    public function currency(): string { return $this->currency; }
    public function scale(): int    { return $this->scale; }
    public function toMajor(): float { return $this->minor / (10 ** $this->scale); }
    public function isZero(): bool  { return $this->minor === 0; }

    public function add(Money $o): self
    {
        $this->assertSame($o);
        return new self($this->minor + $o->minor, $this->currency, $this->scale);
    }

    /** Subtraction clamps at zero (matches the running-total floor in mgk_quote). */
    public function sub(Money $o): self
    {
        $this->assertSame($o);
        return new self(max(0, $this->minor - $o->minor), $this->currency, $this->scale);
    }

    public function percentage(float $pct): self
    {
        return new self((int) round($this->minor * $pct / 100), $this->currency, $this->scale);
    }

    public function greaterThan(Money $o): bool
    {
        $this->assertSame($o);
        return $this->minor > $o->minor;
    }

    public function equals(Money $o): bool
    {
        return $this->currency === $o->currency && $this->minor === $o->minor;
    }

    /** Display string, e.g. "40.00 SGD" / "300,000 VND". */
    public function format(): string
    {
        return number_format($this->toMajor(), $this->scale) . ' ' . $this->currency;
    }

    private function assertSame(Money $o): void
    {
        if ($o->currency !== $this->currency) {
            throw new \InvalidArgumentException("Currency mismatch: {$this->currency} vs {$o->currency}");
        }
    }
}
