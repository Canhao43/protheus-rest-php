<?php

namespace Canhao43\ProtheusRest\Autenticacao;

use Canhao43\ProtheusRest\Erros\RespostaInvalida;
use DateTimeImmutable;

/**
 * O par de tokens que o endpoint /api/oauth2/v1/token devolve.
 *
 * Formato da resposta: TDN, "API para obtenção do token de acesso às API's REST no Protheus"
 * (https://tdn.totvs.com/pages/releaseview.action?pageId=465383509).
 */
final class Token
{
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $accessToken,
        #[\SensitiveParameter]
        private readonly ?string $refreshToken,
        public readonly DateTimeImmutable $expiraEm,
        public readonly bool $hasMFA = false,
    ) {}

    /**
     * @param  array<mixed>  $corpo  JSON da resposta já decodificado
     */
    public static function daResposta(array $corpo, DateTimeImmutable $recebidoEm): self
    {
        $accessToken = $corpo['access_token'] ?? null;
        $expiresIn = $corpo['expires_in'] ?? null;

        // Sem esses dois não há como autenticar nem saber quando renovar: melhor parar aqui
        // do que mandar "Bearer " vazio e receber um 401 que esconde a causa.
        if (! is_string($accessToken) || $accessToken === '' || ! is_int($expiresIn) || $expiresIn <= 0) {
            throw new RespostaInvalida('A resposta do login não trouxe access_token e expires_in válidos.');
        }

        $refreshToken = $corpo['refresh_token'] ?? null;

        return new self(
            $accessToken,
            is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            $recebidoEm->modify("+{$expiresIn} seconds"),
            // hasMFA existe a partir do release 20241007; nas versões anteriores não vem.
            ($corpo['hasMFA'] ?? false) === true,
        );
    }

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function refreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function valeEm(DateTimeImmutable $momento): bool
    {
        return $momento < $this->expiraEm;
    }

    /**
     * Só tipos simples: o cache pode ser Redis, arquivo ou banco, e objeto serializado
     * quebraria ao mudar a classe.
     *
     * @return array{access_token: string, refresh_token: string|null, expira_em: int, hasMFA: bool}
     */
    public function paraCache(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expira_em' => $this->expiraEm->getTimestamp(),
            'hasMFA' => $this->hasMFA,
        ];
    }

    /**
     * O que veio do cache, ou null se não for um token que este pacote gravou.
     */
    public static function doCache(mixed $dados): ?self
    {
        if (! is_array($dados)
            || ! is_string($dados['access_token'] ?? null) || $dados['access_token'] === ''
            || ! is_int($dados['expira_em'] ?? null)
            || ! (is_string($dados['refresh_token'] ?? null) || ($dados['refresh_token'] ?? null) === null)
        ) {
            return null;
        }

        return new self(
            $dados['access_token'],
            $dados['refresh_token'],
            (new DateTimeImmutable)->setTimestamp($dados['expira_em']),
            ($dados['hasMFA'] ?? false) === true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'accessToken' => '***',
            'refreshToken' => $this->refreshToken === null ? null : '***',
            'expiraEm' => $this->expiraEm,
            'hasMFA' => $this->hasMFA,
        ];
    }
}
