<?php

namespace Tests;

use Canhao43\ProtheusRest\Colecoes\Consulta;
use Canhao43\ProtheusRest\Erros\RespostaInvalida;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Apoio\ProtheusGravado;

/**
 * Coleções no formato do guia de API da TOTVS (pageId=484701329): "page" e "pageSize" na
 * URL, "hasNext" e "items" na resposta.
 */
class ColecoesTest extends TestCase
{
    private const RECURSOS = 'GET '.ProtheusGravado::URL_BASE.'/api/exemplo/v1/recursos';

    private ProtheusGravado $protheus;

    protected function setUp(): void
    {
        $this->protheus = (new ProtheusGravado)->respondeArquivo(200, 'token.json');
    }

    /**
     * @return list<string> o roteiro sem o login
     */
    private function chamadas(): array
    {
        return array_slice($this->protheus->roteiro(), 1);
    }

    public function test_pagina_manda_page_e_pagesize_e_separa_os_campos_a_mais(): void
    {
        $this->protheus->respondeArquivo(200, 'colecao-pagina-2.json');

        $pagina = $this->protheus->cliente()->pagina('api/exemplo/v1/recursos', 2, 2);

        $this->assertSame([self::RECURSOS.'?page=2&pageSize=2'], $this->chamadas());
        $this->assertSame(2, $pagina->numero);
        $this->assertTrue($pagina->hasNext);
        $this->assertSame(['000003', '000004'], array_column($pagina->items, 'codigo'));
        $this->assertSame(['totvs_transaction_id' => 2, 'total' => 5], $pagina->extras);
    }

    public function test_sem_tamanho_vale_o_padrao_do_endpoint(): void
    {
        $this->protheus->respondeArquivo(200, 'colecao-pagina-1.json');

        $this->protheus->cliente()->pagina('api/exemplo/v1/recursos');

        $this->assertSame([self::RECURSOS.'?page=1'], $this->chamadas());
    }

    public function test_colecao_percorre_as_paginas_ate_hasnext_falso(): void
    {
        $this->protheus->respondeArquivo(200, 'colecao-pagina-1.json')
            ->respondeArquivo(200, 'colecao-pagina-2.json')
            ->respondeArquivo(200, 'colecao-pagina-3.json');

        $itens = iterator_to_array($this->protheus->cliente()->colecao('api/exemplo/v1/recursos', tamanhoDaPagina: 2));

        $this->assertSame([
            self::RECURSOS.'?page=1&pageSize=2',
            self::RECURSOS.'?page=2&pageSize=2',
            self::RECURSOS.'?page=3&pageSize=2',
        ], $this->chamadas());
        // As chaves seguem de 0 a 4: com as chaves de cada página, iterator_to_array perderia itens.
        $this->assertSame([0, 1, 2, 3, 4], array_keys($itens));
        $this->assertSame(['000001', '000002', '000003', '000004', '000005'], array_column($itens, 'codigo'));
    }

    public function test_colecao_so_pede_a_proxima_pagina_quando_precisa(): void
    {
        $this->protheus->respondeArquivo(200, 'colecao-pagina-1.json');

        $colecao = $this->protheus->cliente()->colecao('api/exemplo/v1/recursos');
        $this->assertSame([], $this->chamadas(), 'Criar a coleção não deveria chamar nada');

        foreach ($colecao as $item) {
            break;
        }

        $this->assertSame([self::RECURSOS.'?page=1'], $this->chamadas());
    }

    public function test_colecao_vazia_termina_sem_itens(): void
    {
        $this->protheus->respondeJson(200, ['hasNext' => false, 'items' => []]);

        $this->assertSame([], iterator_to_array($this->protheus->cliente()->colecao('api/exemplo/v1/recursos')));
        $this->assertCount(1, $this->chamadas());
    }

    public function test_token_vencido_no_meio_da_colecao_e_renovado_entre_as_paginas(): void
    {
        $this->protheus->respondeArquivo(200, 'colecao-pagina-1.json')
            ->respondeArquivo(200, 'token-renovado.json')
            ->respondeArquivo(200, 'colecao-pagina-3.json');
        $itens = [];

        foreach ($this->protheus->cliente()->colecao('api/exemplo/v1/recursos') as $item) {
            $itens[] = $item;
            // Quem consome demora: a leitura da primeira página passa da hora do token.
            $this->protheus->relogio->avancar(1800);
        }

        $this->assertCount(3, $itens);
        $this->assertStringContainsString('grant_type=refresh_token', $this->chamadas()[1]);
        $this->assertSame('Bearer ACESSO-FICTICIO-2', $this->protheus->requisicao(3)->getHeaderLine('Authorization'));
    }

    public function test_pagina_vazia_que_diz_ter_proxima_para_a_colecao(): void
    {
        $this->protheus->respondeJson(200, ['hasNext' => true, 'items' => []]);

        $this->expectException(RespostaInvalida::class);
        $this->expectExceptionMessage('veio vazia, mas diz que há próxima');

        iterator_to_array($this->protheus->cliente()->colecao('api/exemplo/v1/recursos'));
    }

