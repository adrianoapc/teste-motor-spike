<?php

declare(strict_types=1);

namespace App\Aplicacao\Fatos;

use App\Aplicacao\Fechamento\Excecoes;
use App\Aplicacao\Portas\RepositorioContratoTipoFato;
use App\Aplicacao\Portas\RepositorioEvento;
use App\Aplicacao\Portas\RepositorioFato;
use App\Aplicacao\Portas\RepositorioRecebimento;
use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Fatos\ContratoTipoFato;
use App\Dominio\Fatos\Fato;
use App\Dominio\Fatos\HashFatoV2;
use App\Dominio\Fatos\Instante;
use App\Dominio\Fatos\ValidadorContrato;
use App\Dominio\Fechamento\Caso;

/**
 * Núcleo de uma publicação (§6.1–6.4, v5), compartilhado por PublicarFato (/fatos) e
 * ConcluirTarefa (saídas, §8 passo 3a). Já sob a trava do titular e a trava da chave
 * (os chamadores as tomam na ordem global de locks).
 *
 * Faz, nesta ordem (§6.2):
 *   2. tipo com versão ativa (senão tipo_desconhecido);
 *   3. fonte em fontes_permitidas (senão fonte_nao_autorizada);
 *   4. qualificador + valor_centavos + payload contra o esquema (senão payload_invalido).
 * Depois calcula o hash v2 e o efeito (§6.4), grava fato quando for o caso, grava o
 * recebimento de toda publicação válida e abre a exceção conflito_fonte.
 */
