using System.Text.Json;
using Dapper;
using Motor.Aplicacao;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Npgsql;
using NpgsqlTypes;

namespace Motor.Infraestrutura;

internal sealed class TarefaRepo : RepoBase, ITarefaRepo
{
    public TarefaRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    public async Task<Guid> CriarAsync(Guid casoId, Guid entregavelId, string tipo, string responsavel)
    {
        return await Conn.ExecuteScalarAsync<Guid>(
            @"INSERT INTO trabalho.tarefa_humana (caso_id, entregavel_id, tipo, responsavel, estado)
              VALUES (@c, @e, @t, @r, 'aberta') RETURNING id",
            new { c = casoId, e = entregavelId, t = tipo, r = responsavel }, Tx);
    }

    public async Task<Guid?> AbertaDoEntregavelAsync(Guid entregavelId)
    {
        return await Conn.QuerySingleOrDefaultAsync<Guid?>(
            @"SELECT id FROM trabalho.tarefa_humana
              WHERE entregavel_id = @e AND estado = 'aberta' ORDER BY criada_em LIMIT 1",
            new { e = entregavelId }, Tx);
    }

    public async Task ConcluirAsync(Guid tarefaId)
    {
        await Conn.ExecuteAsync(
            @"UPDATE trabalho.tarefa_humana SET estado='concluida', concluida_em=now() WHERE id=@id",
            new { id = tarefaId }, Tx);
    }

    public async Task<IReadOnlyList<TarefaAberta>> AbertasDoCasoAsync(Guid casoId)
    {
        var rows = await Conn.QueryAsync<(Guid id, Guid entregavel)>(
            @"SELECT id, entregavel_id AS entregavel FROM trabalho.tarefa_humana
              WHERE caso_id = @c AND estado = 'aberta'", new { c = casoId }, Tx);
        return rows.Select(x => new TarefaAberta(x.id, x.entregavel)).ToList();
    }

    public async Task CancelarAbertasAsync(Guid entregavelId)
    {
        await Conn.ExecuteAsync(
            @"UPDATE trabalho.tarefa_humana SET estado='cancelada'
              WHERE entregavel_id=@e AND estado='aberta'", new { e = entregavelId }, Tx);
    }
}
