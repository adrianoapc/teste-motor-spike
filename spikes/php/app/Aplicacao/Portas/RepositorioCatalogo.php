<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

interface RepositorioCatalogo
{
    /** Área (fechamento.entregavel_tipo.area) e evento publicado do tipo de entregável. */
    public function entregavelTipo(string $tipo): CatalogoEntregavel;

    /** Dados do catálogo de uma conferência (severidade, classe_padrao). */
    public function conferenciaTipo(string $cf): CatalogoConferencia;
}
