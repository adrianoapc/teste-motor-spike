<?php

declare(strict_types=1);

namespace Tests\Unidade\Fakes;

use App\Aplicacao\Portas\RepositorioFato;
use App\Dominio\Fatos\Fato;

final class FatosMemoria implements RepositorioFato
{
    /** @var list<Fato> todas as versões, na ordem de inserção */
    public array $versoes = [];

    /** @var array<string, string> id do fato => observado_em gravado */
    public array $observadoEm = [];

    /** @var list<string> chaves travadas ("tipo|tributo"), na ordem */
    public array $travas = [];

    public function travarChave(string $titularId, string $competencia, string $tipo, string $tributo): void
    {
        $this->travas[] = "{$tipo}|{$tributo}";
    }

    public function vigente(string $titularId, string $competencia, string $tipo, string $tributo): ?Fato
    {
        $vigente = null;
        foreach ($this->versoes as $f) {
            if ($f->titularId === $titularId && $f->competencia === $competencia && $f->tipo === $tipo && $f->tributo === $tributo
                && ($vigente === null || $f->versao > $vigente->versao)) {
                $vigente = $f;
            }
        }

        return $vigente;
    }

    public function porId(string $fatoId): ?Fato
    {
        foreach ($this->versoes as $f) {
            if ($f->id === $fatoId) {
                return $f;
            }
        }

        return null;
    }

    public function inserir(
        string $titularId,
        string $competencia,
        string $tipo,
        string $tributo,
        int $versao,
        ?int $valorCentavos,
        mixed $payload,
        string $fonte,
        ?string $origemRef,
        string $hash,
        ?string $substituiId,
        string $observadoEm,
        int $tipoVersao = 1,
    ): Fato {
        $fato = new Fato(
            'fato-'.(count($this->versoes) + 1), $titularId, $competencia, $tipo, $tributo, $versao,
            $valorCentavos, $payload, $hash, $fonte, $observadoEm, $tipoVersao,
        );
        $this->versoes[] = $fato;
        $this->observadoEm[$fato->id] = $observadoEm;

        return $fato;
    }

    public function vigentesDoCaso(string $titularId, string $competencia): array
    {
        $porChave = [];
        foreach ($this->versoes as $f) {
            if ($f->titularId === $titularId && $f->competencia === $competencia) {
                $chave = $f->tipo.'|'.$f->tributo;
                if (! isset($porChave[$chave]) || $f->versao > $porChave[$chave]->versao) {
                    $porChave[$chave] = $f;
                }
            }
        }

        return array_values($porChave);
    }
}
