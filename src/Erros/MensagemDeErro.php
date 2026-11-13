<?php

namespace Canhao43\ProtheusRest\Erros;

/**
 * O corpo de erro no padrão das APIs TOTVS. Os nomes ficam como a TOTVS escreve.
 *
 * Fonte: TDN, "Guia de Implementação de API V1.0", seção "Mensagens de erro"
 * (https://tdn.totvs.com/pages/releaseview.action?pageId=484701329).
 */
final class MensagemDeErro
{
    /**
     * @param  list<MensagemDeErro>  $details
     */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $detailedMessage = null,
        public readonly ?string $helpUrl = null,
        public readonly array $details = [],
    ) {}

    /**
     * Devolve null quando o corpo não segue o padrão (HTML de um proxy, corpo vazio):
     * o erro continua sendo lançado, só não há mensagem estruturada para mostrar.
     */
    public static function doCorpo(string $corpo): ?self
    {
        $dados = json_decode($corpo, true);

        return is_array($dados) ? self::doArray($dados) : null;
    }

    /**
     * @param  array<mixed>  $dados
     */
    private static function doArray(array $dados): ?self
    {
        $code = $dados['code'] ?? null;
        $message = $dados['message'] ?? null;

        // O guia também exige detailedMessage, mas sem code e message não há o que mostrar;
        // o resto entra se vier.
        if (! is_scalar($code) || ! is_string($message)) {
            return null;
        }

        $details = [];
        foreach (is_array($dados['details'] ?? null) ? $dados['details'] : [] as $detalhe) {
            $mensagem = is_array($detalhe) ? self::doArray($detalhe) : null;
            if ($mensagem !== null) {
                $details[] = $mensagem;
            }
        }

        return new self(
            (string) $code,
            $message,
            is_string($dados['detailedMessage'] ?? null) ? $dados['detailedMessage'] : null,
            is_string($dados['helpUrl'] ?? null) ? $dados['helpUrl'] : null,
            $details,
        );
    }
}
