"""Protocolo da unidade de trabalho: tudo o que o motor pede ao banco.

Uma instância = uma transação. A implementação concreta (psycopg) vive em
`infraestrutura`; o motor só conhece este protocolo (requisito 11).
"""
from __future__ import annotations

from collections.abc import Sequence
from typing import Protocol

from motor.aplicacao.registros import (
    Caso,
    Entregavel,
    Fato,
    Parametros,
    RegraEntregaveis,
    TipoConferencia,
    Tolerancia,
)
from motor.dominio.contrato import Contrato


class UnidadeDeTrabalho(Protocol):
    """Operações de leitura/escrita numa única transação. `hora()` vem do banco."""

    def hora(self) -> str:
        """Instante do banco (now()/clock_timestamp()) como texto ISO."""
        ...

    # -- regras e catálogo ----------------------------------------------------
    def regra_entregaveis_em_uso(self) -> RegraEntregaveis: ...
    def regra_entregaveis_da_versao(self, versao_id: str) -> RegraEntregaveis:
        """Regra `fechamento.entregaveis` da versão fixada no caso (qualquer status)."""
        ...
    def tolerancia_em_uso(self) -> Tolerancia: ...
    def parametros_em_uso(self) -> Parametros: ...
    def area_do_tipo(self, tipo: str) -> str: ...
    def evento_publicado_do_tipo(self, tipo: str) -> str | None: ...
    def tipo_conferencia(self, cf: str) -> TipoConferencia: ...

    # -- contrato de tipo de fato (§6.1, v5) ----------------------------------
    def contrato_ativo(self, tipo: str) -> Contrato | None:
        """Única versão `ativa` do contrato do tipo, lida do banco a cada uso (§6.1, §13).

        None se o tipo não tem versão `ativa` (→ tipo_desconhecido no §6.2).
        """
        ...

    # -- caso -----------------------------------------------------------------
    def buscar_caso(self, titular_id: str, competencia: str) -> Caso | None: ...
    def travar_titular(self, titular_id: str) -> None:
        """Lock de transação por titular (reentrante): nível 1 da ordem global de locks.

        Ordem: 1) titular, 2) chave do fato, 3) linhas de entregável por tipo. Todo comando
        que escreve (abrir, fatos, concluir, override) o toma ANTES de qualquer outro lock.
        """
        ...

    def criar_caso(
        self,
        titular_id: str,
        competencia: str,
        snapshot: dict[str, object],
        carteira: str,
        regra_versao_id: str,
    ) -> Caso: ...
    def encerrar_caso(self, caso_id: str) -> None: ...
    def casos_abertos_do_titular(self, titular_id: str) -> list[Caso]: ...

    # -- entregável -----------------------------------------------------------
    def criar_entregavel(
        self, caso_id: str, tipo: str, executor: str, area: str
    ) -> Entregavel: ...
    def transicao_criacao(
        self, entregavel: Entregavel, ator: str, regra_versao_id: str
    ) -> None:
        """Linha de criação: de_estado NULL → aguardando_insumo (§4.1)."""
        ...
    def entregaveis_do_caso_para_atualizar(self, caso_id: str) -> list[Entregavel]:
        """Entregáveis do caso com SELECT ... FOR UPDATE, em ordem de tipo."""
        ...

    def buscar_entregavel(
        self, caso_id: str, tipo: str, para_atualizar: bool = False
    ) -> Entregavel | None:
        """Com `para_atualizar`, usa SELECT ... FOR UPDATE (serializa comandos concorrentes)."""
        ...

    def entregaveis_do_caso(self, caso_id: str) -> list[Entregavel]:
        """Entregáveis do caso em ordem de tipo (sem trava — só leitura)."""
        ...

    def tarefas_abertas_do_caso(self, caso_id: str) -> list[tuple[str, str]]:
        """[(tarefa_id, entregavel_tipo)] das tarefas abertas do caso."""
        ...

    def excecoes_abertas_do_caso(self, caso_id: str) -> list[tuple[str, str, str]]:
        """[(excecao_id, tipo, classe)] das exceções abertas do caso."""
        ...
    def transicionar(
        self,
        entregavel: Entregavel,
        para: str,
        motivo: str,
        causado_por_tipo: str | None,
        causado_por_id: str | None,
        ator: str,
        regra_versao_id: str,
    ) -> None:
        """Regra de ouro (§4.1): estado + estado_desde + version++ + transição.

        Levanta `ConflitoConcorrencia` se o estado no banco não é mais o lido.
        """
        ...

    # -- dependências e fatos ligados ----------------------------------------
    def gravar_dependencia(
        self, entregavel_id: str, depende_de_id: str, estado_minimo: str
    ) -> None: ...
    def dependencias_com_estado(
        self, entregavel_id: str
    ) -> list[tuple[str, str]]:
        """[(estado atual da dependência, estado_minimo)], lidos no momento da chamada."""
        ...

    def estado_do_entregavel_por_id(self, entregavel_id: str) -> str: ...
    def ligar_fato(self, entregavel_id: str, fato_id: str, papel: str) -> None: ...

    # -- fatos ----------------------------------------------------------------
    def travar_chave_fato(
        self, titular_id: str, competencia: str, tipo: str, tributo: str
    ) -> None:
        """Lock consultivo transacional por (titular, competência, tipo, tributo)."""
        ...

    def fato_vigente(
        self, titular_id: str, competencia: str, tipo: str, tributo: str
    ) -> Fato | None: ...
    def fatos_vigentes_do_caso(
        self, titular_id: str, competencia: str
    ) -> list[Fato]: ...
    def inserir_fato(
        self,
        titular_id: str,
        competencia: str,
        tipo: str,
        tributo: str,
        versao: int,
        valor_centavos: int | None,
        payload: dict[str, object],
        fonte: str,
        observado_em: str,
        hash_valor: str,
        substitui_id: str | None,
        tipo_versao: int,
        origem_ref: str | None = None,
    ) -> Fato: ...

    def versao_do_fato(self, fato_id: str) -> int:
        """`versao` do fato por id (para montar a resposta de um efeito sem novo fato)."""
        ...

    # -- recebimento e idempotência (§6.4, §6.5, v5) --------------------------
    def travar_idempotencia(self, fonte: str, chave_idempotencia: str) -> None:
        """Trava consultiva pela chave (`fonte`, `chave_idempotencia`), global entre titulares.

        A trava do titular (§13) não serializa duas publicações de titulares diferentes com a
        mesma chave; sem isto ambas leriam "sem recebimento" e a segunda inserção colidiria no
        índice único `recebimento_idempotencia` (erro, em vez do `repetido: true` do §6.5).
        """
        ...

    def inserir_recebimento(
        self,
        titular_id: str,
        competencia: str,
        tipo: str,
        tributo: str,
        fonte: str,
        chave_idempotencia: str | None,
        observado_em: str,
        tipo_versao: int,
        hash_valor: str,
        efeito: str,
        fato_id: str,
        payload: dict[str, object],
        valor_centavos: int | None,
    ) -> None:
        """Grava uma linha em `fatos.recebimento` (toda publicação válida, §6.4)."""
        ...

    def recebimento_por_idempotencia(
        self, fonte: str, chave_idempotencia: str
    ) -> tuple[str, int, str] | None:
        """(fato_id, versao, efeito) do recebimento com a mesma (fonte, chave), ou None (§6.5)."""
        ...

    # -- conferências ---------------------------------------------------------
    def conferencia_existente(
        self, cf: str, entregavel_id: str, entrada_hash: str
    ) -> tuple[str, str, str, str, bool] | None:
        """(resultado, severidade, conferencia_id, classe, sobrescrita) se já existe (§9.2)."""
        ...

    def marcar_conferencia_corrente(self, conferencia_id: str) -> None:
        """Torna a conferência reaproveitada a mais recente (`em`), base do override (§8.1)."""
        ...

    def inserir_conferencia(
        self,
        cf: str,
        caso_id: str,
        entregavel_id: str,
        regra_versao_id: str,
        fatos_usados: Sequence[dict[str, object]],
        entrada_hash: str,
        resultado: str,
        esperado_centavos: int | None,
        obtido_centavos: int | None,
        diferenca: dict[str, object],
        severidade: str,
        classe: str,
        semantica_calculo: int,
    ) -> str: ...

    # -- trabalho -------------------------------------------------------------
    def criar_tarefa(
        self, caso_id: str, entregavel_id: str, tipo: str, responsavel: str
    ) -> str: ...
    def concluir_tarefa_aberta(self, entregavel_id: str) -> str | None:
        """Conclui a tarefa aberta do entregável; devolve o id ou None."""
        ...


    def abrir_excecao_unica(
        self, caso_id: str, entregavel_id: str | None, tipo: str, classe: str, dono: str
    ) -> str | None:
        """Abre a exceção só se ainda não há uma aberta do mesmo tipo (§9.4).

        Devolve o id da exceção criada, ou None se já existia uma aberta. A verificação e a
        inserção são atômicas por (alvo, tipo): implementações serializam concorrentes.
        """
        ...

    def resolver_excecoes(
        self,
        entregavel_id: str,
        tipos_prefixo: str | None,
        tipos_exatos: Sequence[str] | None,
        resolucao: str,
    ) -> None:
        """Resolve exceções abertas de um entregável (§9.4, §10.3, §11).

        `tipos_prefixo` casa por LIKE 'prefixo%' (ex.: 'CF-'); `tipos_exatos`
        casa por igualdade a qualquer item da lista. Pelo menos um deve vir.
        """
        ...

    def cancelar_tarefa_aberta(self, entregavel_id: str) -> None:
        """Tarefa aberta do entregável → cancelada (§11.2)."""
        ...

    # -- invalidação (§11) ----------------------------------------------------
    def entregaveis_com_fato(self, fato_id: str) -> list[Entregavel]:
        """Entregáveis ligados a este fato (qualquer papel), para as sementes (§11.1)."""
        ...

    def caso_por_id(self, caso_id: str) -> Caso | None: ...

    def dependentes_de(self, caso_id: str, depende_de_id: str) -> list[Entregavel]:
        """Entregáveis do caso que dependem de `depende_de_id` (cascata §11.2)."""
        ...

    # -- override (§8.1) ------------------------------------------------------
    def conferencia_vigente(self, cf: str, entregavel_id: str) -> dict[str, object] | None:
        """Conferência mais recente da cf no entregável (maior `em`), ou None."""
        ...

    def aplicar_override(self, conferencia_id: str, autor: str, motivo: str) -> None:
        """Grava override_autor/motivo/em na conferência (§8.1)."""
        ...

    def ha_divergente_b_sem_override(
        self, entregavel_id: str, exceto_cf: str
    ) -> bool:
        """Alguma CF (≠ exceto_cf) tem conferência vigente divergente B sem override?"""
        ...

    # -- eventos --------------------------------------------------------------
    def gravar_evento(
        self,
        nome: str,
        caso_id: str | None,
        entregavel_id: str | None,
        payload: dict[str, object],
    ) -> None: ...
