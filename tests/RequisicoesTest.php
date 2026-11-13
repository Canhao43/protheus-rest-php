<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Apoio\ProtheusGravado;

/**
 * O que cada verbo manda: query na URL, corpo em JSON, e nada de corpo onde o guia proíbe.
 */
class RequisicoesTest extends TestCase
{
    private ProtheusGravado $protheus;

    protected function setUp(): void
    {
        $this->protheus = (new ProtheusGravado)->respondeArquivo(200, 'token.json');
    }

    public function test_get_manda_a_query_e_nenhum_corpo(): void
    {
        $this->protheus->respondeJson(200, ['hasNext' => false, 'items' => []]);

        $this->protheus->cliente()->get('api/exemplo/v1/recursos', ['page' => 2, 'pageSize' => 20, 'order' => 'nome,-codigo']);

        $chamada = $this->protheus->requisicao(1);
        $this->assertSame('GET', $chamada->getMethod());
        $this->assertSame('page=2&pageSize=20&order=nome%2C-codigo', $chamada->getUri()->getQuery());
        $this->assertSame('', (string) $chamada->getBody());
    }

    public function test_post_put_e_patch_mandam_o_corpo_em_json(): void
    {
        $this->protheus->respondeJson(201, [])->respondeJson(200, [])->respondeJson(200, []);
        $cliente = $this->protheus->cliente();

        $cliente->post('api/exemplo/v1/recursos', ['nome' => 'Exemplo']);
        $cliente->put('api/exemplo/v1/recursos/1', ['nome' => 'Outro']);
        $cliente->patch('api/exemplo/v1/recursos/1', [['op' => 'replace', 'path' => '/nome', 'value' => 'Mais um']]);

        $esperado = [
            1 => ['POST', '{"nome":"Exemplo"}'],
            2 => ['PUT', '{"nome":"Outro"}'],
            3 => ['PATCH', '[{"op":"replace","path":"\/nome","value":"Mais um"}]'],
        ];
        foreach ($esperado as $indice => [$metodo, $corpo]) {
            $chamada = $this->protheus->requisicao($indice);
            $this->assertSame($metodo, $chamada->getMethod());
            $this->assertSame('application/json', $chamada->getHeaderLine('Content-Type'));
            $this->assertSame($corpo, (string) $chamada->getBody());
        }
    }

    public function test_post_sem_corpo_nao_manda_corpo(): void
    {
        $this->protheus->respondeJson(200, []);

        $this->protheus->cliente()->post('api/exemplo/v1/recursos/1/acao', null, ['tipo' => 'exemplo']);

        $chamada = $this->protheus->requisicao(1);
        $this->assertSame('', (string) $chamada->getBody());
        $this->assertFalse($chamada->hasHeader('Content-Type'));
        $this->assertSame('tipo=exemplo', $chamada->getUri()->getQuery());
    }

    public function test_delete_manda_so_a_query(): void
    {
        $this->protheus->responde(204);

        $this->protheus->cliente()->delete('api/exemplo/v1/recursos/1', ['motivo' => 'teste']);

        $chamada = $this->protheus->requisicao(1);
        $this->assertSame('DELETE '.ProtheusGravado::URL_BASE.'/api/exemplo/v1/recursos/1?motivo=teste', $chamada->getMethod().' '.$chamada->getUri());
        $this->assertSame('', (string) $chamada->getBody());
    }
}