    public function test_pagina_repetida_para_a_colecao(): void
    {
        // Um servidor que ignora o "page" devolveria sempre a primeira página, com hasNext verdadeiro.
        $this->protheus->respondeArquivo(200, 'colecao-pagina-1.json')
            ->respondeArquivo(200, 'colecao-pagina-1.json');

        $lidos = [];
        try {
            foreach ($this->protheus->cliente()->colecao('api/exemplo/v1/recursos') as $item) {
                $lidos[] = $item;
            }
            $this->fail('Deveria ter lançado RespostaInvalida');
        } catch (RespostaInvalida $erro) {
            $this->assertStringContainsString('repetiu a anterior', $erro->getMessage());
        }

        $this->assertCount(2, $lidos, 'Os itens da página repetida não podem sair');
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function corposForaDoFormato(): array
    {
        return [
            'sem hasNext' => [['items' => []]],
            'hasNext em texto' => [['hasNext' => 'false', 'items' => []]],
            'sem items' => [['hasNext' => false]],
            'items como objeto' => [['hasNext' => false, 'items' => ['a' => ['codigo' => '000001']]]],
            'lista solta, sem envelope' => [[['codigo' => '000001']]],
        ];
    }

    /**
     * @param  array<mixed>  $corpo
     */
    #[DataProvider('corposForaDoFormato')]
    public function test_resposta_fora_do_formato_de_colecao_e_resposta_invalida(array $corpo): void
    {
        $this->protheus->respondeJson(200, $corpo);

        $this->expectException(RespostaInvalida::class);
        $this->expectExceptionMessage('A página 1 de api/exemplo/v1/recursos não está no formato de coleção da TOTVS');

        $this->protheus->cliente()->pagina('api/exemplo/v1/recursos');
    }

    public function test_consulta_vira_filtros_order_fields_e_expand(): void
    {
        $this->protheus->respondeArquivo(200, 'colecao-pagina-3.json');
        $consulta = new Consulta(
            filtros: ['nome' => 'exemplo', 'ativo' => 'true'],
            ordem: ['nome', '-codigo'],
            campos: ['codigo', 'nome'],
            expandir: ['enderecos', 'contatos.telefones'],
        );

        $this->protheus->cliente()->pagina('api/exemplo/v1/recursos', 3, 2, $consulta);

        $this->assertSame(
            'nome=exemplo&ativo=true&order=nome%2C-codigo&fields=codigo%2Cnome&expand=enderecos%2Ccontatos.telefones&page=3&pageSize=2',
            $this->protheus->requisicao(1)->getUri()->getQuery(),
        );
    }

    public function test_consulta_vazia_nao_manda_parametro_nenhum(): void
    {
        $this->assertSame([], (new Consulta)->paraQuery());
    }

    public function test_filtro_odata_passa_como_filtro_simples(): void
    {
        $consulta = new Consulta(filtros: ['$filter' => "nome eq 'exemplo'"], ordem: ['codigo']);

        $this->assertSame(['$filter' => "nome eq 'exemplo'", 'order' => 'codigo'], $consulta->paraQuery());
    }

    /**
     * @return array<string, array{callable(): Consulta}>
     */
    public static function consultasInvalidas(): array
    {
        // A lista vem do guia, escrita aqui, e não da constante da classe: lida da constante,
        // tirar um nome dela passaria despercebido.
        $reservados = [];
        foreach (['page', 'pageSize', 'order', 'fields', 'expand'] as $nome) {
            $reservados["filtro chamado {$nome}"] = [fn () => new Consulta(filtros: [$nome => '1'])];
        }

        return $reservados + [
            'filtro sem nome' => [fn () => new Consulta(filtros: ['valor'])],
            'filtro com lista' => [fn () => new Consulta(filtros: ['nome' => ['a', 'b']])],
            'campo com vírgula' => [fn () => new Consulta(campos: ['codigo,nome'])],
            'campo vazio' => [fn () => new Consulta(campos: [''])],
            'ordem só com o sinal' => [fn () => new Consulta(ordem: ['-'])],
            'ordem com vírgula' => [fn () => new Consulta(ordem: ['-nome,codigo'])],
            'expandir com vírgula' => [fn () => new Consulta(expandir: ['a,b'])],
        ];
    }

    /**
     * @param  callable(): Consulta  $criar
     */
    #[DataProvider('consultasInvalidas')]
    public function test_consulta_recusa_o_que_mudaria_a_url_por_baixo(callable $criar): void
    {
        $this->expectException(InvalidArgumentException::class);

        $criar();
    }

    /**
     * @return array<string, array{int, int|null}>
     */
    public static function paginasInvalidas(): array
    {
        return [
            'página zero' => [0, null],
            'página negativa' => [-1, 10],
            'tamanho zero' => [1, 0],
        ];
    }

    #[DataProvider('paginasInvalidas')]
    public function test_numero_e_tamanho_comecam_em_1(int $numero, ?int $tamanho): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->protheus->cliente()->pagina('api/exemplo/v1/recursos', $numero, $tamanho);
    }

    public function test_tamanho_invalido_na_colecao_falha_na_chamada_e_nao_no_foreach(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->protheus->cliente()->colecao('api/exemplo/v1/recursos', tamanhoDaPagina: 0);
    }
}
