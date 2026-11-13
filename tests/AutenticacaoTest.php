<?php

namespace Tests;

use Canhao43\ProtheusRest\Erros\ErroDaApi;
use Canhao43\ProtheusRest\Erros\ErroDeAutenticacao;
use Canhao43\ProtheusRest\Erros\RespostaInvalida;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Apoio\ProtheusGravado;

/**
 * O ciclo do token descrito no TDN (pageId=465383509): login com usuário e senha,
 * Bearer em cada chamada, renovação pelo refresh_token e 401 quando o token vence.
 */
class AutenticacaoTest extends TestCase
{
    private const LOGIN = 'POST '.ProtheusGravado::URL_BASE.'/api/oauth2/v1/token?grant_type=password';

    private const RECURSO = ProtheusGravado::URL_BASE.'/api/exemplo/v1/recursos';

    private ProtheusGravado $protheus;

    protected function setUp(): void
    {
        $this->protheus = new ProtheusGravado;
    }

    private static function renovacao(string $refreshToken): string
    {
        return 'POST '.ProtheusGravado::URL_BASE.'/api/oauth2/v1/token?grant_type=refresh_token&refresh_token='.$refreshToken;
    }

    public function test_o_login_manda_usuario_e_senha_em_cabecalho_e_nunca_na_url(): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')->respondeJson(200, ['id' => 1]);

        $this->protheus->cliente()->get('api/exemplo/v1/recursos');

        $login = $this->protheus->requisicao(0);
        $this->assertSame(self::LOGIN, $login->getMethod().' '.$login->getUri());
        $this->assertSame(ProtheusGravado::USUARIO, $login->getHeaderLine('username'));
        $this->assertSame(ProtheusGravado::SENHA, $login->getHeaderLine('password'));
        $this->assertStringNotContainsString(ProtheusGravado::SENHA, (string) $login->getUri());
        $this->assertSame('', (string) $login->getBody());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function urlsBase(): array
    {
        return [
            'base sem barra no fim' => [ProtheusGravado::URL_BASE, 'api/exemplo/v1/recursos'],
            'base com barra no fim' => [ProtheusGravado::URL_BASE.'/', 'api/exemplo/v1/recursos'],
            'caminho com barra no início' => [ProtheusGravado::URL_BASE, '/api/exemplo/v1/recursos'],
        ];
    }

    #[DataProvider('urlsBase')]
    public function test_a_url_base_mantem_o_caminho_do_rest(string $urlBase, string $caminho): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')->respondeJson(200, []);

        $this->protheus->cliente($urlBase)->get($caminho);

