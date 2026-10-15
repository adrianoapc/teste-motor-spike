<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Aplicacao\Conferencias\Conferidor;
use App\Aplicacao\Conferencias\FatosDoCaso;
use App\Aplicacao\Conferencias\Formulas;
use App\Aplicacao\Conferencias\ParametrosDeConferencia;
use App\Aplicacao\Portas\RepositorioCaso;
use App\Aplicacao\Portas\RepositorioConferencia;
use App\Aplicacao\Portas\RepositorioDependencia;
use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Portas\RepositorioEvento;
use App\Aplicacao\Portas\RepositorioFato;
use App\Aplicacao\Portas\RepositorioRegras;
use App\Aplicacao\Portas\RepositorioTarefa;
use App\Dominio\Fechamento\Caso;
use App\Dominio\Fechamento\Condicao;
use App\Dominio\Fechamento\Entregavel;
use App\Dominio\Fechamento\EstadoEntregavel;
use App\Dominio\Regras\ItemEntregavel;

/**
 * Reavaliação de um caso (§7.4): em voltas, cada entregável dá no máximo um
 * passo por volta, em ordem de tipo, lendo o estado atual das dependências.
 * Repete até nenhuma mudança.
 */
final readonly class AvaliarCaso
{
    public function __construct(
        private RepositorioCaso $casos,
        private RepositorioEntregavel $entregaveis,
        private RepositorioDependencia $dependencias,
        private RepositorioFato $fatos,
        private RepositorioTarefa $tarefas,
        private RepositorioEvento $eventos,
        private RepositorioConferencia $conferencias,
        private Excecoes $excecoes,
        private RepositorioRegras $regras,
        private Transicionador $transicionador,
        private Conferidor $conferidor,
    ) {}

    /** Reavalia todos os casos abertos do titular, em ordem de competência (§6.2, §7.4: superconjunto seguro). */
    public function avaliarAbertosDoTitular(string $titularId): void
    {
        foreach ($this->casos->abertosDoTitular($titularId) as $caso) {
            $this->avaliar($caso);
        }
    }

    public function avaliar(Caso $caso): void
    {
        $regra = $this->regras->entregaveisPorId($caso->regraEntregaveisVersaoId);
        $parametros = $this->parametrosDe($caso);

        $encerrou = false;
        do {
            $mudou = false;
            // Trava e percorre em ordem de tipo (E01, E02, …).
            $lista = $this->entregaveis->doCasoParaAtualizar($caso->id);
            foreach ($lista as $e) {
                $item = $regra->item($e->tipo);
                if ($item === null) {
                    continue;
                }
                $passo = $this->passo($caso, $e, $item, $parametros);
                if ($passo === 'encerrou') {
                    $encerrou = true;
                    $mudou = true;
                    break;
                }
                if ($passo === 'mudou') {
                    $mudou = true;
                }
            }
        } while ($mudou && ! $encerrou);
    }

    /** Tolerância, parâmetros fiscais e tributo da guia em uso para as conferências do caso. */
    public function parametrosDe(Caso $caso): ParametrosDeConferencia
    {
        return new ParametrosDeConferencia(
            new Formulas($this->regras->parametrosEmUso()->prolaborePercentualBp),
            $this->regras->toleranciaEmUso(),
            Tributos::guia($caso),
        );
    }

    /**
     * Roda conferências e aplica o desfecho (§9.3): divergente (B) ou validado + evento.
     */
    public function conferirEDecidir(Caso $caso, Entregavel $eProcessado, ItemEntregavel $item, ParametrosDeConferencia $parametros): void
    {
        $regraVersaoId = $caso->regraEntregaveisVersaoId;
        // Folha da competência anterior (CF-09 IRRF, v4 §9.1): o IRRF retido na folha de M-1
        // é declarado na DCTFWeb de M. Entrada declarada do E08 com competencia_relativa: -1 na regra v3.
        $folhaMesAnterior = $this->fatos->vigente($caso->titularId, $caso->competencia->somar(-1)->valor, 'folha', '');
        $fatosDoCaso = new FatosDoCaso($this->fatos->vigentesDoCaso($caso->titularId, $caso->competencia->valor), $folhaMesAnterior);
        $divergiu = $this->conferidor->conferir($caso, $eProcessado, $item, $fatosDoCaso, $parametros);

        if ($divergiu) {
            $divergente = $this->transicionador->transicionar($eProcessado, EstadoEntregavel::Divergente, 'conferencia_divergente', 'sistema', $regraVersaoId, 'conferencia');
            // Abre exceção por CF bloqueante divergente (§9.3, §9.4 abertura única).
            foreach ($this->conferencias->divergentesBloqueantes($divergente->id) as $div) {
                $this->eventos->gravar('conferencia.divergente', $caso->id, $divergente->id, ['conferencia_id' => $div->id]);
                // Classe gravada na conferência (S quando faltou dado de fórmula), não a do catálogo.
                $this->excecoes->abrirUnica($caso->id, $divergente->id, $div->cf, $div->classe, $caso->carteira);
            }

            return;
        }

        // → validado + evento do tipo (se houver).
        $this->transicionador->validar($eProcessado, 'conferencias_ok', 'sistema', $regraVersaoId, 'conferencia', null);
    }

    /**
     * Um passo (§7.4). Devolve 'mudou', 'nada' ou 'encerrou'.
     *
     * @return 'mudou'|'nada'|'encerrou'
     */
    private function passo(Caso $caso, Entregavel $e, ItemEntregavel $item, ParametrosDeConferencia $parametros): string
    {
        return match ($e->estado) {
            EstadoEntregavel::AguardandoInsumo, EstadoEntregavel::Invalidado => $this->passoProntidao($caso, $e, $item),
            EstadoEntregavel::Pronto => $e->sistema() ? $this->passoProcessar($caso, $e, $item, $parametros) : 'nada',
            EstadoEntregavel::Validado => $this->passoDepoisDeValidado($caso, $e, $item),
            EstadoEntregavel::Liberado => $this->passoDisponibilizar($caso, $e, $item, $parametros->tributoGuia),
            default => 'nada',
        };
    }

    /** @return 'mudou'|'nada' */
    private function passoProntidao(Caso $caso, Entregavel $e, ItemEntregavel $item): string
    {
        $regraVersaoId = $caso->regraEntregaveisVersaoId;

        if ($this->prontoPodeAvancar($caso, $e, $item)) {
            // Liga as entradas resolvidas (papel entrada) com a versão vigente.
            foreach ($this->entradasResolvidas($caso, $item) as $fatoId) {
                $this->entregaveis->ligarFato($e->id, $fatoId, 'entrada');
            }
            $this->transicionador->transicionar($e, EstadoEntregavel::Pronto, 'prontidao', 'sistema', $regraVersaoId);
            if ($e->humano()) {
                $this->tarefas->abrir($caso->id, $e->id, 'executar_entregavel', $caso->carteira);
            }

            return 'mudou';
        }

        // invalidado que não cumpre §7.2 volta a aguardando_insumo.
        if ($e->estado === EstadoEntregavel::Invalidado) {
            $this->transicionador->transicionar($e, EstadoEntregavel::AguardandoInsumo, 'prontidao', 'sistema', $regraVersaoId);

            return 'mudou';
        }

        return 'nada';
    }

    /** @return 'mudou' */
    private function passoProcessar(Caso $caso, Entregavel $e, ItemEntregavel $item, ParametrosDeConferencia $parametros): string
    {
        // pronto (sistema) → processado + desfecho das conferências, no mesmo passo.
        $processado = $this->transicionador->transicionar($e, EstadoEntregavel::Processado, 'processamento_sistema', 'sistema', $caso->regraEntregaveisVersaoId);
        $this->conferirEDecidir($caso, $processado, $item, $parametros);

        return 'mudou';
    }

    /** @return 'mudou'|'nada'|'encerrou' */
    private function passoDepoisDeValidado(Caso $caso, Entregavel $e, ItemEntregavel $item): string
    {
        $regraVersaoId = $caso->regraEntregaveisVersaoId;

        // E12: encerra o caso (§10.2).
        if ($item->encerraCaso) {
            $this->transicionador->transicionar($e, EstadoEntregavel::Encerrado, 'encerramento', 'sistema', $regraVersaoId);
            $this->casos->encerrar($caso->id);
            $this->eventos->gravar('fechamento.concluido', $caso->id, null, ['caso_id' => $caso->id]);

            return 'encerrou';
        }

        // Pós-validação (E11): liberação automática (§10.1).
        if ($item->posValidacao !== null && $item->posValidacao->liberacao === 'automatica') {
            $this->transicionador->transicionar($e, EstadoEntregavel::Liberado, 'liberacao_automatica', 'sistema', $regraVersaoId);

            return 'mudou';
        }

        return 'nada';
    }

    /** @return 'mudou'|'nada' */
    private function passoDisponibilizar(Caso $caso, Entregavel $e, ItemEntregavel $item, string $tributoGuia): string
    {
        $pos = $item->posValidacao;
        if ($pos === null || $pos->disponibilizadoQuandoTipo === null) {
            return 'nada';
        }

        // Protocolo (§10.1): existe fato de disponibilização com o tributo da guia?
        $fatoDisp = $this->fatos->vigente($caso->titularId, $caso->competencia->valor, $pos->disponibilizadoQuandoTipo, $tributoGuia);
        if ($fatoDisp === null) {
            return 'nada';
        }

        // fato_id do evento = versão vigente da GUIA de entrada do E11 (§10.1, INV-14).
        $guia = $this->fatos->vigente($caso->titularId, $caso->competencia->valor, 'guia', $tributoGuia);
        if ($guia === null) {
            return 'nada';
        }

        $this->transicionador->transicionar($e, EstadoEntregavel::Disponibilizado, 'documento_disponibilizado', 'sistema', $caso->regraEntregaveisVersaoId, 'fato', $fatoDisp->id);
        $this->eventos->gravar('documento.disponibilizado', $caso->id, $e->id, ['entregavel_id' => $e->id, 'fato_id' => $guia->id]);

        return 'mudou';
    }

    private function prontoPodeAvancar(Caso $caso, Entregavel $e, ItemEntregavel $item): bool
    {
        // Dependências ≥ estado_minimo.
        foreach ($this->dependencias->doEntregavel($e->id) as $dep) {
            $alvo = $this->entregaveis->porId($dep->dependeDeId);
            if ($alvo === null) {
                return false;
            }
            if (! $alvo->estado->maiorOuIgual($dep->estadoMinimo)) {
                return false;
            }
        }

        // Toda entrada resolvida presente (§7.2).
        foreach ($this->entradasResolvidasBruto($caso, $item) as $id) {
            if ($id === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fato_ids das entradas resolvidas presentes (§7.1). Entradas cujo `quando`
     * não casa são ignoradas. Retorna só as presentes (para ligar).
     *
     * @return list<string>
     */
    private function entradasResolvidas(Caso $caso, ItemEntregavel $item): array
    {
        $ids = [];
        foreach ($this->entradasResolvidasBruto($caso, $item) as $id) {
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Para cada entrada cujo `quando` casa: o fato_id vigente, ou null se ausente.
     *
     * @return list<?string>
     */
    private function entradasResolvidasBruto(Caso $caso, ItemEntregavel $item): array
    {
        $ctx = $caso->contexto();
        $saida = [];
        foreach ($item->entradas as $entrada) {
            if (! Condicao::casa($entrada->quando, $ctx)) {
                continue;
            }
            $comp = $caso->competencia->somar($entrada->competenciaRelativa);
            $fato = $this->fatos->vigente($caso->titularId, $comp->valor, $entrada->tipo, $entrada->tributo);
            $saida[] = $fato?->id;
        }

        return $saida;
    }
}
