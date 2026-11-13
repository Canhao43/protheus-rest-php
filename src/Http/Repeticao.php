<?php

namespace Canhao43\ProtheusRest\Http;

use Canhao43\ProtheusRest\Erros\ErroDaApi;
use Canhao43\ProtheusRest\Erros\ErroDeConexao;
use Canhao43\ProtheusRest\Erros\ErroDoProtheus;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Quando e quanto esperar antes de repetir uma chamada que falhou de um jeito passageiro.
 * Regras em docs/decisoes/0008.
 */
final class Repeticao
{
    /**
     * 429 (RFC 6585) e os 5xx de "tente de novo": gateway, serviço indisponível e tempo
     * esgotado no gateway. O 500 fica de fora: é erro do servidor, e repetir dá o mesmo erro.
     */
    public const STATUS_PASSAGEIROS = [429, 502, 503, 504];

    private readonly Closure $dormir;

    /**
     * @param  int  $tentativas  total de tentativas, contando a primeira; 1 desliga a repetição
     * @param  (Closure(int): void)|null  $dormir  recebe a espera em milissegundos; em teste, um registro
     */
    public function __construct(
        public readonly int $tentativas = 3,
        public readonly int $esperaInicialMs = 500,
        public readonly int $esperaMaximaMs = 5000,
        ?Closure $dormir = null,
    ) {
        if ($tentativas < 1 || $esperaInicialMs < 0 || $esperaMaximaMs < $esperaInicialMs) {
            throw new InvalidArgumentException('Repetição precisa de ao menos 1 tentativa e de espera máxima maior ou igual à inicial.');
        }

        $this->dormir = $dormir ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    public static function desligada(): self
    {
        return new self(tentativas: 1);
    }

    /**
     * Milissegundos a esperar antes da próxima tentativa, ou null para desistir.
     *
     * @param  int  $tentativa  a tentativa que acabou de falhar, começando em 1
     */
    public function esperaDepoisDe(string $metodo, ErroDoProtheus $erro, int $tentativa, DateTimeImmutable $agora): ?int
    {
        // Só leitura: um POST que deu tempo esgotado pode ter sido gravado, e repetir duplicaria.
        if ($metodo !== 'GET' || $tentativa >= $this->tentativas) {
            return null;
        }

        if ($erro instanceof ErroDaApi) {
            if (! in_array($erro->status, self::STATUS_PASSAGEIROS, true)) {
                return null;
            }

            $pedido = self::retryAfterEmMs($erro->retryAfter, $agora);
            if ($pedido !== null) {
                // O servidor disse quanto esperar. Se for mais do que quem chama aceita, desistir
                // agora é melhor que repetir antes da hora ou prender o processo.
                return $pedido <= $this->esperaMaximaMs ? $pedido : null;
            }
        } elseif (! $erro instanceof ErroDeConexao) {
            return null;
        }

        // Exponencial, com variação: vários clientes que falharam juntos não voltam juntos.
        $teto = (int) min($this->esperaMaximaMs, $this->esperaInicialMs * 2 ** ($tentativa - 1));

        return random_int(intdiv($teto, 2), $teto);
    }

    public function dormir(int $ms): void
    {
        ($this->dormir)($ms);
    }

    /**
     * Retry-After em segundos ou em data HTTP (RFC 9110, seção 10.2.3). Qualquer outra coisa
     * é ignorada, e vale a espera exponencial.
     */
    private static function retryAfterEmMs(?string $valor, DateTimeImmutable $agora): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (ctype_digit($valor)) {
            return (int) $valor * 1000;
        }

        $data = DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $valor, new \DateTimeZone('UTC'));
        if ($data === false) {
            return null;
        }

        return max(0, ($data->getTimestamp() - $agora->getTimestamp()) * 1000);
    }
}
