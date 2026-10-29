<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

use App\Dominio\Fatos\ContratoTipoFato;

interface RepositorioContratoTipoFato
{
    /**
     * Contrato na única versão `ativa` do tipo, lido do banco agora (§6.1, §13:
     * nada em memória entre requisições — o verificador troca contratos no meio de
     * cenários). Null se o tipo não tem versão ativa.
     */
    public function ativo(string $tipo): ?ContratoTipoFato;
}
