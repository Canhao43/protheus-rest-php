<?php

namespace Canhao43\ProtheusRest;

use Canhao43\ProtheusRest\Autenticacao\Autenticador;
use Canhao43\ProtheusRest\Autenticacao\RelogioDoSistema;
use Canhao43\ProtheusRest\Autenticacao\Token;
use Canhao43\ProtheusRest\Colecoes\Consulta;
use Canhao43\ProtheusRest\Colecoes\Pagina;
use Canhao43\ProtheusRest\Erros\ErroDaApi;
use Canhao43\ProtheusRest\Erros\ErroDeConexao;
use Canhao43\ProtheusRest\Erros\RespostaInvalida;
use Canhao43\ProtheusRest\Http\Repeticao;
use Canhao43\ProtheusRest\Http\Transporte;
use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Porta de entrada do pacote: chama a API REST do Protheus com o token já resolvido.
 *
 * Os caminhos são relativos à URL base do REST, por exemplo "api/exemplo/v1/recursos".
 */
final class ClienteProtheus
{
    private readonly Transporte $transporte;

    private readonly Autenticador $autenticador;

    private readonly ClockInterface $relogio;

    private readonly Repeticao $repeticao;

    /**
     * @param  string  $urlBase  endereço do REST, com o caminho, ex. https://servidor:8080/rest
     * @param  ClientInterface|null  $http  um cliente Guzzle próprio (proxy, certificado, timeouts)
     * @param  ClockInterface|null  $relogio  relógio PSR-20; em teste, um relógio parado
     * @param  CacheInterface|null  $cache  cache PSR-16 para o token valer entre processos; guarda tokens
     * @param  Repeticao|null  $repeticao  nova tentativa de leitura em falha passageira; padrão: 3 tentativas
     */
    public function __construct(
        string $urlBase,
        Credenciais $credenciais,
        ?ClientInterface $http = null,
        ?ClockInterface $relogio = null,
        ?CacheInterface $cache = null,
        ?Repeticao $repeticao = null,
    ) {
        $this->relogio = $relogio ?? new RelogioDoSistema;
        $this->repeticao = $repeticao ?? new Repeticao;

        // Sem timeout o Guzzle espera para sempre; um ERP travado não pode travar quem chama.
        $this->transporte = new Transporte($http ?? new Client(['connect_timeout' => 10, 'timeout' => 30]), $urlBase);

        // Um token por servidor e usuário, e nunca a senha na chave. Ver docs/decisoes/0009.
        $chave = 'protheus_rest.token.'.hash('sha256', rtrim($urlBase, '/')."\n".$credenciais->usuario);
        $this->autenticador = new Autenticador($this->transporte, $credenciais, $this->relogio, $cache, $chave);
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
     * Uma página de uma coleção. Sem tamanho, vale o padrão do endpoint, como o guia manda.
     */
    public function pagina(string $caminho, int $numero = 1, ?int $tamanho = null, ?Consulta $consulta = null): Pagina
    {
        if ($numero < 1 || ($tamanho !== null && $tamanho < 1)) {
            throw new InvalidArgumentException('Número e tamanho de página começam em 1.');
        }

        $query = ($consulta ?? new Consulta)->paraQuery() + ['page' => $numero];
        if ($tamanho !== null) {
            $query['pageSize'] = $tamanho;
        }

        return Pagina::daResposta($numero, $this->get($caminho, $query), $caminho);
    }

    /**
     * Todos os itens de uma coleção, página por página, até "hasNext" ser falso.
     *
     * Só pede a próxima página quando quem chama chega ao fim da atual: parar no meio do
     * foreach não busca o resto. Ver docs/decisoes/0005.
     *
     * @return Generator<int, mixed>
     */
    public function colecao(string $caminho, ?Consulta $consulta = null, ?int $tamanhoDaPagina = null): Generator
    {
        // Conferido aqui, fora do gerador: o código de um gerador só roda no primeiro foreach,
        // e o erro de quem chamou apareceria longe da chamada.
        if ($tamanhoDaPagina !== null && $tamanhoDaPagina < 1) {
            throw new InvalidArgumentException('Número e tamanho de página começam em 1.');
        }

        return $this->percorrer($caminho, $consulta, $tamanhoDaPagina);
    }

    /**
     * @return Generator<int, mixed>
     */
    private function percorrer(string $caminho, ?Consulta $consulta, ?int $tamanhoDaPagina): Generator
    {
        $anterior = null;
        $numero = 1;

        do {
            $pagina = $this->pagina($caminho, $numero, $tamanhoDaPagina, $consulta);

            // Duas defesas contra laço infinito com um servidor fora do guia. Ver docs/decisoes/0006.
            if ($pagina->hasNext && $pagina->items === []) {
                throw new RespostaInvalida("A página {$numero} de {$caminho} veio vazia, mas diz que há próxima.");
            }
            if ($anterior !== null && $pagina->items !== [] && $pagina->items === $anterior->items) {
                throw new RespostaInvalida("A página {$numero} de {$caminho} repetiu a anterior: o parâmetro page foi ignorado?");
            }

            // Item por item, e não "yield from": as chaves de cada página recomeçam em 0, e um
            // iterator_to_array() sobrescreveria a página anterior.
            foreach ($pagina->items as $item) {
                yield $item;
            }

            $anterior = $pagina;
            $numero++;
        } while ($pagina->hasNext);
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
            return $this->tentar($metodo, $caminho, $this->comToken($opcoes, $token));
        } catch (ErroDaApi $erro) {
            if ($erro->status !== 401) {
                throw $erro;
            }
        }

        // O TDN diz que token vencido volta 401 "como se não houvesse sido enviado um token".
        // Um 401 também quer dizer que nada foi processado, então repetir uma vez é seguro
        // até num POST. Se o token novo também for recusado, o 401 sobe. Ver docs/decisoes/0002.
        return $this->tentar($metodo, $caminho, $this->comToken($opcoes, $this->autenticador->renovar()));
    }

    /**
     * Manda a chamada e, se a falha for passageira, espera e manda de novo. Quem decide se
     * repete e quanto espera é a Repeticao. Ver docs/decisoes/0008.
     *
     * @param  array<string, mixed>  $opcoes
     * @return array<mixed>
     */
    private function tentar(string $metodo, string $caminho, array $opcoes): array
    {
        for ($tentativa = 1; ; $tentativa++) {
            try {
                return $this->transporte->enviar($metodo, $caminho, $opcoes);
            } catch (ErroDeConexao|ErroDaApi $erro) {
                $espera = $this->repeticao->esperaDepoisDe($metodo, $erro, $tentativa, $this->relogio->now());
                if ($espera === null) {
                    throw $erro;
                }

                $this->repeticao->dormir($espera);
            }
        }
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
