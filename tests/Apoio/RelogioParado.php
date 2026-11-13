<?php

namespace Tests\Apoio;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Relógio que só anda quando o teste manda: vencimento de token sem sleep.
 */
final class RelogioParado implements ClockInterface
{
    public function __construct(private DateTimeImmutable $agora) {}

    public function now(): DateTimeImmutable
    {
        return $this->agora;
    }

    public function avancar(int $segundos): void
    {
        $this->agora = $this->agora->modify("+{$segundos} seconds");
    }
}
