<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

use App\Dominio\Fechamento\Caso;
use App\Dominio\Fechamento\Competencia;

interface RepositorioCaso
{
    public function porTitularCompetencia(string $titularId, Competencia $competencia): ?Caso;

    /**
     * Nível 1 da ordem global de locks de escrita: advisory lock do titular
     * (pg_advisory_xact_lock, até o fim da transação; reentrante). Todo comando que escreve
     * (PublicarFato, ConcluirTarefa, RegistrarOverride, AbrirCompetencias) o toma ANTES de qualquer outro lock.
     */
    public function travarTitular(string $titularId): void;

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function criar(
        string $titularId,
        Competencia $competencia,
        array $snapshot,
        string $carteira,
        string $regraEntregaveisVersaoId,
    ): Caso;

    public function encerrar(string $casoId): void;

    /**
     * Casos abertos de um titular, em qualquer competência.
     *
     * @return list<Caso>
     */
    public function abertosDoTitular(string $titularId): array;

    public function porId(string $casoId): ?Caso;
}
