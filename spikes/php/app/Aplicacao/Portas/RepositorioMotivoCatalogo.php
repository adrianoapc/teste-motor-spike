<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

/**
 * Catálogo de motivos (trabalho.motivo_catalogo, V010 / §18.5). Lê se um código existe para um
 * dado `aplica_a` ('override' | 'impedimento' | 'fato_manual') e se ele exige texto.
 */
interface RepositorioMotivoCatalogo
{
    /** O código existe no catálogo com o `aplica_a` dado? */
    public function existe(string $codigo, string $aplicaA): bool;

    /** O código exige texto? (Só faz sentido quando existe(); false quando não existe.) */
    public function exigeTexto(string $codigo, string $aplicaA): bool;
}
