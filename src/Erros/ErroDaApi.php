<?php

namespace Canhao43\ProtheusRest\Erros;

/**
 * O Protheus respondeu, com status 4xx ou 5xx.
 *
 * O guia de API da TOTVS separa 4xx (erro de negócio ou da requisição) de 5xx (erro não
 * esperado do servidor); o status fica exposto para quem chama decidir o que fazer.
 */
class ErroDaApi extends ErroDoProtheus
{
    /**
     * @param  string|null  $retryAfter  o cabeçalho Retry-After, como veio, quando veio
     */
    final public function __construct(
        string $mensagem,
        public readonly int $status,
        public readonly ?MensagemDeErro $mensagemDeErro = null,
        public readonly ?string $retryAfter = null,
    ) {
        parent::__construct($mensagem, $status);
    }

    public static function daResposta(string $metodo, string $url, int $status, string $corpo, ?string $retryAfter = null): static
    {
        $mensagemDeErro = MensagemDeErro::doCorpo($corpo);

        // Só o caminho, sem a query: filtro de consulta pode carregar dado pessoal e a
        // renovação do token vai na query. Mensagem de erro acaba em log.
        $texto = sprintf('HTTP %d em %s %s', $status, $metodo, parse_url($url, PHP_URL_PATH) ?: '/');
        if ($mensagemDeErro !== null) {
            $texto .= sprintf(': [%s] %s', $mensagemDeErro->code, $mensagemDeErro->message);
        }

        return new static($texto, $status, $mensagemDeErro, $retryAfter);
    }
}
