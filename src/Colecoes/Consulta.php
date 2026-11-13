<?php

namespace Canhao43\ProtheusRest\Colecoes;

use InvalidArgumentException;

/**
 * Filtro, ordem, campos e expansão de uma consulta, com os nomes de parâmetro do guia de
 * API da TOTVS: "propriedade=filtro", "order", "fields" e "expand".
 *
 * Fonte: TDN, "Guia de Implementação de API V1.0", seções "Ordenação", "Filtros simples",
 * "Filtro de campos da resposta" e "Parâmetros expansíveis"
 * (https://tdn.totvs.com/pages/releaseview.action?pageId=484701329).
 */
final class Consulta
{
    /**
     * Nomes que o guia reserva para a paginação e para a própria consulta. Um filtro com um
     * desses nomes sobrescreveria o parâmetro na URL sem ninguém perceber.
     */
    public const PARAMETROS_RESERVADOS = ['page', 'pageSize', 'order', 'fields', 'expand'];

    /**
     * @param  array<string, string|int|float|bool>  $filtros  filtro simples, propriedade => valor
     * @param  list<string>  $ordem  campos na ordem de prioridade; "-campo" é decrescente
     * @param  list<string>  $campos  só estes campos voltam em cada item
     * @param  list<string>  $expandir  propriedades a expandir, com ponto para subnível
     */
    public function __construct(
        public readonly array $filtros = [],
        public readonly array $ordem = [],
        public readonly array $campos = [],
        public readonly array $expandir = [],
    ) {
        foreach ($filtros as $nome => $valor) {
            if (! is_string($nome) || $nome === '') {
                throw new InvalidArgumentException('Filtro precisa de nome.');
            }
            if (in_array($nome, self::PARAMETROS_RESERVADOS, true)) {
                throw new InvalidArgumentException("\"{$nome}\" é parâmetro reservado da consulta, não nome de filtro.");
            }
            if (! is_scalar($valor)) {
                throw new InvalidArgumentException("O filtro \"{$nome}\" precisa de um valor simples.");
            }
        }

        // Na ordem, um "-" na frente quer dizer decrescente; o nome do campo é o que vem depois.
        self::conferirLista('ordem', array_map(
            fn ($campo) => is_string($campo) && str_starts_with($campo, '-') ? substr($campo, 1) : $campo,
            $ordem,
        ));
        self::conferirLista('campos', $campos);
        self::conferirLista('expandir', $expandir);
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    public function paraQuery(): array
    {
        $query = $this->filtros;

        foreach (['order' => $this->ordem, 'fields' => $this->campos, 'expand' => $this->expandir] as $parametro => $lista) {
            if ($lista !== []) {
                $query[$parametro] = implode(',', $lista);
            }
        }

        return $query;
    }

    /**
     * A lista vai separada por vírgula, então um nome com vírgula viraria dois campos.
     *
     * @param  array<mixed>  $lista
     */
    private static function conferirLista(string $nome, array $lista): void
    {
        foreach ($lista as $item) {
            if (! is_string($item) || $item === '' || str_contains($item, ',')) {
                throw new InvalidArgumentException("Cada item de \"{$nome}\" precisa ser um nome de campo, sem vírgula.");
            }
        }
    }
}
