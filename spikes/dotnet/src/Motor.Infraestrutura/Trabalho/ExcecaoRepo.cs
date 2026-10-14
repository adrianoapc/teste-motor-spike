using System.Text.Json;
using Dapper;
using Motor.Aplicacao;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Npgsql;
using NpgsqlTypes;

namespace Motor.Infraestrutura;

internal sealed class ExcecaoRepo : RepoBase, IExcecaoRepo
{
    public ExcecaoRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    public async Task<Guid?> AbrirUnicaAsync(Guid casoId, Guid entregavelId, string tipo, string classe, string dono)
    {
        // Abertura única (§9.4): insere só se não houver outra aberta do mesmo tipo p/ o mesmo entregável.
        return await Conn.QuerySingleOrDefaultAsync<Guid?>(
            @"INSERT INTO trabalho.excecao (caso_id, entregavel_id, tipo, classe, dono, estado)
              SELECT @c, @e, @t, @cl, @d, 'aberta'
              WHERE NOT EXISTS (
                  SELECT 1 FROM trabalho.excecao
                  WHERE entregavel_id = @e AND tipo = @t AND estado = 'aberta')
              RETURNING id",
            new { c = casoId, e = entregavelId, t = tipo, cl = classe, d = dono }, Tx);
    }

    public async Task<Guid?> AbrirUnicaSemEntregavelAsync(Guid casoId, string tipo, string classe, string dono)
    {
        // Abertura única por CASO (§11.3): entregavel_id nulo; chave = (caso_id, tipo).
        return await Conn.QuerySingleOrDefaultAsync<Guid?>(
            @"INSERT INTO trabalho.excecao (caso_id, entregavel_id, tipo, classe, dono, estado)
              SELECT @c, NULL, @t, @cl, @d, 'aberta'
              WHERE NOT EXISTS (
                  SELECT 1 FROM trabalho.excecao
                  WHERE caso_id = @c AND entregavel_id IS NULL AND tipo = @t AND estado = 'aberta')
              RETURNING id",
            new { c = casoId, t = tipo, cl = classe, d = dono }, Tx);
    }

    public async Task ResolverCfAbertasAsync(Guid entregavelId, string resolucao)
    {
        await Conn.ExecuteAsync(
            @"UPDATE trabalho.excecao
              SET estado = 'resolvida', resolucao = @r, resolvida_em = now()
              WHERE entregavel_id = @e AND estado = 'aberta' AND tipo LIKE 'CF-%'",
            new { e = entregavelId, r = resolucao }, Tx);
    }

    public async Task<IReadOnlyList<ExcecaoConsulta>> AbertasDoCasoAsync(Guid casoId)
    {
        var rows = await Conn.QueryAsync<(Guid id, string tipo, string classe)>(
            @"SELECT id, tipo, classe FROM trabalho.excecao WHERE caso_id = @c AND estado = 'aberta'",
            new { c = casoId }, Tx);
        return rows.Select(x => new ExcecaoConsulta(x.id, x.tipo, x.classe)).ToList();
    }

    public async Task ResolverPorTiposAsync(Guid entregavelId, IReadOnlyList<string> tipos, string resolucao)
    {
        if (tipos.Count == 0) return;
        await Conn.ExecuteAsync(
            @"UPDATE trabalho.excecao
              SET estado = 'resolvida', resolucao = @r, resolvida_em = now()
              WHERE entregavel_id = @e AND estado = 'aberta' AND tipo = ANY(@tipos)",
            new { e = entregavelId, r = resolucao, tipos = tipos.ToArray() }, Tx);
    }
}
