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


class UnidadeDeTrabalho(Protocol):
    """Operações de leitura/escrita numa única transação. `hora()` vem do banco."""

    def hora(self) -> str:
        """Instante do banco (now()/clock_timestamp()) como texto ISO."""
        ...

    # -- regras e catálogo ----------------------------------------------------
    def regra_entregaveis_em_uso(self) -> RegraEntregaveis: ...
    def tolerancia_em_uso(self) -> Tolerancia: ...
    def parametros_em_uso(self) -> Parametros: ...
    def area_do_tipo(self, tipo: str) -> str: ...
    def evento_publicado_do_tipo(self, tipo: str) -> str | None: ...
    def tipo_conferencia(self, cf: str) -> TipoConferencia: ...

    # -- caso -----------------------------------------------------------------
    def buscar_caso(self, titular_id: str, competencia: str) -> Caso | None: ...
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

    def buscar_entregavel(self, caso_id: str, tipo: str) -> Entregavel | None: ...
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
        """Regra de ouro (§4.1): estado + estado_desde + version++ + transição."""
        ...

    # -- dependências e fatos ligados ----------------------------------------
    def gravar_dependencia(
        self, entregavel_id: str, depende_de_id: str, estado_minimo: str
    ) -> None: ...
    def dependencias_do_entregavel(
        self, entregavel_id: str
    ) -> list[tuple[str, str]]:
        """[(depende_de_id, estado_minimo)]."""
        ...

    def estado_do_entregavel_por_id(self, entregavel_id: str) -> str: ...
    def ligar_fato(self, entregavel_id: str, fato_id: str, papel: str) -> None: ...

    # -- fatos ----------------------------------------------------------------
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
    ) -> Fato: ...

    # -- conferências ---------------------------------------------------------
    def conferencia_existente(
        self, cf: str, entregavel_id: str, entrada_hash: str
    ) -> str | None:
        """Devolve o resultado se já existe (idempotência §9.2), senão None."""
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
    ) -> str: ...

    # -- trabalho -------------------------------------------------------------
    def criar_tarefa(
        self, caso_id: str, entregavel_id: str, tipo: str, responsavel: str
    ) -> str: ...
    def concluir_tarefa_aberta(self, entregavel_id: str) -> str | None:
        """Conclui a tarefa aberta do entregável; devolve o id ou None."""
        ...

    def tarefa_aberta_do_entregavel(self, entregavel_id: str) -> str | None: ...
    def criar_excecao(
        self, caso_id: str, entregavel_id: str, tipo: str, classe: str, dono: str
    ) -> str: ...

    # -- eventos --------------------------------------------------------------
    def gravar_evento(
        self,
        nome: str,
        caso_id: str | None,
        entregavel_id: str | None,
        payload: dict[str, object],
    ) -> None: ...
