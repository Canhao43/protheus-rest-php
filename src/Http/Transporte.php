<?php

namespace Canhao43\ProtheusRest\Http;

use Canhao43\ProtheusRest\Erros\ErroDaApi;
use Canhao43\ProtheusRest\Erros\ErroDeConexao;
use Canhao43\ProtheusRest\Erros\RespostaInvalida;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Manda a requisição e traduz o que volta: JSON vira array, 4xx/5xx vira ErroDaApi,
 * falha de rede vira ErroDeConexao. Login e chamadas de API passam por aqui.
 *
 * @internal
 */
final class Transporte
{
    private readonly string $urlBase;

    public function __construct(
        private readonly ClientInterface $http,
        string $urlBase,
    ) {
        // A URL base do REST do Protheus costuma ter caminho (https://servidor:8080/rest).
        // A URL final é montada aqui, por concatenação: com base_uri do Guzzle, um caminho
        // relativo descartaria o "/rest" se a base não terminasse em barra (RFC 3986).
        $this->urlBase = rtrim($urlBase, '/').'/';
    }

    /**
     * @param  array<string, mixed>  $opcoes  opções de requisição do Guzzle (query, headers, json)
     * @return array<mixed>
     */
    public function enviar(string $metodo, string $caminho, array $opcoes = []): array
    {
        $url = $this->urlBase.ltrim($caminho, '/');

        /** @var array<string, string> $cabecalhos */
        $cabecalhos = $opcoes['headers'] ?? [];
        $opcoes['headers'] = ['Accept' => 'application/json'] + $cabecalhos;
        // Status de erro é tratado abaixo, com o corpo no padrão TOTVS; não como exceção do Guzzle.
        $opcoes['http_errors'] = false;

        try {
            $resposta = $this->http->request($metodo, $url, $opcoes);
        } catch (GuzzleException $erro) {
            throw ErroDeConexao::em($metodo, $url, $erro);
        }

        $status = $resposta->getStatusCode();
        $corpo = (string) $resposta->getBody();

        if ($status >= 400) {
            $retryAfter = $resposta->getHeaderLine('Retry-After');
            throw ErroDaApi::daResposta($metodo, $url, $status, $corpo, $retryAfter !== '' ? $retryAfter : null);
        }

        // 204 do DELETE vem sem corpo, como o guia manda.
        if (trim($corpo) === '') {
            return [];
        }

        $dados = json_decode($corpo, true);
        if (! is_array($dados)) {
            throw new RespostaInvalida(sprintf(
                'HTTP %d em %s %s, mas o corpo não é um objeto JSON.',
                $status,
                $metodo,
                parse_url($url, PHP_URL_PATH) ?: '/',
            ));
        }

        return $dados;
    }
}
