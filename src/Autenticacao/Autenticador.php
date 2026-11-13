<?php

namespace Canhao43\ProtheusRest\Autenticacao;

use Canhao43\ProtheusRest\Credenciais;
use Canhao43\ProtheusRest\Erros\ErroDaApi;
use Canhao43\ProtheusRest\Erros\ErroDeAutenticacao;
use Canhao43\ProtheusRest\Http\Transporte;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheException;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * Cuida do ciclo de vida do token: login, renovação antes de vencer, troca quando o
 * servidor recusa e, se houver cache, o token compartilhado entre processos.
 * Regras em docs/decisoes/0001, 0002 e 0009.
 *
 * Fonte: TDN, "API para obtenção do token de acesso às API's REST no Protheus"
 * (https://tdn.totvs.com/pages/releaseview.action?pageId=465383509).
 *
 * @internal
 */
final class Autenticador
{
    /**
     * Renova um minuto antes do fim: cobre a diferença de relógio com o servidor e o tempo
     * da própria requisição, para o token não vencer no caminho.
     */
    public const MARGEM_EM_SEGUNDOS = 60;

    /**
     * Quanto o token fica no cache. O TDN dá 24 h ao refresh_token; depois disso a entrada
     * não serve mais para nada. É só faxina: se o refresh vencer antes, o login cobre.
     */
    public const VALIDADE_NO_CACHE_EM_SEGUNDOS = 86400;

    private const CAMINHO = 'api/oauth2/v1/token';

    private ?Token $token = null;

    private bool $cacheLido = false;

    public function __construct(
        private readonly Transporte $transporte,
        private readonly Credenciais $credenciais,
        private readonly ClockInterface $relogio,
        private readonly ?CacheInterface $cache = null,
        private readonly string $chaveDoCache = '',
    ) {}

    /**
     * Um token que ainda vale além da margem: o atual, se der, ou um novo.
     */
    public function token(): Token
    {
        if (! $this->cacheLido) {
            $this->cacheLido = true;
            $this->token = $this->lerDoCache();
        }

        $limite = $this->relogio->now()->modify('+'.self::MARGEM_EM_SEGUNDOS.' seconds');

        if ($this->token !== null && $this->token->valeEm($limite)) {
            return $this->token;
        }

        return $this->renovar();
    }

    /**
     * Troca o token mesmo que ele ainda pareça válido (o servidor recusou com 401).
     * Tenta o refresh_token primeiro, para não trafegar a senha de novo; se ele for
     * recusado (vence em 24 h, ou outro processo já o usou), faz o login com usuário e senha.
     */
    public function renovar(): Token
    {
        $refreshToken = $this->token?->refreshToken();
        $this->token = null;

        if ($refreshToken !== null) {
            try {
                return $this->guardar($this->pedir(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken], []));
            } catch (ErroDeAutenticacao) {
                // Refresh vencido ou recusado: o caminho que sobra é o login completo.
            }
        }

        return $this->guardar($this->pedir(
            ['grant_type' => 'password'],
            ['username' => $this->credenciais->usuario, 'password' => $this->credenciais->senha()],
        ));
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $cabecalhos
     */
    private function pedir(array $query, array $cabecalhos): Token
    {
        try {
            $corpo = $this->transporte->enviar('POST', self::CAMINHO, ['query' => $query, 'headers' => $cabecalhos]);
        } catch (ErroDaApi $erro) {
            // 4xx no login é recusa do pedido; 5xx é problema do servidor e segue como está.
            throw $erro->status < 500 ? ErroDeAutenticacao::de($erro) : $erro;
        }

        return Token::daResposta($corpo, $this->relogio->now());
    }

    private function guardar(Token $token): Token
    {
        $this->token = $token;

        if ($this->cache !== null) {
            try {
                $this->cache->set($this->chaveDoCache, $token->paraCache(), self::VALIDADE_NO_CACHE_EM_SEGUNDOS);
            } catch (CacheException|RuntimeException) {
                // Cache fora do ar custa um login a mais no próximo processo, não esta chamada.
            }
        }

        return $token;
    }

    private function lerDoCache(): ?Token
    {
        if ($this->cache === null) {
            return null;
        }

        try {
            return Token::doCache($this->cache->get($this->chaveDoCache));
        } catch (CacheException|RuntimeException) {
            return null;
        }
    }
}
