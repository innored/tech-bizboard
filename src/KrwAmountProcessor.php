<?php
/**
 * 외화 × (환율 / 단위) → 원화 반올림
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/src/KrwAmountProcessor.php
 */

declare(strict_types=1);

class KrwAmountProcessor
{
    public function toKrw(float $amountForeign, float $rate, int $unit): int
    {
        $effective = $rate / max($unit, 1);

        return (int) round($amountForeign * $effective);
    }
}
