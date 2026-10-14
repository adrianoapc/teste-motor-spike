<?php

declare(strict_types=1);

namespace Tests\Unidade\Fakes;

use App\Aplicacao\Portas\ExcecaoAberta;
use App\Aplicacao\Portas\RepositorioExcecao;

final class ExcecoesMemoria implements RepositorioExcecao
{
    /** @var array<string, array{casoId: string, entregavelId: ?string, tipo: string, classe: string, dono: string, estado: string, resolucao: ?string}> */
    public array $excecoes = [];

    public function existeAberta(string $casoId, ?string $entregavelId, string $tipo): bool
    {
        foreach ($this->excecoes as $x) {
            $mesmoAlvo = $entregavelId === null
                ? $x['casoId'] === $casoId && $x['entregavelId'] === null
                : $x['entregavelId'] === $entregavelId;
            if ($mesmoAlvo && $x['tipo'] === $tipo && $x['estado'] === 'aberta') {
                return true;
            }
        }

        return false;
    }

    public function abrir(string $casoId, ?string $entregavelId, string $tipo, string $classe, string $dono): string
    {
        $id = 'exc-'.(count($this->excecoes) + 1);
        $this->excecoes[$id] = [
            'casoId' => $casoId, 'entregavelId' => $entregavelId, 'tipo' => $tipo, 'classe' => $classe,
            'dono' => $dono, 'estado' => 'aberta', 'resolucao' => null,
        ];

        return $id;
    }

    public function abertasDoCaso(string $casoId): array
    {
        $saida = [];
        foreach ($this->excecoes as $id => $x) {
            if ($x['casoId'] === $casoId && $x['estado'] === 'aberta') {
                $saida[] = new ExcecaoAberta($id, $x['tipo'], $x['classe']);
            }
        }

        return $saida;
    }

    public function resolverCfDoEntregavel(string $entregavelId, string $resolucao): void
    {
        foreach ($this->excecoes as $id => $x) {
            if ($x['entregavelId'] === $entregavelId && $x['estado'] === 'aberta' && str_starts_with($x['tipo'], 'CF-')) {
                $this->excecoes[$id]['estado'] = 'resolvida';
                $this->excecoes[$id]['resolucao'] = $resolucao;
            }
        }
    }

    public function resolverPorTipo(string $entregavelId, string $tipo, string $resolucao): void
    {
        foreach ($this->excecoes as $id => $x) {
            if ($x['entregavelId'] === $entregavelId && $x['estado'] === 'aberta' && $x['tipo'] === $tipo) {
                $this->excecoes[$id]['estado'] = 'resolvida';
                $this->excecoes[$id]['resolucao'] = $resolucao;
            }
        }
    }

    /** @return list<string> "tipo:estado[:resolucao]" de todas as exceções, na ordem de abertura */
    public function resumo(): array
    {
        return array_values(array_map(
            static fn (array $x): string => $x['tipo'].':'.$x['estado'].($x['resolucao'] !== null ? ':'.$x['resolucao'] : ''),
            $this->excecoes,
        ));
    }
}
