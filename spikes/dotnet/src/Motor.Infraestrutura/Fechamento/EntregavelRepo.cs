using System.Text.Json;
using Dapper;
using Motor.Aplicacao;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Npgsql;
using NpgsqlTypes;

namespace Motor.Infraestrutura;

internal sealed class EntregavelRepo : RepoBase, IEntregavelRepo
{
    public EntregavelRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    private static Entregavel Mapear((Guid id, Guid caso, string tipo, string estado, string executor, string area, int version) r) =>
        new(r.id, r.caso, r.tipo, Estados.Analisar(r.estado), r.executor, r.area, r.version);

    private const string Cols = "id, caso_id AS caso, tipo, estado, executor, area, version";

    public async Task<IReadOnlyList<Entregavel>> DoCasoComTravaAsync(Guid casoId)
    {
        var rows = await Conn.QueryAsync<(Guid, Guid, string, string, string, string, int)>(
            $"SELECT {Cols} FROM fechamento.entregavel WHERE caso_id = @c ORDER BY tipo FOR UPDATE",
            new { c = casoId }, Tx);
        return rows.Select(Mapear).ToList();
    }

    public async Task<IReadOnlyList<Entregavel>> DoCasoAsync(Guid casoId)
    {
        var rows = await Conn.QueryAsync<(Guid, Guid, string, string, string, string, int)>(
            $"SELECT {Cols} FROM fechamento.entregavel WHERE caso_id = @c ORDER BY tipo",
            new { c = casoId }, Tx);
        return rows.Select(Mapear).ToList();
    }

    public async Task<EntregavelTipoCatalogo?> TipoCatalogoAsync(string tipo)
    {
        var row = await Conn.QuerySingleOrDefaultAsync<(string area, string? evento)?>(
            @"SELECT area, evento_publicado AS evento
              FROM fechamento.entregavel_tipo WHERE chave = @tipo", new { tipo }, Tx);
        return row is null ? null : new EntregavelTipoCatalogo(row.Value.area, row.Value.evento);
    }

    public async Task<Entregavel?> PorChaveAsync(Guid casoId, string tipo)
    {
        var r = await Conn.QuerySingleOrDefaultAsync<(Guid, Guid, string, string, string, string, int)?>(
            $"SELECT {Cols} FROM fechamento.entregavel WHERE caso_id = @c AND tipo = @t",
            new { c = casoId, t = tipo }, Tx);
        return r is null ? null : Mapear(r.Value);
    }

    public async Task<Entregavel?> PorChaveComTravaAsync(Guid casoId, string tipo)
    {
        var r = await Conn.QuerySingleOrDefaultAsync<(Guid, Guid, string, string, string, string, int)?>(
            $"SELECT {Cols} FROM fechamento.entregavel WHERE caso_id = @c AND tipo = @t FOR UPDATE",
            new { c = casoId, t = tipo }, Tx);
        return r is null ? null : Mapear(r.Value);
    }

    public async Task<Entregavel> CriarAsync(Guid casoId, string tipo, string executor, string area)
    {
        var id = await Conn.ExecuteScalarAsync<Guid>(
            @"INSERT INTO fechamento.entregavel (caso_id, tipo, estado, executor, area)
              VALUES (@c, @t, 'aguardando_insumo', @e, @a) RETURNING id",
            new { c = casoId, t = tipo, e = executor, a = area }, Tx);
        return new Entregavel(id, casoId, tipo, EstadoEntregavel.AguardandoInsumo, executor, area, 1);
    }

    public async Task GravarDependenciaAsync(Guid entregavelId, Guid dependeDeId, EstadoEntregavel estadoMinimo)
    {
        await Conn.ExecuteAsync(
            @"INSERT INTO fechamento.entregavel_dependencia (entregavel_id, depende_de_id, estado_minimo)
              VALUES (@e, @d, @m) ON CONFLICT DO NOTHING",
            new { e = entregavelId, d = dependeDeId, m = estadoMinimo.Nome() }, Tx);
    }

    public async Task<IReadOnlyList<Dependencia>> DependenciasDoCasoAsync(Guid casoId)
    {
        var rows = await Conn.QueryAsync<(Guid e, Guid d, string m)>(
            @"SELECT d.entregavel_id AS e, d.depende_de_id AS d, d.estado_minimo AS m
              FROM fechamento.entregavel_dependencia d
              JOIN fechamento.entregavel x ON x.id = d.entregavel_id
              WHERE x.caso_id = @c",
            new { c = casoId }, Tx);
        return rows.Select(x => new Dependencia(x.e, x.d, Estados.Analisar(x.m))).ToList();
    }

    public async Task TransicionarAsync(Entregavel atual, NovaTransicao tr)
    {
        // UPDATE do entregável com checagem otimista de version (§13)
        var afetadas = await Conn.ExecuteAsync(
            @"UPDATE fechamento.entregavel
              SET estado = @novo, estado_desde = clock_timestamp(), version = version + 1
              WHERE id = @id AND version = @ver",
            new { novo = tr.Para.Nome(), id = atual.Id, ver = atual.Version }, Tx);
        if (afetadas != 1)
            throw new InvalidOperationException($"conflito de versão ao transicionar {atual.Tipo} ({atual.Id})");

        await Conn.ExecuteAsync(
            @"INSERT INTO fechamento.entregavel_transicao
              (entregavel_id, caso_id, de_estado, para_estado, motivo, causado_por_tipo, causado_por_id, ator, regra_versao_id)
              VALUES (@e, @c, @de, @para, @motivo, @ct, @ci, @ator, @rv)",
            new
            {
                e = tr.EntregavelId,
                c = tr.CasoId,
                de = tr.De?.Nome(),
                para = tr.Para.Nome(),
                motivo = tr.Motivo,
                ct = tr.CausadoPorTipo,
                ci = tr.CausadoPorId,
                ator = tr.Ator,
                rv = tr.RegraVersaoId,
            }, Tx);
    }

    public async Task LigarFatoAsync(Guid entregavelId, Guid fatoId, string papel)
    {
        await Conn.ExecuteAsync(
            @"INSERT INTO fechamento.entregavel_fato (entregavel_id, fato_id, papel)
              VALUES (@e, @f, @p) ON CONFLICT DO NOTHING",
            new { e = entregavelId, f = fatoId, p = papel }, Tx);
    }

    public async Task<IReadOnlyList<Entregavel>> ComFatoLigadoAsync(Guid fatoId)
    {
        var rows = await Conn.QueryAsync<(Guid, Guid, string, string, string, string, int)>(
            $@"SELECT {Cols} FROM fechamento.entregavel e
               WHERE EXISTS (SELECT 1 FROM fechamento.entregavel_fato ef
                             WHERE ef.entregavel_id = e.id AND ef.fato_id = @f)
               ORDER BY e.tipo FOR UPDATE",
            new { f = fatoId }, Tx);
        return rows.Select(Mapear).ToList();
    }

    public async Task<IReadOnlyList<Entregavel>> DependentesDeAsync(Guid casoId, Guid dependeDeId)
    {
        var rows = await Conn.QueryAsync<(Guid, Guid, string, string, string, string, int)>(
            $@"SELECT {Cols} FROM fechamento.entregavel e
               WHERE e.caso_id = @c
                 AND EXISTS (SELECT 1 FROM fechamento.entregavel_dependencia d
                             WHERE d.entregavel_id = e.id AND d.depende_de_id = @dd)
               ORDER BY e.tipo FOR UPDATE",
            new { c = casoId, dd = dependeDeId }, Tx);
        return rows.Select(Mapear).ToList();
    }
}
