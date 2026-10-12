<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Portas\RepositorioEvento;
use App\Aplicacao\Portas\RepositorioExcecao;
use App\Aplicacao\Portas\RepositorioFato;
use App\Aplicacao\Portas\RepositorioRegras;
use App\Dominio\Fechamento\Caso;

/**
 * Acompanhamento depois do protocolo (§10.3). Nunca transiciona nem reabre o
 * caso; vira exceção com dono ou evento. Vale para caso aberto ou encerrado.
 *
 * Só se aplica quando o fato foi gravado (novo/nova_versao) E o E11 do caso já
 * estava em 'disponibilizado' ANTES desta publicação.
 */
final readonly class Acompanhar
{
    public function __construct(
        private RepositorioEntregavel $entregaveis,
        private RepositorioExcecao $excecoes,
        private RepositorioEvento $eventos,
        private RepositorioFato $fatos,
        private RepositorioRegras $regras,
    ) {}

    public function aplicar(Caso $caso, string $tipoFato, string $tributoFato, bool $novaVersao, string $guiaFatoId): void
    {
        $regra = $this->regras->entregaveisPorId($caso->regraEntregaveisVersaoId);
        $item = $regra->item('E11');
        if ($item === null || $item->acompanhamento === null || $item->posValidacao === null) {
            return;
        }
        $e11 = $this->entregaveis->porCasoTipo($caso->id, 'E11');
        if ($e11 === null) {
            return;
        }

        $tributoGuia = Tributos::guia($caso);
        if ($tributoFato !== $tributoGuia) {
            return; // o acompanhamento usa o tributo da guia de entrada do E11
        }

        $acomp = $item->acompanhamento;

        // Reenvio: nova versão do fato de protocolo com o E11 já disponibilizado.
        if ($tipoFato === $item->posValidacao->disponibilizadoQuandoTipo && $novaVersao) {
            $this->eventos->gravar('documento.disponibilizado', $caso->id, $e11->id, ['entregavel_id' => $e11->id, 'fato_id' => $guiaFatoId]);
            $this->excecoes->resolverPorTipo($e11->id, 'entrega_falhou', 'reenviado');

            return;
        }

        if ($tipoFato === $acomp->falhaTipo) {
            $excId = $this->excecoes->abrirUnica($caso->id, $e11->id, 'entrega_falhou', 'O', $caso->carteira);
            if ($excId !== null) {
                $this->eventos->gravar('excecao.aberta', $caso->id, $e11->id, ['excecao_id' => $excId]);
            }

            return;
        }

        if ($tipoFato === $acomp->confirmacaoTipo) {
            $this->eventos->gravar('recebimento.confirmado', $caso->id, $e11->id, ['entregavel_id' => $e11->id]);
            $this->excecoes->resolverPorTipo($e11->id, 'entrega_falhou', 'recebido');
            $this->excecoes->resolverPorTipo($e11->id, 'recebimento_pendente', 'recebido');

            return;
        }

        if ($tipoFato === $acomp->pendenciaTipo) {
            // Só abre se não existe fato vigente entrega_confirmada com a mesma chave.
            $confirmada = $this->fatos->vigente($caso->titularId, $caso->competencia->valor, $acomp->confirmacaoTipo ?? '', $tributoFato);
            if ($confirmada === null) {
                $excId = $this->excecoes->abrirUnica($caso->id, $e11->id, 'recebimento_pendente', 'O', $caso->carteira);
                if ($excId !== null) {
                    $this->eventos->gravar('excecao.aberta', $caso->id, $e11->id, ['excecao_id' => $excId]);
                }
            }
        }
    }
}
