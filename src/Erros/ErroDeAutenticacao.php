<?php

namespace Canhao43\ProtheusRest\Erros;

/**
 * O endpoint de token recusou o pedido (4xx): usuário ou senha errados, ou refresh_token vencido.
 */
final class ErroDeAutenticacao extends ErroDaApi
{
    public static function de(ErroDaApi $erro): self
    {
        return new self('Login recusado pelo Protheus. '.$erro->getMessage(), $erro->status, $erro->mensagemDeErro);
    }
}
