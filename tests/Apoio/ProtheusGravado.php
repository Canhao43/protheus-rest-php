<?php

namespace Tests\Apoio;

use Canhao43\ProtheusRest\ClienteProtheus;
use Canhao43\ProtheusRest\Credenciais;
use Canhao43\ProtheusRest\Http\Repeticao;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Um Protheus de mentira: devolve, em ordem, as respostas que o teste enfileirou e guarda
 * cada requisição que recebeu. Se a fila acabar, o MockHandler falha: nada vai para a rede.
 */
final class ProtheusGravado
{
    public const URL_BASE = 'https://erp.exemplo.invalid:8080/rest';

    public const USUARIO = 'usuario.teste';

    public const SENHA = 'senha-ficticia';

    public readonly RelogioParado $relogio;

    /** @var list<int> milissegundos de cada espera, na ordem */
    public array $esperas = [];

    private readonly MockHandler $fila;

    /** @var list<array{request: RequestInterface}> */
    private array $historico = [];

    public function __construct()
    {
        $this->fila = new MockHandler;
        $this->relogio = new RelogioParado(new DateTimeImmutable('2026-01-05 09:00:00'));
    }

    /**
     * @param  array<string, string>  $cabecalhos
     */
    public function responde(int $status, string $corpo = '', array $cabecalhos = []): self
    {
        $this->fila->append(new Response($status, $cabecalhos, $corpo));

        return $this;
    }

    /**
     * @param  array<mixed>  $dados
     */
    public function respondeJson(int $status, array $dados): self
    {
        return $this->responde($status, (string) json_encode($dados), ['Content-Type' => 'application/json']);
    }

    public function respondeArquivo(int $status, string $arquivo): self
    {
        return $this->responde($status, (string) file_get_contents(__DIR__.'/../respostas/'.$arquivo), ['Content-Type' => 'application/json']);
    }

    public function falha(Throwable $erro): self
    {
        $this->fila->append($erro);

        return $this;
    }

    /**
     * Sem repetição por padrão: os testes de outros assuntos não devem gastar respostas da
     * fila com novas tentativas. Quem testa repetição passa a sua, de repeticao().
     */
    public function cliente(
        string $urlBase = self::URL_BASE,
        ?Repeticao $repeticao = null,
        ?CacheInterface $cache = null,
        string $usuario = self::USUARIO,
    ): ClienteProtheus {
        $pilha = HandlerStack::create($this->fila);
        $pilha->push(Middleware::history($this->historico));

        return new ClienteProtheus(
            $urlBase,
            new Credenciais($usuario, self::SENHA),
            new Client(['handler' => $pilha]),
            $this->relogio,
            $cache,
            $repeticao ?? Repeticao::desligada(),
        );
    }

    /**
     * Uma repetição que não dorme de verdade: anota cada espera em $esperas.
     */
    public function repeticao(int $tentativas = 3, int $esperaInicialMs = 500, int $esperaMaximaMs = 5000): Repeticao
    {
        return new Repeticao($tentativas, $esperaInicialMs, $esperaMaximaMs, function (int $ms): void {
            $this->esperas[] = $ms;
        });
    }

    /**
     * @return list<RequestInterface>
     */
    public function requisicoes(): array
    {
        return array_map(fn (array $item) => $item['request'], $this->historico);
    }

    public function requisicao(int $indice): RequestInterface
    {
        return $this->requisicoes()[$indice];
    }

    /**
     * Método e URL de cada requisição, na ordem: o roteiro do que o cliente fez.
     *
     * @return list<string>
     */
    public function roteiro(): array
    {
        return array_map(fn (RequestInterface $r) => $r->getMethod().' '.$r->getUri(), $this->requisicoes());
    }

    public function respostasNaoUsadas(): int
    {
        return $this->fila->count();
    }
}
