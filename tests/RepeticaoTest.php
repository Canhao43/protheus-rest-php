<?php

namespace Tests;

use Canhao43\ProtheusRest\Erros\ErroDaApi;
use Canhao43\ProtheusRest\Erros\ErroDeConexao;
use Canhao43\ProtheusRest\Http\Repeticao;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Apoio\ProtheusGravado;

/**
 * Nova tentativa em falha passageira: só leitura, só falha que passa, com espera crescente.
 */
class RepeticaoTest extends TestCase
{
    private const RECURSOS = 'GET '.ProtheusGravado::URL_BASE.'/api/exemplo/v1/recursos';

    private ProtheusGravado $protheus;

    protected function setUp(): void
    {
        $this->protheus = (new ProtheusGravado)->respondeArquivo(200, 'token.json');
    }

    private function semConexao(): ConnectException
    {
        $url = ProtheusGravado::URL_BASE.'/api/exemplo/v1/recursos';

        return new ConnectException("cURL error 7: Failed to connect for {$url}", new Request('GET', $url), null, ['errno' => 7, 'error' => 'Failed to connect']);
    }

    /**
     * @return list<string> o roteiro sem o login
     */
    private function chamadas(): array
    {
        return array_slice($this->protheus->roteiro(), 1);
    }

    public function test_get_com_503_espera_e_repete(): void
    {
        $this->protheus->responde(503)->respondeJson(200, ['id' => 1]);

        $resposta = $this->protheus->cliente(repeticao: $this->protheus->repeticao())->get('api/exemplo/v1/recursos');

        $this->assertSame(['id' => 1], $resposta);
        $this->assertSame([self::RECURSOS, self::RECURSOS], $this->chamadas());
        $this->assertCount(1, $this->protheus->esperas);
        $this->assertGreaterThanOrEqual(250, $this->protheus->esperas[0]);
        $this->assertLessThanOrEqual(500, $this->protheus->esperas[0]);
    }

    public function test_falha_de_conexao_repete_com_espera_que_dobra(): void
    {
        $this->protheus->falha($this->semConexao())->falha($this->semConexao())->respondeJson(200, []);

        $this->protheus->cliente(repeticao: $this->protheus->repeticao())->get('api/exemplo/v1/recursos');

        $this->assertCount(3, $this->chamadas());
        [$primeira, $segunda] = $this->protheus->esperas;
        // Teto de 500 ms e depois de 1000 ms; a variação sorteia entre a metade e o teto.
        $this->assertGreaterThanOrEqual(250, $primeira);
        $this->assertLessThanOrEqual(500, $primeira);
        $this->assertGreaterThanOrEqual(500, $segunda);
        $this->assertLessThanOrEqual(1000, $segunda);
    }