final readonly class PublicacaoDeFato
{
    public function __construct(
        private RepositorioContratoTipoFato $contratos,
        private RepositorioFato $fatos,
        private RepositorioRecebimento $recebimentos,
        private RepositorioEvento $eventos,
        private Excecoes $excecoes,
    ) {}

    /**
     * Valida e publica. $prefixoCampo = prefixo do ITEM nas violações ('' no /fatos,
     * 'saidas[i]' numa saída de tarefa). O chamador já travou titular e chave.
     *
     * @param  mixed  $payload  objeto preservado
     */
    public function publicar(
        string $titularId,
        string $competencia,
        string $tipo,
        string $tributo,
        ?int $valorCentavos,
        mixed $payload,
        string $fonte,
        ?string $origemRef,
        string $observadoEm,
        ?string $chaveIdempotencia,
        ?Caso $caso,
        string $prefixoCampo,
    ): Publicacao {
        // §6.4 (v5.1): trunca observado_em para 6 dígitos de fração ANTES de comparar e de
        // gravar. Assim o Postgres recebe sempre 6 dígitos e nunca arredonda (meio-para-o-par).
        $observadoEm = Instante::truncar($observadoEm);

        // §6.2 passo 2: tipo com versão ativa.
        $contrato = $this->contratos->ativo($tipo);
        if ($contrato === null) {
            throw ErroDeNegocio::tipoDesconhecido($tipo);
        }

        // §6.2 passo 3: fonte autorizada.
        if (! $contrato->fontePermitida($fonte)) {
            throw ErroDeNegocio::fonteNaoAutorizada($fonte, $tipo);
        }

        // §6.2 passo 4: qualificador, valor_centavos e payload.
        $violacoes = (new ValidadorContrato($prefixoCampo))->validar($contrato, $tributo, $valorCentavos, $payload);
        if ($violacoes !== []) {
            throw ErroDeNegocio::payloadInvalido($violacoes);
        }

        // §6.5: idempotência de requisição, DEPOIS das validações e já sob a trava. Repetida =
        // devolve o resultado da primeira vez, sem gravar nada (nem recebimento). A chave manda.
        if ($chaveIdempotencia !== null) {
            $anterior = $this->recebimentos->porIdempotencia($fonte, $chaveIdempotencia);
            if ($anterior !== null) {
                $fatoAnterior = $this->fatos->porId($anterior['fato_id']);
                assert($fatoAnterior !== null);

                return new Publicacao($fatoAnterior, $anterior['efeito'], null, true);
            }
        }

        $hash = HashFatoV2::hash($payload, $valorCentavos, $contrato);
        $vigente = $this->fatos->vigente($titularId, $competencia, $tipo, $tributo);

        // §6.4: efeito a partir de h e hV (hV recalculado AGORA, sob o contrato ativo).
        $efeito = $this->efeito($vigente, $hash, $valorCentavos, $observadoEm, $fonte, $contrato);

        $fato = $this->aplicarEfeito(
            $efeito, $vigente, $titularId, $competencia, $tipo, $tributo, $valorCentavos,
            $payload, $fonte, $origemRef, $hash, $observadoEm, $contrato->versao, $caso,
        );

        // Recebimento de toda publicação válida (§6.4), inclusive as que não viram fato.
        $this->recebimentos->gravar(
            $titularId, $competencia, $tipo, $tributo, $fonte, $chaveIdempotencia, $observadoEm,
            $contrato->versao, $hash, $efeito, $fato->id, $payload, $valorCentavos,
        );

        $fatoAnteriorId = $efeito === 'nova_versao' ? $vigente?->id : null;

        return new Publicacao($fato, $efeito, $fatoAnteriorId);
    }

    /**
     * Valida uma publicação contra o contrato ativo SEM gravar (§8 passo 3a: todas as
     * saídas de /concluir são validadas antes de gravar qualquer uma). Lança o mesmo
     * erro de publicar(): tipo_desconhecido (422), fonte_nao_autorizada (403) ou
     * payload_invalido (422, com violações prefixadas por $prefixoCampo, o prefixo do item).
     *
     * @param  mixed  $payload  objeto preservado
     */
    public function validar(
        string $tipo,
        string $tributo,
        ?int $valorCentavos,
        mixed $payload,
        string $fonte,
        string $prefixoCampo,
    ): void {
        $contrato = $this->contratos->ativo($tipo);
        if ($contrato === null) {
            throw ErroDeNegocio::tipoDesconhecido($tipo);
        }
        if (! $contrato->fontePermitida($fonte)) {
            throw ErroDeNegocio::fonteNaoAutorizada($fonte, $tipo);
        }
        $violacoes = (new ValidadorContrato($prefixoCampo))->validar($contrato, $tributo, $valorCentavos, $payload);
        if ($violacoes !== []) {
            throw ErroDeNegocio::payloadInvalido($violacoes);
        }
    }

    /**
     * Recalcula o hash da vigente sob o contrato ativo e decide o efeito (§6.4).
     */
    private function efeito(
        ?Fato $vigente,
        string $hash,
        ?int $valorCentavos,
        string $observadoEm,
        string $fonte,
        ContratoTipoFato $contrato,
    ): string {
        if ($vigente === null) {
            return 'novo';
        }
        $hV = HashFatoV2::hash($vigente->payload, $vigente->valorCentavos, $contrato);
        if ($hash === $hV) {
            return 'sem_mudanca';
        }
        // Comparação de observado_em como instante.
        $cmp = $this->compararInstantes($observadoEm, $vigente->observadoEm);
        if ($cmp > 0) {
            return 'nova_versao';
        }
        if ($cmp < 0) {
            return 'obsoleto';
        }

        // Mesmo instante: mesma fonte = conflito_fonte; fonte diferente = nova_versao.
        return $fonte === $vigente->fonte ? 'conflito_fonte' : 'nova_versao';
    }

    /**
     * Grava (ou não) o fato conforme o efeito e devolve o fato de referência do
     * recebimento: o criado (novo/nova_versao) ou a vigente (demais).
     */
    private function aplicarEfeito(
        string $efeito,
        ?Fato $vigente,
        string $titularId,
        string $competencia,
        string $tipo,
        string $tributo,
        ?int $valorCentavos,
        mixed $payload,
        string $fonte,
        ?string $origemRef,
        string $hash,
        string $observadoEm,
        int $tipoVersao,
        ?Caso $caso,
    ): Fato {
        if ($efeito === 'novo' || $efeito === 'nova_versao') {
            $versao = $vigente !== null ? $vigente->versao + 1 : 1;
            $fato = $this->fatos->inserir(
                $titularId, $competencia, $tipo, $tributo, $versao, $valorCentavos, $payload,
                $fonte, $origemRef, $hash, $vigente?->id, $observadoEm, $tipoVersao,
            );
            $this->eventos->gravar('fato.publicado', $caso?->id, null, ['fato_id' => $fato->id]);

            return $fato;
        }

        assert($vigente !== null); // sem_mudanca/obsoleto/conflito_fonte exigem vigente.

        if ($efeito === 'conflito_fonte') {
            $this->abrirConflitoFonte($caso);
        }

        return $vigente;
    }

    /**
     * conflito_fonte (§6.4, §9.4): exceção classe S, sem entregável, no caso aberto;
     * dono = carteira. Sem caso, ou caso encerrado, fica só o recebimento.
     */
    private function abrirConflitoFonte(?Caso $caso): void
    {
        if ($caso === null || $caso->encerrado()) {
            return;
        }
        $this->excecoes->abrirUnica($caso->id, null, 'conflito_fonte', 'S', $caso->carteira);
    }

    /** -1, 0 ou 1 comparando dois instantes; null (vigente pré-v5) conta como anterior. */
    private function compararInstantes(string $novo, ?string $vigente): int
    {
        if ($vigente === null) {
            return 1;
        }
        try {
            // Normaliza à precisão do timestamptz (6 casas, truncando) ANTES de comparar:
            // o que chega (até 9 dígitos de fração) e a vigente lida do banco (já truncada)
            // têm de ser medidos no mesmo instante, senão '…1234567Z' viraria obsoleto em vez de
            // conflito_fonte por 1 µs de diferença entre o que chega e o que está gravado.
            $a = Instante::microssegundos($novo);
            $b = Instante::microssegundos($vigente);
        } catch (\Exception) {
            // Sem instante comparável, trata como nova_versao (não silenciar mudança de hash).
            return 1;
        }

        return $a <=> $b;
    }
}
