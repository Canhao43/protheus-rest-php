<?php

namespace Canhao43\ProtheusRest\Autenticacao;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Relógio padrão. Quem já tem um relógio PSR-20 (Symfony Clock, Carbon) pode passar o dele.
 */
final class RelogioDoSistema implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable;
    }
}
