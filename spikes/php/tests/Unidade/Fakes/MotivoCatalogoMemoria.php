<?php

declare(strict_types=1);

namespace Tests\Unidade\Fakes;

use App\Aplicacao\Portas\RepositorioMotivoCatalogo;

/**
 * Catálogo de motivos em memória. Padrão: aceita qualquer código para qualquer aplica_a e não
 * exige texto, para os testes de unidade de override/impedimento não dependerem do banco. Testes
 * que precisam exercitar a recusa passam listas explícitas.
 */
final class MotivoCatalogoMemoria implements RepositorioMotivoCatalogo
{
    /**
     * @param  array<string, string>|null  $validos  codigo => aplica_a; null = aceita tudo
     * @param  list<string>  $exigemTexto  códigos que exigem texto
     */
    public function __construct(
        private readonly ?array $validos = null,
        private readonly array $exigemTexto = [],
    ) {}

    public function existe(string $codigo, string $aplicaA): bool
    {
        if ($this->validos === null) {
            return true;
        }

        return ($this->validos[$codigo] ?? null) === $aplicaA;
    }

    public function exigeTexto(string $codigo, string $aplicaA): bool
    {
        return $this->existe($codigo, $aplicaA) && in_array($codigo, $this->exigemTexto, true);
    }
}
