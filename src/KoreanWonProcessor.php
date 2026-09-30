<?php
/**
 * 원화 금액을 한글 표기로 바꾼다.
 *
 * @date 2026-09-08
 * @link https://lucy.conbus.co.kr/tech-bizboard/src/KoreanWonProcessor.php
 */

declare(strict_types=1);

class KoreanWonProcessor
{
    private const DIGITS = ['', '일', '이', '삼', '사', '오', '육', '칠', '팔', '구'];
    private const SMALL = ['', '십', '백', '천'];
    private const LARGE = ['', '만', '억', '조'];

    public function toKorean(int $amount): string
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('금액은 0 이상이어야 합니다.');
        }
        if ($amount === 0) {
            return '영원';
        }

        $parts = [];
        $large = 0;
        $n = $amount;
        while ($n > 0) {
            $chunk = $n % 10000;
            if ($chunk > 0) {
                // 만·억·조는 마지막 자리에 붙인다. 팔십 삼만
                $parts[] = $this->chunk($chunk) . self::LARGE[$large];
            }
            $n = intdiv($n, 10000);
            $large++;
        }

        return implode(' ', array_reverse($parts));
    }

    public function formal(int $amount): string
    {
        if ($amount === 0) {
            return '일금 영원정';
        }

        return '일금 ' . $this->toKorean($amount) . ' 원정';
    }

    /** 십·백·천의 1은 '일'을 생략한다. 일의 자리 1은 '일'이다. */
    private function chunk(int $n): string
    {
        $bits = [];
        for ($i = 3; $i >= 0; $i--) {
            $div = 10 ** $i;
            $d = intdiv($n, $div);
            $n %= $div;
            if ($d === 0) {
                continue;
            }
            if ($d === 1 && $i > 0) {
                $bits[] = self::SMALL[$i];
                continue;
            }
            $bits[] = self::DIGITS[$d] . self::SMALL[$i];
        }

        return implode(' ', $bits);
    }
}
