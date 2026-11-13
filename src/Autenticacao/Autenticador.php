<?php

namespace Canhao43\ProtheusRest\Autenticacao;

use Canhao43\ProtheusRest\Credenciais;
use Canhao43\ProtheusRest\Erros\ErroDaApi;
use Canhao43\ProtheusRest\Erros\ErroDeAutenticacao;
use Canhao43\ProtheusRest\Http\Transporte;
use Psr\Clock\ClockInterface;

/**
 * Cuida do ciclo de vida do token: login, renovação antes de vencer e troca quando o
 * servidor recusa. Regras em docs/decisoes/0001 e 0002.
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

    private const CAMINHO = 'api/oauth2/v1/token';

    private ?Token $token = null;

    public function __construct(
        private readonly Transporte $transporte,
        private readonly Credenciais $credenciais,
        private readonly ClockInterface $relogio,
    ) {}

    /**
     * Um token que ainda vale além da margem: o atual, se der, ou um novo.
     */
    public function token(): Token
    {
        $limite = $this->relogio->now()->modify('+'.self::MARGEM_EM_SEGUNDOS.' seconds');

        if ($this->token !== null && $this->token->valeEm($limite)) {
            return $this->token;
        }

        return $this->renovar();
    }

    /**
     * Troca o token mesmo que ele ainda pareça válido (o servidor recusou com 401).
     * Tenta o refresh_token primeiro, para não trafegar a senha de novo; se ele for
     * recusado (vence em 24 h), faz o login com usuário e senha.
     */
    public function renovar(): Token
    {
        $refreshToken = $this->token?->refreshToken();
        $this->token = null;

        if ($refreshToken !== null) {
            try {
                return $this->token = $this->pedir(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken], []);
            } catch (ErroDeAutenticacao) {
                // Refresh vencido ou recusado: o caminho que sobra é o login completo.
            }
        }

        return $this->token = $this->pedir(
            ['grant_type' => 'password'],
            ['username' => $this->credenciais->usuario, 'password' => $this->credenciais->senha()],
        );
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
}