        $this->assertSame([self::LOGIN, 'GET '.self::RECURSO], $this->protheus->roteiro());
    }

    public function test_a_chamada_leva_o_access_token_como_bearer(): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')->respondeJson(200, ['id' => 1]);

        $resposta = $this->protheus->cliente()->get('api/exemplo/v1/recursos/1');

        $chamada = $this->protheus->requisicao(1);
        $this->assertSame('Bearer ACESSO-FICTICIO-1', $chamada->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $chamada->getHeaderLine('Accept'));
        $this->assertFalse($chamada->hasHeader('password'));
        $this->assertSame(['id' => 1], $resposta);
    }

    public function test_o_token_e_reaproveitado_enquanto_vale(): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')->respondeJson(200, [])->respondeJson(200, []);
        $cliente = $this->protheus->cliente();

        $cliente->get('api/exemplo/v1/recursos');
        $this->protheus->relogio->avancar(1800);
        $cliente->get('api/exemplo/v1/recursos');

        $this->assertSame([self::LOGIN, 'GET '.self::RECURSO, 'GET '.self::RECURSO], $this->protheus->roteiro());
    }

    public function test_renova_pelo_refresh_token_um_minuto_antes_de_vencer(): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')
            ->respondeJson(200, [])
            ->respondeJson(200, [])
            ->respondeArquivo(200, 'token-renovado.json')
            ->respondeJson(200, []);
        $cliente = $this->protheus->cliente();

        $cliente->get('api/exemplo/v1/recursos');
        // expires_in é 3600: com a margem de 60 s, o último segundo em que o token serve é o 3539.
        $this->protheus->relogio->avancar(3539);
        $cliente->get('api/exemplo/v1/recursos');
        $this->protheus->relogio->avancar(1);
        $cliente->get('api/exemplo/v1/recursos');

        $this->assertSame([
            self::LOGIN,
            'GET '.self::RECURSO,
            'GET '.self::RECURSO,
            self::renovacao('REFRESH-FICTICIO-1'),
            'GET '.self::RECURSO,
        ], $this->protheus->roteiro());
        $this->assertFalse($this->protheus->requisicao(3)->hasHeader('password'));
        $this->assertSame('Bearer ACESSO-FICTICIO-2', $this->protheus->requisicao(4)->getHeaderLine('Authorization'));
    }

    public function test_refresh_recusado_cai_para_o_login_com_senha(): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')
            ->respondeJson(200, [])
            ->respondeArquivo(401, 'erro.json')
            ->respondeArquivo(200, 'token-renovado.json')
            ->respondeJson(200, []);
        $cliente = $this->protheus->cliente();

        $cliente->get('api/exemplo/v1/recursos');
        // Passou do dia: o refresh_token (24 h) também venceu.
        $this->protheus->relogio->avancar(25 * 3600);
        $cliente->get('api/exemplo/v1/recursos');

        $this->assertSame([
            self::LOGIN,
            'GET '.self::RECURSO,
            self::renovacao('REFRESH-FICTICIO-1'),
            self::LOGIN,
            'GET '.self::RECURSO,
        ], $this->protheus->roteiro());
        $this->assertSame('Bearer ACESSO-FICTICIO-2', $this->protheus->requisicao(4)->getHeaderLine('Authorization'));
    }

    public function test_erro_do_servidor_na_renovacao_nao_vira_tentativa_de_senha(): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')
            ->respondeJson(200, [])
            ->responde(500, 'erro interno');
        $cliente = $this->protheus->cliente();
        $cliente->get('api/exemplo/v1/recursos');
        $this->protheus->relogio->avancar(3600);

        try {
            $cliente->get('api/exemplo/v1/recursos');
            $this->fail('Deveria ter lançado ErroDaApi');
        } catch (ErroDaApi $erro) {
            $this->assertNotInstanceOf(ErroDeAutenticacao::class, $erro);
            $this->assertSame(500, $erro->status);
        }

        $this->assertCount(3, $this->protheus->requisicoes());
    }

    public function test_sem_refresh_token_na_resposta_a_renovacao_e_pelo_login(): void
    {
        $this->protheus->respondeJson(200, ['access_token' => 'ACESSO-FICTICIO-1', 'expires_in' => 3600])
            ->respondeJson(200, [])
            ->respondeArquivo(200, 'token-renovado.json')
            ->respondeJson(200, []);
        $cliente = $this->protheus->cliente();

        $cliente->get('api/exemplo/v1/recursos');
        $this->protheus->relogio->avancar(3600);
        $cliente->get('api/exemplo/v1/recursos');

        $this->assertSame([self::LOGIN, 'GET '.self::RECURSO, self::LOGIN, 'GET '.self::RECURSO], $this->protheus->roteiro());
    }

    public function test_401_da_api_renova_o_token_e_repete_a_chamada_uma_vez(): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')
            ->responde(401)
            ->respondeArquivo(200, 'token-renovado.json')
            ->respondeJson(201, ['id' => 7]);

        $resposta = $this->protheus->cliente()->post('api/exemplo/v1/recursos', ['nome' => 'Exemplo']);

        $this->assertSame(['id' => 7], $resposta);
        $this->assertSame([
            self::LOGIN,
            'POST '.self::RECURSO,
            self::renovacao('REFRESH-FICTICIO-1'),
            'POST '.self::RECURSO,
        ], $this->protheus->roteiro());
        $repetida = $this->protheus->requisicao(3);
        $this->assertSame('Bearer ACESSO-FICTICIO-2', $repetida->getHeaderLine('Authorization'));
        $this->assertSame('{"nome":"Exemplo"}', (string) $repetida->getBody());
    }

    public function test_401_de_novo_depois_de_renovar_sobe_sem_laco(): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')
            ->responde(401)
            ->respondeArquivo(200, 'token-renovado.json')
            ->responde(401);

        try {
            $this->protheus->cliente()->get('api/exemplo/v1/recursos');
            $this->fail('Deveria ter lançado ErroDaApi');
        } catch (ErroDaApi $erro) {
            $this->assertSame(401, $erro->status);
        }

        $this->assertCount(4, $this->protheus->requisicoes());
    }

    public function test_outro_erro_da_api_nao_renova_o_token(): void
    {
        $this->protheus->respondeArquivo(200, 'token.json')->respondeArquivo(403, 'erro.json');

        try {
            $this->protheus->cliente()->get('api/exemplo/v1/recursos');
            $this->fail('Deveria ter lançado ErroDaApi');
        } catch (ErroDaApi $erro) {
            $this->assertSame(403, $erro->status);
        }

        $this->assertCount(2, $this->protheus->requisicoes());
    }

    public function test_login_recusado_lanca_erro_de_autenticacao_sem_tentar_de_novo(): void
    {
        $this->protheus->respondeArquivo(401, 'erro.json');

        try {
            $this->protheus->cliente()->get('api/exemplo/v1/recursos');
            $this->fail('Deveria ter lançado ErroDeAutenticacao');
        } catch (ErroDeAutenticacao $erro) {
            $this->assertSame(401, $erro->status);
            $this->assertSame('EXEMPLO-404', $erro->mensagemDeErro?->code);
            $this->assertStringNotContainsString(ProtheusGravado::SENHA, $erro->getMessage());
        }

        $this->assertSame([self::LOGIN], $this->protheus->roteiro());
    }

    public function test_login_sem_access_token_e_resposta_invalida(): void
    {
        $this->protheus->respondeJson(200, ['token_type' => 'Bearer', 'expires_in' => 3600]);

        $this->expectException(RespostaInvalida::class);

        $this->protheus->cliente()->get('api/exemplo/v1/recursos');
    }
}
