"""Unidade de trabalho em psycopg: uma transação por comando (§13, requisito 2).

Implementa o protocolo `motor.aplicacao.portas.UnidadeDeTrabalho`. Só SQL
explícito contra o esquema existente — nunca cria/altera tabela.
"""
from __future__ import annotations

from collections.abc import Iterator, Sequence
from contextlib import contextmanager
from typing import Any

from psycopg import Connection
from psycopg.types.json import Jsonb

from motor.aplicacao.registros import (
    Caso,
    Entregavel,
    Fato,
    ItemEntregavel,
    Parametros,
    RegraEntregaveis,
    TipoConferencia,
    Tolerancia,
)
from motor.infraestrutura import banco


class UnidadeDeTrabalhoSQL:
    """Wrapper transacional sobre uma conexão psycopg (dict_row)."""

    def __init__(self, conn: Connection[dict[str, Any]]) -> None:
        self._conn = conn

    def _um(self, sql: str, params: Sequence[object] = ()) -> dict[str, Any] | None:
        with self._conn.cursor() as cur:
            cur.execute(sql, params)
            row = cur.fetchone()
        return row

    def _todos(self, sql: str, params: Sequence[object] = ()) -> list[dict[str, Any]]:
        with self._conn.cursor() as cur:
            cur.execute(sql, params)
            rows = cur.fetchall()
        return rows

    def _exec(self, sql: str, params: Sequence[object] = ()) -> None:
        with self._conn.cursor() as cur:
            cur.execute(sql, params)

    # -- hora ----------------------------------------------------------------
    def hora(self) -> str:
        row = self._um("SELECT clock_timestamp() AS t")
        assert row is not None
        return str(row["t"])

    # -- regras e catálogo ----------------------------------------------------
    def _conteudo_regra(self, chave: str) -> tuple[str, dict[str, Any]]:
        row = self._um(
            "SELECT id, conteudo FROM regras.regra_versao "
            "WHERE regra_chave = %s AND status IN ('provisoria','ativa')",
            (chave,),
        )
        if row is None:
            raise LookupError(f"sem versão em uso para a regra {chave}")
        return str(row["id"]), row["conteudo"]

    def regra_entregaveis_em_uso(self) -> RegraEntregaveis:
        versao_id, conteudo = self._conteudo_regra("fechamento.entregaveis")
        itens = [
            ItemEntregavel(
                tipo=it["tipo"],
                executor=it["executor"],
                quando=it.get("quando") or [],
                depende_de=it.get("depende_de") or [],
                entradas=it.get("entradas") or [],
                saida=it.get("saida"),
                conferencias=it.get("conferencias") or [],
                pos_validacao=it.get("pos_validacao"),
                encerra_caso=bool(it.get("encerra_caso", False)),
            )
            for it in conteudo["entregaveis"]
        ]
        return RegraEntregaveis(
            versao_id=versao_id,
            estado_minimo_padrao=conteudo.get("estado_minimo_padrao", "validado"),
            itens=itens,
        )

    def tolerancia_em_uso(self) -> Tolerancia:
        versao_id, conteudo = self._conteudo_regra("fiscal.tolerancia")
        padrao = conteudo["padrao"]
        return Tolerancia(
            versao_id=versao_id,
            ok_ate_centavos=int(padrao["ok_ate_centavos"]),
            alerta_ate_centavos=int(padrao["alerta_ate_centavos"]),
        )

    def parametros_em_uso(self) -> Parametros:
        _, conteudo = self._conteudo_regra("fiscal.parametros")
        return Parametros(prolabore_percentual_bp=int(conteudo["prolabore_percentual_bp"]))

    def area_do_tipo(self, tipo: str) -> str:
        row = self._um(
            "SELECT area FROM fechamento.entregavel_tipo WHERE chave = %s", (tipo,)
        )
        if row is None:
            raise LookupError(f"entregavel_tipo inexistente: {tipo}")
        return str(row["area"])

    def evento_publicado_do_tipo(self, tipo: str) -> str | None:
        row = self._um(
            "SELECT evento_publicado FROM fechamento.entregavel_tipo WHERE chave = %s",
            (tipo,),
        )
        if row is None:
            return None
        v = row["evento_publicado"]
        return str(v) if v is not None else None

    def tipo_conferencia(self, cf: str) -> TipoConferencia:
        row = self._um(
            "SELECT chave, severidade, classe_padrao "
            "FROM conferencias.conferencia_tipo WHERE chave = %s",
            (cf,),
        )
        if row is None:
            raise LookupError(f"conferencia_tipo inexistente: {cf}")
        return TipoConferencia(
            chave=str(row["chave"]),
            severidade=str(row["severidade"]),
            classe_padrao=str(row["classe_padrao"]),
        )

    # -- caso -----------------------------------------------------------------
    @staticmethod
    def _caso(row: dict[str, Any]) -> Caso:
        return Caso(
            id=str(row["id"]),
            titular_id=row["titular_id"],
            competencia=row["competencia"],
            snapshot=row["snapshot"],
            carteira=row["carteira"],
            regra_entregaveis_versao_id=str(row["regra_entregaveis_versao_id"]),
            estado=row["estado"],
            version=row["version"],
        )

    def buscar_caso(self, titular_id: str, competencia: str) -> Caso | None:
        row = self._um(
            "SELECT * FROM fechamento.caso_competencia "
            "WHERE titular_id = %s AND competencia = %s",
            (titular_id, competencia),
        )
        return self._caso(row) if row else None

    def criar_caso(
        self,
        titular_id: str,
        competencia: str,
        snapshot: dict[str, object],
        carteira: str,
        regra_versao_id: str,
    ) -> Caso:
        row = self._um(
            "INSERT INTO fechamento.caso_competencia "
            "(titular_id, competencia, snapshot, carteira, regra_entregaveis_versao_id, estado) "
            "VALUES (%s,%s,%s,%s,%s,'aberto') RETURNING *",
            (titular_id, competencia, Jsonb(snapshot), carteira, regra_versao_id),
        )
        assert row is not None
        return self._caso(row)

    def encerrar_caso(self, caso_id: str) -> None:
        self._exec(
            "UPDATE fechamento.caso_competencia "
            "SET estado = 'encerrado', encerrado_em = now(), version = version + 1 "
            "WHERE id = %s",
            (caso_id,),
        )

    def casos_abertos_do_titular(self, titular_id: str) -> list[Caso]:
        rows = self._todos(
            "SELECT * FROM fechamento.caso_competencia "
            "WHERE titular_id = %s AND estado = 'aberto' ORDER BY competencia",
            (titular_id,),
        )
        return [self._caso(r) for r in rows]

    # -- entregável -----------------------------------------------------------
    @staticmethod
    def _entregavel(row: dict[str, Any]) -> Entregavel:
        return Entregavel(
            id=str(row["id"]),
            caso_id=str(row["caso_id"]),
            tipo=row["tipo"],
            estado=row["estado"],
            executor=row["executor"],
            area=row["area"],
            version=row["version"],
        )

    def criar_entregavel(
        self, caso_id: str, tipo: str, executor: str, area: str
    ) -> Entregavel:
        row = self._um(
            "INSERT INTO fechamento.entregavel (caso_id, tipo, estado, executor, area) "
            "VALUES (%s,%s,'aguardando_insumo',%s,%s) RETURNING *",
            (caso_id, tipo, executor, area),
        )
        assert row is not None
        return self._entregavel(row)

    def entregaveis_do_caso_para_atualizar(self, caso_id: str) -> list[Entregavel]:
        rows = self._todos(
            "SELECT * FROM fechamento.entregavel WHERE caso_id = %s "
            "ORDER BY tipo FOR UPDATE",
            (caso_id,),
        )
        return [self._entregavel(r) for r in rows]

    def buscar_entregavel(self, caso_id: str, tipo: str) -> Entregavel | None:
        row = self._um(
            "SELECT * FROM fechamento.entregavel WHERE caso_id = %s AND tipo = %s",
            (caso_id, tipo),
        )
        return self._entregavel(row) if row else None

    def estado_do_entregavel_por_id(self, entregavel_id: str) -> str:
        row = self._um(
            "SELECT estado FROM fechamento.entregavel WHERE id = %s", (entregavel_id,)
        )
        assert row is not None
        return str(row["estado"])

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
        de = entregavel.estado
        self._exec(
            "UPDATE fechamento.entregavel "
            "SET estado = %s, estado_desde = clock_timestamp(), version = version + 1 "
            "WHERE id = %s",
            (para, entregavel.id),
        )
        self._exec(
            "INSERT INTO fechamento.entregavel_transicao "
            "(entregavel_id, caso_id, de_estado, para_estado, motivo, "
            " causado_por_tipo, causado_por_id, ator, regra_versao_id) "
            "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)",
            (
                entregavel.id,
                entregavel.caso_id,
                de,
                para,
                motivo,
                causado_por_tipo,
                causado_por_id,
                ator,
                regra_versao_id,
            ),
        )
        entregavel.estado = para
        entregavel.version += 1

    # -- dependências e fatos ligados ----------------------------------------
    def gravar_dependencia(
        self, entregavel_id: str, depende_de_id: str, estado_minimo: str
    ) -> None:
        self._exec(
            "INSERT INTO fechamento.entregavel_dependencia "
            "(entregavel_id, depende_de_id, estado_minimo) VALUES (%s,%s,%s) "
            "ON CONFLICT DO NOTHING",
            (entregavel_id, depende_de_id, estado_minimo),
        )

    def dependencias_do_entregavel(self, entregavel_id: str) -> list[tuple[str, str]]:
        rows = self._todos(
            "SELECT depende_de_id, estado_minimo FROM fechamento.entregavel_dependencia "
            "WHERE entregavel_id = %s",
            (entregavel_id,),
        )
        return [(str(r["depende_de_id"]), str(r["estado_minimo"])) for r in rows]

    def ligar_fato(self, entregavel_id: str, fato_id: str, papel: str) -> None:
        self._exec(
            "INSERT INTO fechamento.entregavel_fato (entregavel_id, fato_id, papel) "
            "VALUES (%s,%s,%s) ON CONFLICT DO NOTHING",
            (entregavel_id, fato_id, papel),
        )

    # -- fatos ----------------------------------------------------------------
    @staticmethod
    def _fato(row: dict[str, Any]) -> Fato:
        return Fato(
            id=str(row["id"]),
            titular_id=row["titular_id"],
            competencia=row["competencia"],
            tipo=row["tipo"],
            tributo=row["tributo"],
            versao=row["versao"],
            valor_centavos=row["valor_centavos"],
            payload=row["payload"],
            hash=row["hash"],
        )

    def fato_vigente(
        self, titular_id: str, competencia: str, tipo: str, tributo: str
    ) -> Fato | None:
        row = self._um(
            "SELECT * FROM fatos.fato_vigente "
            "WHERE titular_id = %s AND competencia = %s AND tipo = %s AND tributo = %s",
            (titular_id, competencia, tipo, tributo),
        )
        return self._fato(row) if row else None

    def fatos_vigentes_do_caso(
        self, titular_id: str, competencia: str
    ) -> list[Fato]:
        rows = self._todos(
            "SELECT * FROM fatos.fato_vigente "
            "WHERE titular_id = %s AND competencia = %s",
            (titular_id, competencia),
        )
        return [self._fato(r) for r in rows]

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
    ) -> Fato:
        row = self._um(
            "INSERT INTO fatos.fato "
            "(titular_id, competencia, tipo, tributo, versao, valor_centavos, payload, "
            " fonte, hash, substitui_id, observado_em) "
            "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) RETURNING *",
            (
                titular_id,
                competencia,
                tipo,
                tributo,
                versao,
                valor_centavos,
                Jsonb(payload),
                fonte,
                hash_valor,
                substitui_id,
                observado_em,
            ),
        )
        assert row is not None
        return self._fato(row)

    # -- conferências ---------------------------------------------------------
    def conferencia_existente(
        self, cf: str, entregavel_id: str, entrada_hash: str
    ) -> str | None:
        row = self._um(
            "SELECT resultado FROM conferencias.conferencia "
            "WHERE cf = %s AND entregavel_id = %s AND entrada_hash = %s",
            (cf, entregavel_id, entrada_hash),
        )
        return str(row["resultado"]) if row else None

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
    ) -> str:
        row = self._um(
            "INSERT INTO conferencias.conferencia "
            "(cf, caso_id, entregavel_id, regra_versao_id, fatos_usados, entrada_hash, "
            " resultado, esperado_centavos, obtido_centavos, diferenca, severidade, classe) "
            "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) "
            "ON CONFLICT (cf, entregavel_id, entrada_hash) DO NOTHING RETURNING id",
            (
                cf,
                caso_id,
                entregavel_id,
                regra_versao_id,
                Jsonb(list(fatos_usados)),
                entrada_hash,
                resultado,
                esperado_centavos,
                obtido_centavos,
                Jsonb(diferenca),
                severidade,
                classe,
            ),
        )
        if row is None:
            existente = self._um(
                "SELECT id FROM conferencias.conferencia "
                "WHERE cf = %s AND entregavel_id = %s AND entrada_hash = %s",
                (cf, entregavel_id, entrada_hash),
            )
            assert existente is not None
            return str(existente["id"])
        return str(row["id"])

    # -- trabalho -------------------------------------------------------------
    def criar_tarefa(
        self, caso_id: str, entregavel_id: str, tipo: str, responsavel: str
    ) -> str:
        row = self._um(
            "INSERT INTO trabalho.tarefa_humana "
            "(caso_id, entregavel_id, tipo, responsavel, estado) "
            "VALUES (%s,%s,%s,%s,'aberta') RETURNING id",
            (caso_id, entregavel_id, tipo, responsavel),
        )
        assert row is not None
        return str(row["id"])

    def tarefa_aberta_do_entregavel(self, entregavel_id: str) -> str | None:
        row = self._um(
            "SELECT id FROM trabalho.tarefa_humana "
            "WHERE entregavel_id = %s AND estado = 'aberta' ORDER BY criada_em LIMIT 1",
            (entregavel_id,),
        )
        return str(row["id"]) if row else None

    def concluir_tarefa_aberta(self, entregavel_id: str) -> str | None:
        row = self._um(
            "UPDATE trabalho.tarefa_humana SET estado = 'concluida', concluida_em = now() "
            "WHERE id = ("
            "  SELECT id FROM trabalho.tarefa_humana "
            "  WHERE entregavel_id = %s AND estado = 'aberta' ORDER BY criada_em LIMIT 1"
            ") RETURNING id",
            (entregavel_id,),
        )
        return str(row["id"]) if row else None

    def criar_excecao(
        self, caso_id: str, entregavel_id: str, tipo: str, classe: str, dono: str
    ) -> str:
        row = self._um(
            "INSERT INTO trabalho.excecao "
            "(caso_id, entregavel_id, tipo, classe, dono, estado) "
            "VALUES (%s,%s,%s,%s,%s,'aberta') RETURNING id",
            (caso_id, entregavel_id, tipo, classe, dono),
        )
        assert row is not None
        return str(row["id"])

    # -- eventos --------------------------------------------------------------
    def gravar_evento(
        self,
        nome: str,
        caso_id: str | None,
        entregavel_id: str | None,
        payload: dict[str, object],
    ) -> None:
        self._exec(
            "INSERT INTO eventos.evento (nome, caso_id, entregavel_id, payload) "
            "VALUES (%s,%s,%s,%s)",
            (nome, caso_id, entregavel_id, Jsonb(payload)),
        )


@contextmanager
def unidade_de_trabalho() -> Iterator[UnidadeDeTrabalhoSQL]:
    """Abre uma conexão e envolve o bloco numa transação (commit/rollback)."""
    with banco.conexao() as conn:
        try:
            yield UnidadeDeTrabalhoSQL(conn)
            conn.commit()
        except Exception:
            conn.rollback()
            raise
