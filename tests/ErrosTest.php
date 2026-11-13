<?php

namespace Tests;

use Canhao43\ProtheusRest\Autenticacao\Token;
use Canhao43\ProtheusRest\Credenciais;
use Canhao43\ProtheusRest\Erros\ErroDaApi;
use Canhao43\ProtheusRest\Erros\ErroDeConexao;
use Canhao43\ProtheusRest\Erros\RespostaInvalida;
use DateTimeImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Tests\Apoio\ProtheusGravado;

/**
 * Erros no padrão do guia de API da TOTVS (pageId=484701329) e nenhum segredo em mensagem.
 */
class ErrosTest extends TestCase
{
    private ProtheusGravado $protheus;

    protected function setUp(): void
    {
        $this->protheus = (new ProtheusGravado)->respondeArquivo(200, 'token.json');
    }

    private function erroDaApi(callable $chamada): ErroDaApi
    {
        try {
            $chamada();
        } catch (ErroDaApi $erro) {
            return $erro;
        }

        $this->fail('Deveria ter lançado ErroDaApi');
    }

    public function test_erro_no_padrao_totvs_traz_os_campos_do_guia(): void
    {
        $this->protheus->respondeArquivo(404, 'erro.json');

        $erro = $this->erroDaApi(fn () => $this->protheus->cliente()->get('api/exemplo/v1/recursos/42'));

        $this->assertSame(404, $erro->status);
        $this->assertSame('HTTP 404 em GET /rest/api/exemplo/v1/recursos/42: [EXEMPLO-404] Registro não encontrado.', $erro->getMessage());
        $mensagem = $erro->mensagemDeErro;
        $this->assertNotNull($mensagem);
        $this->assertSame('Nenhum registro com o id 42 no recurso de exemplo.', $mensagem->detailedMessage);
        $this->assertSame('https://exemplo.invalid/ajuda/EXEMPLO-404', $mensagem->helpUrl);
        $this->assertSame(['EXEMPLO-404-1', 'EXEMPLO-404-2'], array_map(fn ($d) => $d->code, $mensagem->details));
        $this->assertSame('Filial 99 sem cadastro.', $mensagem->details[1]->detailedMessage);
    }

    public function test_erro_fora_do_padrao_tambem_vira_erro_da_api(): void
    {
        $this->protheus->responde(502, '<html><body>Bad Gateway</body></html>', ['Content-Type' => 'text/html']);

        $erro = $this->erroDaApi(fn () => $this->protheus->cliente()->get('api/exemplo/v1/recursos'));

        $this->assertSame(502, $erro->status);
        $this->assertNull($erro->mensagemDeErro);
        $this->assertSame('HTTP 502 em GET /rest/api/exemplo/v1/recursos', $erro->getMessage());
    }

    public function test_code_numerico_e_aceito_como_texto(): void
    {
        $this->protheus->respondeJson(400, ['code' => 400, 'message' => 'Requisição inválida.']);

        $erro = $this->erroDaApi(fn () => $this->protheus->cliente()->get('api/exemplo/v1/recursos'));

        $this->assertSame('400', $erro->mensagemDeErro?->code);
        $this->assertNull($erro->mensagemDeErro->detailedMessage);
        $this->assertSame([], $erro->mensagemDeErro->details);
    }

    public function test_a_mensagem_de_erro_nao_leva_a_query(): void
    {
        $this->protheus->respondeArquivo(400, 'erro.json');

        $erro = $this->erroDaApi(fn () => $this->protheus->cliente()->get('api/exemplo/v1/recursos', ['nome' => 'valor-sensivel']));

        $this->assertStringContainsString('nome=valor-sensivel', (string) $this->protheus->requisicao(1)->getUri());
        $this->assertStringNotContainsString('valor-sensivel', $erro->getMessage());
    }

    public function test_falha_de_conexao_na_renovacao_nao_vaza_o_refresh_token(): void
    {
        $url = ProtheusGravado::URL_BASE.'/api/oauth2/v1/token?grant_type=refresh_token&refresh_token=REFRESH-FICTICIO-1';
        // Mensagem e contexto no formato que o Guzzle monta para um erro de cURL.
        $this->protheus->respondeJson(200, [])->falha(new ConnectException(
            "cURL error 7: Failed to connect to erp.exemplo.invalid port 8080 (see https://curl.se/libcurl/c/libcurl-errors.html) for {$url}",
            new Request('POST', $url),
            null,
            ['errno' => 7, 'error' => 'Failed to connect to erp.exemplo.invalid port 8080'],
        ));
        $cliente = $this->protheus->cliente();
        $cliente->get('api/exemplo/v1/recursos');
        $this->protheus->relogio->avancar(3600);

        try {
            $cliente->get('api/exemplo/v1/recursos');
            $this->fail('Deveria ter lançado ErroDeConexao');
        } catch (ErroDeConexao $erro) {
            $this->assertSame(
                'Sem resposta do Protheus em POST https://erp.exemplo.invalid:8080/rest/api/oauth2/v1/token: Failed to connect to erp.exemplo.invalid port 8080',
                $erro->getMessage(),
            );
            $this->assertNull($erro->getPrevious());
        }
    }

    public function test_sucesso_sem_corpo_devolve_array_vazio(): void
    {
        $this->protheus->responde(204);

        $this->assertSame([], $this->protheus->cliente()->delete('api/exemplo/v1/recursos/42'));
    }

    public function test_sucesso_com_corpo_que_nao_e_json_e_resposta_invalida(): void
    {
        $this->protheus->responde(200, '<html>Ok</html>', ['Content-Type' => 'text/html']);

        $this->expectException(RespostaInvalida::class);
        $this->expectExceptionMessage('HTTP 200 em GET /rest/api/exemplo/v1/recursos, mas o corpo não é um objeto JSON.');

        $this->protheus->cliente()->get('api/exemplo/v1/recursos');
    }

    public function test_senha_e_tokens_nao_aparecem_ao_inspecionar_os_objetos(): void
    {
        $credenciais = new Credenciais(ProtheusGravado::USUARIO, ProtheusGravado::SENHA);
        $token = new Token('ACESSO-FICTICIO-1', 'REFRESH-FICTICIO-1', new DateTimeImmutable);

        ob_start();
        var_dump($credenciais, $token);
        $despejo = (string) ob_get_clean().print_r($credenciais, true).print_r($token, true);

        $this->assertStringContainsString(ProtheusGravado::USUARIO, $despejo);
        foreach ([ProtheusGravado::SENHA, 'ACESSO-FICTICIO-1', 'REFRESH-FICTICIO-1'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $despejo);
        }
    }
}