    public function test_desiste_no_limite_de_tentativas_com_o_ultimo_erro(): void
    {
        $this->protheus->responde(502)->responde(503)->responde(504);

        try {
            $this->protheus->cliente(repeticao: $this->protheus->repeticao(tentativas: 3))->get('api/exemplo/v1/recursos');
            $this->fail('Deveria ter lançado ErroDaApi');
        } catch (ErroDaApi $erro) {
            $this->assertSame(504, $erro->status);
        }

        $this->assertCount(3, $this->chamadas());
        $this->assertCount(2, $this->protheus->esperas);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function metodosQueEscrevem(): array
    {
        return ['POST' => ['post'], 'PUT' => ['put'], 'PATCH' => ['patch'], 'DELETE' => ['delete']];
    }

    #[DataProvider('metodosQueEscrevem')]
    public function test_chamada_que_escreve_nao_repete(string $metodo): void
    {
        $this->protheus->responde(503);

        try {
            $this->protheus->cliente(repeticao: $this->protheus->repeticao())->{$metodo}('api/exemplo/v1/recursos/1');
            $this->fail('Deveria ter lançado ErroDaApi');
        } catch (ErroDaApi $erro) {
            $this->assertSame(503, $erro->status);
        }

        $this->assertCount(1, $this->chamadas());
        $this->assertSame([], $this->protheus->esperas);
    }

    public function test_post_sem_conexao_nao_repete(): void
    {
        $this->protheus->falha($this->semConexao());

        $this->expectException(ErroDeConexao::class);

        try {
            $this->protheus->cliente(repeticao: $this->protheus->repeticao())->post('api/exemplo/v1/recursos', ['nome' => 'Exemplo']);
        } finally {
            $this->assertCount(1, $this->chamadas());
        }
    }

    public function test_login_com_503_nao_repete(): void
    {
        $protheus = (new ProtheusGravado)->responde(503);

        try {
            $protheus->cliente(repeticao: $protheus->repeticao())->get('api/exemplo/v1/recursos');
            $this->fail('Deveria ter lançado ErroDaApi');
        } catch (ErroDaApi $erro) {
            $this->assertSame(503, $erro->status);
        }

        $this->assertSame(['POST '.ProtheusGravado::URL_BASE.'/api/oauth2/v1/token?grant_type=password'], $protheus->roteiro());
        $this->assertSame([], $protheus->esperas);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function errosQueNaoPassam(): array
    {
        return ['500' => [500], '400' => [400], '404' => [404], '403' => [403]];
    }

    #[DataProvider('errosQueNaoPassam')]
    public function test_erro_que_nao_passa_sozinho_nao_repete(int $status): void
    {
        $this->protheus->responde($status);

        try {
            $this->protheus->cliente(repeticao: $this->protheus->repeticao())->get('api/exemplo/v1/recursos');
            $this->fail('Deveria ter lançado ErroDaApi');
        } catch (ErroDaApi $erro) {
            $this->assertSame($status, $erro->status);
        }

        $this->assertCount(1, $this->chamadas());
    }

    public function test_429_com_retry_after_em_segundos_espera_o_que_o_servidor_pediu(): void
    {
        $this->protheus->responde(429, '', ['Retry-After' => '2'])->respondeJson(200, []);

        $this->protheus->cliente(repeticao: $this->protheus->repeticao())->get('api/exemplo/v1/recursos');

        $this->assertSame([2000], $this->protheus->esperas);
    }

    public function test_retry_after_em_data_http_conta_a_partir_do_relogio(): void
    {
        // O relógio parado está em 2026-01-05 09:00:00; três segundos depois, em UTC.
        $data = $this->protheus->relogio->now()->setTimezone(new \DateTimeZone('UTC'))->modify('+3 seconds');
        $this->protheus->responde(503, '', ['Retry-After' => $data->format('D, d M Y H:i:s \G\M\T')])->respondeJson(200, []);

        $this->protheus->cliente(repeticao: $this->protheus->repeticao())->get('api/exemplo/v1/recursos');

        $this->assertSame([3000], $this->protheus->esperas);
    }

    public function test_retry_after_maior_que_a_espera_maxima_desiste_na_hora(): void
    {
        $this->protheus->responde(503, '', ['Retry-After' => '120']);

        try {
            $this->protheus->cliente(repeticao: $this->protheus->repeticao(esperaMaximaMs: 5000))->get('api/exemplo/v1/recursos');
            $this->fail('Deveria ter lançado ErroDaApi');
        } catch (ErroDaApi $erro) {
            $this->assertSame('120', $erro->retryAfter);
        }

        $this->assertCount(1, $this->chamadas());
        $this->assertSame([], $this->protheus->esperas);
    }

    public function test_retry_after_ilegivel_usa_a_espera_exponencial(): void
    {
        $this->protheus->responde(503, '', ['Retry-After' => 'logo mais'])->respondeJson(200, []);

        $this->protheus->cliente(repeticao: $this->protheus->repeticao())->get('api/exemplo/v1/recursos');

        $this->assertCount(1, $this->protheus->esperas);
        $this->assertLessThanOrEqual(500, $this->protheus->esperas[0]);
    }

    public function test_a_espera_nunca_passa_da_maxima(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->protheus->responde(503);
        }
        $this->protheus->respondeJson(200, []);

        $this->protheus->cliente(repeticao: $this->protheus->repeticao(tentativas: 6, esperaInicialMs: 1000, esperaMaximaMs: 3000))
            ->get('api/exemplo/v1/recursos');

        // Tetos: 1000, 2000, 3000, 3000, 3000 (o 4000 e o 8000 batem na máxima).
        $this->assertCount(5, $this->protheus->esperas);
        $this->assertGreaterThanOrEqual(1500, $this->protheus->esperas[3]);
        foreach ($this->protheus->esperas as $espera) {
            $this->assertLessThanOrEqual(3000, $espera);
        }
    }

    public function test_a_espera_nunca_e_menor_que_metade_do_teto(): void
    {
        // Vinte esperas com teto fixo de 1000 ms: sorteando de 0 em vez de 500, a chance de
        // todas caírem acima de 500 seria de uma em um milhão.
        for ($i = 0; $i < 20; $i++) {
            $this->protheus->responde(503);
        }
        $this->protheus->respondeJson(200, []);

        $this->protheus->cliente(repeticao: $this->protheus->repeticao(tentativas: 21, esperaInicialMs: 1000, esperaMaximaMs: 1000))
            ->get('api/exemplo/v1/recursos');

        $this->assertCount(20, $this->protheus->esperas);
        $this->assertGreaterThanOrEqual(500, min($this->protheus->esperas));
    }

    public function test_repeticao_desligada_falha_na_primeira(): void
    {
        $this->protheus->responde(503);

        $this->expectException(ErroDaApi::class);

        try {
            $this->protheus->cliente(repeticao: Repeticao::desligada())->get('api/exemplo/v1/recursos');
        } finally {
            $this->assertCount(1, $this->chamadas());
        }
    }

    public function test_401_depois_de_uma_repeticao_ainda_renova_o_token(): void
    {
        $this->protheus->responde(503)
            ->responde(401)
            ->respondeArquivo(200, 'token-renovado.json')
            ->respondeJson(200, ['id' => 1]);

        $resposta = $this->protheus->cliente(repeticao: $this->protheus->repeticao())->get('api/exemplo/v1/recursos');

        $this->assertSame(['id' => 1], $resposta);
        $this->assertSame([
            self::RECURSOS,
            self::RECURSOS,
            'POST '.ProtheusGravado::URL_BASE.'/api/oauth2/v1/token?grant_type=refresh_token&refresh_token=REFRESH-FICTICIO-1',
            self::RECURSOS,
        ], $this->chamadas());
        $this->assertSame('Bearer ACESSO-FICTICIO-2', $this->protheus->requisicao(4)->getHeaderLine('Authorization'));
    }

    public function test_a_chamada_depois_do_401_tambem_repete_em_falha_passageira(): void
    {
        $this->protheus->responde(401)
            ->respondeArquivo(200, 'token-renovado.json')
            ->responde(503)
            ->respondeJson(200, ['id' => 1]);

        $resposta = $this->protheus->cliente(repeticao: $this->protheus->repeticao())->get('api/exemplo/v1/recursos');

        $this->assertSame(['id' => 1], $resposta);
        $this->assertCount(4, $this->chamadas());
        $this->assertCount(1, $this->protheus->esperas);
    }

    public function test_pagina_da_colecao_tambem_e_repetida(): void
    {
        $this->protheus->responde(503)->respondeArquivo(200, 'colecao-pagina-3.json');

        $itens = iterator_to_array($this->protheus->cliente(repeticao: $this->protheus->repeticao())->colecao('api/exemplo/v1/recursos'));

        $this->assertCount(1, $itens);
        $this->assertCount(2, $this->chamadas());
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function repeticoesInvalidas(): array
    {
        return [
            'nenhuma tentativa' => [0, 500, 5000],
            'espera negativa' => [3, -1, 5000],
            'máxima menor que a inicial' => [3, 500, 100],
        ];
    }

    #[DataProvider('repeticoesInvalidas')]
    public function test_repeticao_recusa_configuracao_sem_sentido(int $tentativas, int $inicial, int $maxima): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Repeticao($tentativas, $inicial, $maxima);
    }

    public function test_o_padrao_tenta_tres_vezes_entre_meio_e_cinco_segundos(): void
    {
        $padrao = new Repeticao;

        $this->assertSame([3, 500, 5000], [$padrao->tentativas, $padrao->esperaInicialMs, $padrao->esperaMaximaMs]);
    }
}
