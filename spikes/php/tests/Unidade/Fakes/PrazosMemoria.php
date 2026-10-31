<?php

declare(strict_types=1);

namespace Tests\Unidade\Fakes;

use App\Aplicacao\Portas\RepositorioPrazo;
use App\Dominio\Prazos\Prazo;

final class PrazosMemoria implements RepositorioPrazo
{
    /** @var list<array{entregavel_id: string, caso_id: string, prazo: Prazo, motivo: string}> */
    public array $gravados = [];

    /**
     * @param  array{calendario: string, catalogo: string, prazos: string}  $versoes
     */
    public function gravar(string $entregavelId, string $casoId, Prazo $prazo, string $motivo, array $versoes): void
    {
        $this->gravados[] = ['entregavel_id' => $entregavelId, 'caso_id' => $casoId, 'prazo' => $prazo, 'motivo' => $motivo];
    }

    /**
     * @return array{vencimento: ?string, meta: ?string, acao: ?string}
     */
    public function datasAtuais(string $entregavelId): array
    {
        foreach (array_reverse($this->gravados) as $g) {
            if ($g['entregavel_id'] === $entregavelId) {
                return ['vencimento' => $g['prazo']->vencimento->iso(), 'meta' => $g['prazo']->meta->iso(), 'acao' => $g['prazo']->acao->iso()];
            }
        }

        return ['vencimento' => null, 'meta' => null, 'acao' => null];
    }
}
