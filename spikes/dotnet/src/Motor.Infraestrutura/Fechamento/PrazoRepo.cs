using Dapper;
using Motor.Aplicacao;
using Npgsql;
using NpgsqlTypes;

namespace Motor.Infraestrutura;

/// <summary>Grava as datas de prazo (§18). UPDATE das três datas no entregável + INSERT no histórico
/// (fechamento.entregavel_prazo). Nenhuma transição é gravada aqui (§18.3).</summary>
internal sealed class PrazoRepo : RepoBase, IPrazosRepo
{
    public PrazoRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    public async Task AtualizarDatasAsync(Guid entregavelId, Guid casoId,
        DateOnly vencimento, DateOnly meta, DateOnly acao, string motivo,
        Guid calendarioVersaoId, Guid catalogoVersaoId, Guid prazosVersaoId)
    {
        // As três datas sempre juntas (constraint entregavel_datas_juntas) e em ordem (acao<=meta<=vencimento).
        // version NÃO muda: prazo é atributo, não estado (§18 princípio).
        await Conn.ExecuteAsync(
            @"UPDATE fechamento.entregavel
              SET vencimento = @v, meta = @m, acao = @a
              WHERE id = @id",
            new
            {
                v = vencimento.ToDateTime(TimeOnly.MinValue),
                m = meta.ToDateTime(TimeOnly.MinValue),
                a = acao.ToDateTime(TimeOnly.MinValue),
                id = entregavelId,
            }, Tx);

        await Conn.ExecuteAsync(
            @"INSERT INTO fechamento.entregavel_prazo
              (entregavel_id, caso_id, vencimento, meta, acao, motivo,
               calendario_versao_id, catalogo_versao_id, prazos_versao_id)
              VALUES (@e, @c, @v, @m, @a, @motivo, @cal, @cat, @prz)",
            new
            {
                e = entregavelId,
                c = casoId,
                v = vencimento.ToDateTime(TimeOnly.MinValue),
                m = meta.ToDateTime(TimeOnly.MinValue),
                a = acao.ToDateTime(TimeOnly.MinValue),
                motivo,
                cal = calendarioVersaoId,
                cat = catalogoVersaoId,
                prz = prazosVersaoId,
            }, Tx);
    }
}
