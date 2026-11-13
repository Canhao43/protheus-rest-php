<?php

namespace Canhao43\ProtheusRest;

use Canhao43\ProtheusRest\Autenticacao\Autenticador;
use Canhao43\ProtheusRest\Autenticacao\RelogioDoSistema;
use Canhao43\ProtheusRest\Autenticacao\Token;
use Canhao43\ProtheusRest\Erros\ErroDaApi;
use Canhao43\ProtheusRest\Http\Transporte;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Clock\ClockInterface;

/**
 * Porta de entrada do pacote: chama a API REST do Protheus com o token já resolvido.
 *
 * Os caminhos são relativos à URL base do REST, por exemplo "api/exemplo/v1/recursos".
 */
final class ClienteProtheus
{
    private readonly Transporte $transporte;

    private readonly Autenticador $autenticador;

    /**
     * @param  string  $urlBase  endereço do REST, com o caminho, ex. https://servidor:8080/rest
     * @param  ClientInterface|null  $http  um cliente Guzzle próprio (proxy, certificado, timeouts)
     * @param  ClockInterface|null  $relogio  relógio PSR-20; em teste, um relógio parado
     */
    public function __construct(
        string $urlBase,
        Credenciais $credenciais,
        ?ClientInterface $http = null,
        ?ClockInterface $relogio = null,
    ) {
        // Sem timeout o Guzzle espera para sempre; um ERP travado não pode travar quem chama.
        $this->transporte = new Transporte($http ?? new Client(['connect_timeout' => 10, 'timeout' => 30]), $urlBase);
        $this->autenticador = new Autenticador($this->transporte, $credenciais, $relogio ?? new RelogioDoSistema);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function get(string $caminho, array $query = []): array
    {
        return $this->enviar('GET', $caminho, ['query' => $query]);
    }

    /**
     * @param  array<mixed>|null  $corpo  vira JSON; null manda sem corpo
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function post(string $caminho, ?array $corpo = null, array $query = []): array
    {
        return $this->enviar('POST', $caminho, $this->comCorpo($corpo, $query));
    }

    /**
     * @param  array<mixed>|null  $corpo
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function put(string $caminho, ?array $corpo = null, array $query = []): array
    {
        return $this->enviar('PUT', $caminho, $this->comCorpo($corpo, $query));
    }

    /**
     * @param  array<mixed>|null  $corpo
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function patch(string $caminho, ?array $corpo = null, array $query = []): array
    {
        return $this->enviar('PATCH', $caminho, $this->comCorpo($corpo, $query));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function delete(string $caminho, array $query = []): array
    {
        return $this->enviar('DELETE', $caminho, ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>  $opcoes
     * @return array<mixed>
     */
    private function enviar(string $metodo, string $caminho, array $opcoes): array
    {
        // O token sai fora do try: se o próprio login falhar, não é caso de tentar de novo.
        $token = $this->autenticador->token();

        try {
            return $this->transporte->enviar($metodo, $caminho, $this->comToken($opcoes, $token));
        } catch (ErroDaApi $erro) {
            if ($erro->status !== 401) {
                throw $erro;
            }
        }

        // O TDN diz que token vencido volta 401 "como se não houvesse sido enviado um token".
        // Um 401 também quer dizer que nada foi processado, então repetir uma vez é seguro
        // até num POST. Se o token novo também for recusado, o 401 sobe. Ver docs/decisoes/0002.
        return $this->transporte->enviar($metodo, $caminho, $this->comToken($opcoes, $this->autenticador->renovar()));
    }

    /**
     * @param  array<string, mixed>  $opcoes
     * @return array<string, mixed>
     */
    private function comToken(array $opcoes, Token $token): array
    {
        $opcoes['headers'] = ['Authorization' => 'Bearer '.$token->accessToken()];

        return $opcoes;
    }

    /**
     * @param  array<mixed>|null  $corpo
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function comCorpo(?array $corpo, array $query): array
    {
        return $corpo === null ? ['query' => $query] : ['query' => $query, 'json' => $corpo];
    }
}
