<?php

namespace Canhao43\ProtheusRest\Colecoes;

use Canhao43\ProtheusRest\Erros\RespostaInvalida;

/**
 * Uma página de coleção no formato do guia de API da TOTVS: "hasNext" e "items", e os
 * campos a mais que a API quiser mandar (o guia cita "total" e "totvs_transaction_id").
 *
 * Fonte: TDN, "Guia de Implementação de API V1.0", seções "Paginação" e "Mensagens de
 * sucesso para coleções" (https://tdn.totvs.com/pages/releaseview.action?pageId=484701329).
 */
final class Pagina
{
    /**
     * @param  list<mixed>  $items
     * @param  array<mixed>  $extras  o resto do corpo, sem "hasNext" e "items"
     */
    public function __construct(
        public readonly int $numero,
        public readonly array $items,
        public readonly bool $hasNext,
        public readonly array $extras = [],
    ) {}

    /**
     * @param  array<mixed>  $corpo
     */
    public static function daResposta(int $numero, array $corpo, string $caminho): self
    {
        $items = $corpo['items'] ?? null;
        $hasNext = $corpo['hasNext'] ?? null;

        // Sem esses dois não dá para saber o que veio nem se há mais: o pacote não chuta.
        if (! is_array($items) || ! array_is_list($items) || ! is_bool($hasNext)) {
            throw new RespostaInvalida(sprintf(
                'A página %d de %s não está no formato de coleção da TOTVS ("hasNext" booleano e "items" em lista).',
                $numero,
                $caminho,
            ));
        }

        unset($corpo['items'], $corpo['hasNext']);

        return new self($numero, $items, $hasNext, $corpo);
    }
}
