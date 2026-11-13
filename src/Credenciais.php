<?php

namespace Canhao43\ProtheusRest;

/**
 * Usuário e senha do Protheus. A senha só sai daqui para os cabeçalhos do login
 * (ver docs/decisoes/0001), e nunca aparece num var_dump, print_r ou rastro de erro.
 */
final class Credenciais
{
    public function __construct(
        public readonly string $usuario,
        #[\SensitiveParameter]
        private readonly string $senha,
    ) {}

    /**
     * @internal Só o Autenticador lê a senha.
     */
    public function senha(): string
    {
        return $this->senha;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['usuario' => $this->usuario, 'senha' => '***'];
    }
}
