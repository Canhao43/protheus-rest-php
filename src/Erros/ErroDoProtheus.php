<?php

namespace Canhao43\ProtheusRest\Erros;

use RuntimeException;

/**
 * Base de todo erro do pacote: um catch só pega qualquer falha vinda do Protheus.
 */
abstract class ErroDoProtheus extends RuntimeException {}
