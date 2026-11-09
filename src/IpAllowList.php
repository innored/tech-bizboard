<?php
/**
 * 접속 IP 허용 목록. 단일 IP와 CIDR 대역(IPv4·IPv6)을 받는다.
 * 목록이 비면 아무도 허용하지 않는다(설정 누락으로 페이지가 열리지 않게).
 *
 * @date 2026-11-09
 * @link https://lucy.conbus.co.kr/tech-bizboard/src/IpAllowList.php
 */

declare(strict_types=1);

final class IpAllowList
{
    /** @param list<string> $rules */
    private function __construct(private array $rules)
    {
    }

    /** "1.2.3.4, 10.0.0.0/8" 처럼 쉼표로 구분한 문자열 */
    public static function fromString(string $raw): self
    {
        $rules = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $r): bool => $r !== ''));

        return new self($rules);
    }

    /** @return list<string> */
    public function rules(): array
    {
        return $this->rules;
    }

    public function allows(string $ip): bool
    {
        $addr = self::packed($ip);
        if ($addr === null) {
            return false;
        }
        foreach ($this->rules as $rule) {
            if (self::matches($addr, $rule)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $addr, string $rule): bool
    {
        $parts = explode('/', $rule, 2);
        $net = self::packed($parts[0]);
        if ($net === null || strlen($net) !== strlen($addr)) {
            return false;
        }
        $maxBits = strlen($net) * 8;
        if (!isset($parts[1])) {
            return $net === $addr;
        }
        if (preg_match('/^\d{1,3}$/', $parts[1]) !== 1 || (int) $parts[1] > $maxBits) {
            return false;
        }
        $bits = (int) $parts[1];
        $fullBytes = intdiv($bits, 8);
        if (substr($net, 0, $fullBytes) !== substr($addr, 0, $fullBytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($net[$fullBytes]) & $mask) === (ord($addr[$fullBytes]) & $mask);
    }

    /** 이진 주소. IPv4-mapped IPv6(::ffff:a.b.c.d)는 IPv4로 바꾼다. 형식이 틀리면 null */
    private static function packed(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $bin = inet_pton($ip);
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return substr($bin, 12);
        }

        return $bin;
    }
}
