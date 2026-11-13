<?php

namespace Canhao43\ProtheusRest\Erros;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Não houve resposta: servidor fora, DNS, certificado, tempo esgotado.
 */
final class ErroDeConexao extends ErroDoProtheus
{
    public static function em(string $metodo, string $url, GuzzleException $erro): self
    {
        // A mensagem do Guzzle termina com a URL inteira, e a renovação do token manda o
        // refresh_token na query (é assim que o TDN define). Por isso o motivo vem do
        // contexto do cURL, a URL vai sem query e a exceção original não é encadeada:
        // encadear levaria o token junto para o log. Ver docs/decisoes/0003.
        $motivo = $erro instanceof ConnectException || $erro instanceof RequestException
            ? (string) ($erro->getHandlerContext()['error'] ?? '')
            : '';

        $partes = parse_url($url) ?: [];
        $endereco = ($partes['scheme'] ?? '').'://'.($partes['host'] ?? '')
            .(isset($partes['port']) ? ':'.$partes['port'] : '')
            .($partes['path'] ?? '');

        return new self(sprintf(
            'Sem resposta do Protheus em %s %s%s',
            $metodo,
            $endereco,
            $motivo !== '' ? ": {$motivo}" : '.',
        ));
    }
}
