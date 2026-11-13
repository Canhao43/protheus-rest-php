<?php

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Apoio\CacheEmMemoria;
use Tests\Apoio\ProtheusGravado;

/**
 * O token guardado num cache PSR-16 vale entre processos: cada ClienteProtheus novo é um
 * processo PHP novo (uma requisição web, um job da fila).
 */
class CacheDoTokenTest extends TestCase
{
    private const LOGIN = 'POST '.ProtheusGravado::URL_BASE.'/api/oauth2/v1/token?grant_type=password';

    private const RECURSOS = 'GET '.ProtheusGravado::URL_BASE.'/api/exemplo/v1/recursos';

    private ProtheusGravado $protheus;

    private CacheEmMemoria $cache;

    protected function setUp(): void
    {
        $this->protheus = new ProtheusGravado;
        $this->cache = new CacheEmMemoria;
    }

    public function test_o_segundo_processo_usa_o_token_do_primeiro_sem_login(): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')->respondeJson(200, [])->respondeJson(200, []);

        $this->protheus->cliente(cache: $this->cache)->get('api/exemplo/v1/recursos');
        $this->protheus->cliente(cache: $this->cache)->get('api/exemplo/v1/recursos');

        $this->assertSame([self::LOGIN, self::RECURSOS, self::RECURSOS], $this->protheus->roteiro());
        $this->assertSame('Bearer ACESSO-FICTICIO-1', $this->protheus->requisicao(2)->getHeaderLine('Authorization'));
    }

    public function test_token_vencido_no_cache_e_renovado_pelo_refresh_do_cache(): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')
            ->respondeJson(200, [])
            ->respondeArquivo(200, 'token-renovado.json')
            ->respondeJson(200, [])
            ->respondeJson(200, []);

        $this->protheus->cliente(cache: $this->cache)->get('api/exemplo/v1/recursos');
        $this->protheus->relogio->avancar(3600);
        $this->protheus->cliente(cache: $this->cache)->get('api/exemplo/v1/recursos');
        // Um terceiro processo já encontra o token renovado pelo segundo.
        $this->protheus->cliente(cache: $this->cache)->get('api/exemplo/v1/recursos');

        $this->assertSame([
            self::LOGIN,
            self::RECURSOS,
            'POST '.ProtheusGravado::URL_BASE.'/api/oauth2/v1/token?grant_type=refresh_token&refresh_token=REFRESH-FICTICIO-1',
            self::RECURSOS,
            self::RECURSOS,
        ], $this->protheus->roteiro());
        $this->assertSame('Bearer ACESSO-FICTICIO-2', $this->protheus->requisicao(4)->getHeaderLine('Authorization'));
    }

    public function test_o_cache_tem_uma_chave_por_servidor_e_usuario_sem_dado_em_claro(): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')->respondeJson(200, []);

        $this->protheus->cliente(cache: $this->cache)->get('api/exemplo/v1/recursos');

        $chaves = array_keys($this->cache->valores);
        $this->assertCount(1, $chaves);
        // Só os caracteres que a PSR-16 garante em qualquer cache: letras, números, "_" e ".".
        $this->assertMatchesRegularExpression('/^protheus_rest\.token\.[0-9a-f]{64}$/', $chaves[0]);
        $this->assertStringNotContainsString(ProtheusGravado::USUARIO, $chaves[0]);
        $this->assertStringNotContainsString(ProtheusGravado::SENHA, (string) json_encode($this->cache->valores));
    }

    public function test_outro_usuario_no_mesmo_cache_faz_o_proprio_login(): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')
            ->respondeJson(200, [])
            ->respondeArquivo(200, 'token-renovado.json')
            ->respondeJson(200, []);

        $this->protheus->cliente(cache: $this->cache)->get('api/exemplo/v1/recursos');
        $this->protheus->cliente(cache: $this->cache, usuario: 'outro.usuario')->get('api/exemplo/v1/recursos');

        $this->assertSame([self::LOGIN, self::RECURSOS, self::LOGIN, self::RECURSOS], $this->protheus->roteiro());
        $this->assertSame('outro.usuario', $this->protheus->requisicao(2)->getHeaderLine('username'));
        $this->assertCount(2, $this->cache->valores);
    }

    public function test_a_mesma_url_com_ou_sem_barra_final_divide_o_token(): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')->respondeJson(200, [])->respondeJson(200, []);

        $this->protheus->cliente(urlBase: ProtheusGravado::URL_BASE, cache: $this->cache)->get('api/exemplo/v1/recursos');
        $this->protheus->cliente(urlBase: ProtheusGravado::URL_BASE.'/', cache: $this->cache)->get('api/exemplo/v1/recursos');

        $this->assertSame([self::LOGIN, self::RECURSOS, self::RECURSOS], $this->protheus->roteiro());
    }

    /**
     * Cada entrada tem um defeito só, para cada conferência ser necessária.
     *
     * @return array<string, array{mixed}>
     */
    public static function entradasEstranhas(): array
    {
        $daqui = (new \DateTimeImmutable('2026-01-05 10:00:00'))->getTimestamp();

        return [
            'texto em vez de array' => ['token'],
            // Objeto, como um cache que desserializa outra coisa: ler um campo dele quebraria.
            'objeto em vez de array' => [new \stdClass],
            'access_token vazio' => [['access_token' => '', 'refresh_token' => null, 'expira_em' => $daqui]],
            'sem access_token' => [['refresh_token' => 'R', 'expira_em' => $daqui]],
            'expira_em em texto' => [['access_token' => 'A', 'refresh_token' => null, 'expira_em' => 'amanhã']],
            'refresh_token numérico' => [['access_token' => 'A', 'refresh_token' => 123, 'expira_em' => $daqui]],
        ];
    }

    #[DataProvider('entradasEstranhas')]
    public function test_entrada_estranha_no_cache_e_ignorada(mixed $entrada): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')->respondeJson(200, []);
        $chave = 'protheus_rest.token.'.hash('sha256', ProtheusGravado::URL_BASE."\n".ProtheusGravado::USUARIO);
        $this->cache->valores[$chave] = $entrada;

        $this->protheus->cliente(cache: $this->cache)->get('api/exemplo/v1/recursos');

        $this->assertSame([self::LOGIN, self::RECURSOS], $this->protheus->roteiro());
        $this->assertSame('ACESSO-FICTICIO-1', $this->cache->valores[$chave]['access_token']);
    }

    public function test_cache_fora_do_ar_nao_derruba_a_chamada(): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')->respondeJson(200, ['id' => 1]);

        $resposta = $this->protheus->cliente(cache: new CacheEmMemoria(quebrado: true))->get('api/exemplo/v1/recursos');

        $this->assertSame(['id' => 1], $resposta);
        $this->assertSame([self::LOGIN, self::RECURSOS], $this->protheus->roteiro());
    }

    public function test_o_token_fica_no_cache_por_24_horas(): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')->respondeJson(200, []);

        $this->protheus->cliente(cache: $this->cache)->get('api/exemplo/v1/recursos');

        $this->assertSame([86400], array_values($this->cache->ttls));
    }

    public function test_401_renova_e_grava_o_token_novo_no_cache(): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')
            ->responde(401)
            ->respondeArquivo(200, 'token-renovado.json')
            ->respondeJson(200, []);

        $this->protheus->cliente(cache: $this->cache)->get('api/exemplo/v1/recursos');

        $this->assertSame(['ACESSO-FICTICIO-2'], array_column($this->cache->valores, 'access_token'));
    }
}
