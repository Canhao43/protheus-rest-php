<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * O registro de decisões só vale enquanto for verdadeiro: todo ADR está no índice, segue o
 * mesmo modelo, e cada teste ou etapa do CI que ele cita como prova existe. Renomear um teste
 * sem atualizar o ADR quebra o build, em vez de deixar a decisão apontando para o nada.
 */
class RegistroDeDecisoesTest extends TestCase
{
    private const RAIZ = __DIR__.'/..';

    private const SECOES = ['## Contexto', '## Decisão', '## Alternativas descartadas', '## Consequências', '## Como é verificado'];

    /**
     * @return array<string, string> nome do arquivo => texto, em ordem
     */
    private function decisoes(): array
    {
        $decisoes = [];
        foreach (glob(self::RAIZ.'/docs/decisoes/[0-9][0-9][0-9][0-9]-*.md') ?: [] as $arquivo) {
            $decisoes[basename($arquivo)] = str_replace("\r\n", "\n", (string) file_get_contents($arquivo));
        }
        ksort($decisoes);

        return $decisoes;
    }

    /**
     * @return array<string, class-string> nome curto => classe, de todo teste do projeto
     */
    private function classesDeTeste(): array
    {
        $classes = [];
        $pasta = realpath(self::RAIZ.'/tests');
        $this->assertNotFalse($pasta);

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta)) as $arquivo) {
            if (str_ends_with($arquivo->getFilename(), 'Test.php')) {
                $relativo = substr($arquivo->getPathname(), strlen($pasta) + 1, -4);
                /** @var class-string $classe */
                $classe = 'Tests\\'.str_replace(['/', '\\'], '\\', $relativo);
                $classes[basename(str_replace('\\', '/', $relativo))] = $classe;
            }
        }

        return $classes;
    }

    public function test_cada_decisao_tem_numero_em_sequencia_e_as_secoes_do_modelo(): void
    {
        $decisoes = $this->decisoes();
        $this->assertNotEmpty($decisoes);

        $esperado = 1;
        foreach ($decisoes as $nome => $texto) {
            // Sem buraco na numeração: uma decisão que mudou ganha texto novo, não some.
            $numero = sprintf('%04d', $esperado++);
            $this->assertMatchesRegularExpression('/^'.$numero.'-[a-z0-9-]+\.md$/', $nome);
            $this->assertStringStartsWith("# {$numero} — ", $texto, $nome);
            $this->assertStringContainsString("\n**Marco:** ", $texto, $nome);

            $posicao = 0;
            foreach (self::SECOES as $secao) {
                $achou = strpos($texto, "\n{$secao}\n", $posicao);
                $this->assertNotFalse($achou, "{$nome}: falta \"{$secao}\", ou está fora de ordem");
                $posicao = $achou;
            }
        }
    }

    public function test_o_indice_lista_cada_decisao_uma_vez_e_nenhuma_que_nao_existe(): void
    {
        $indice = (string) file_get_contents(self::RAIZ.'/docs/decisoes/README.md');
        $nomes = array_keys($this->decisoes());

        foreach ($nomes as $nome) {
            $this->assertSame(1, substr_count($indice, "]({$nome})"), "{$nome} precisa aparecer uma vez no índice");
        }

        preg_match_all('/\]\((\d{4}-[a-z0-9-]+\.md)\)/', $indice, $links);
        $this->assertSame([], array_values(array_diff($links[1], $nomes)), 'O índice aponta para decisão que não existe');
    }

    public function test_toda_prova_citada_existe(): void
    {
        $classes = $this->classesDeTeste();

        foreach ($this->decisoes() as $nome => $texto) {
            // Só a seção da prova, até o próximo título: citação em outra seção não prova nada.
            $prova = (string) preg_replace('/\n## .*/s', '', substr($texto, (int) strpos($texto, "\n## Como é verificado\n") + 1));
            preg_match_all('/`(\w+Test)(?:::(test_\w+))?`/', $prova, $testes, PREG_SET_ORDER);
            // A etapa vai em itálico; o formatador do projeto escreve _assim_, mas *assim* também vale.
            preg_match_all('/`([\w-]+\.yml)`: (?|\*([^*]+)\*|_([^_]+)_)/', $prova, $etapas, PREG_SET_ORDER);

            $this->assertNotEmpty([...$testes, ...$etapas], "{$nome} não aponta nenhuma prova");

            foreach ($testes as $citacao) {
                $this->assertArrayHasKey($citacao[1], $classes, "{$nome} cita {$citacao[1]}, que não existe");
                if (($citacao[2] ?? '') !== '') {
                    $this->assertTrue(method_exists($classes[$citacao[1]], $citacao[2]), "{$nome} cita {$citacao[1]}::{$citacao[2]}, que não existe");
                }
            }

            foreach ($etapas as [, $arquivo, $etapa]) {
                $workflow = (string) @file_get_contents(self::RAIZ."/.github/workflows/{$arquivo}");
                $this->assertMatchesRegularExpression('/^\s*- name: '.preg_quote($etapa, '/').'\s*$/m', $workflow, "{$nome} cita a etapa \"{$etapa}\" do {$arquivo}, que não existe");
            }
        }
    }
}
