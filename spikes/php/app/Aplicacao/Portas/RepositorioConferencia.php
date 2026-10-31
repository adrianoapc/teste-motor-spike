<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

interface RepositorioConferencia
{
    /**
     * Grava uma conferência se não houver outra com (cf, entregavel_id, entrada_hash).
     * Idempotente: ON CONFLICT DO NOTHING. Devolve o id vigente dessa chave.
     *
     * @param  list<array{fato_id: string, versao: int}>  $fatosUsados
     * @param  array<string, mixed>  $diferenca
     */
    public function gravarSeNova(
        string $cf,
        string $casoId,
        string $entregavelId,
        string $regraVersaoId,
        array $fatosUsados,
        string $entradaHash,
        string $resultado,
        ?int $esperadoCentavos,
        ?int $obtidoCentavos,
        array $diferenca,
        string $severidade,
        string $classe,
    ): void;

    /**
     * Conferência vigente (maior em) de uma CF num entregável.
     */
    public function vigente(string $entregavelId, string $cf): ?ConferenciaVigente;

    /** Grava override na conferência vigente da CF. */
    public function registrarOverride(string $conferenciaId, string $autor, string $motivo, string $motivoCodigo): void;

    /**
     * Conferências vigentes do entregável que são divergentes B sem override.
     *
     * @return list<DivergenciaBloqueante>
     */
    public function divergentesBloqueantes(string $entregavelId): array;

    /**
     * CFs do entregável com conferência vigente divergente B sem override.
     *
     * @return list<string>
     */
    public function cfsDivergentesBloqueantes(string $entregavelId): array;
}
