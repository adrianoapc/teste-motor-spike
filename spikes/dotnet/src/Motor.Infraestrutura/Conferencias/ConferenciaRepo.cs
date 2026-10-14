using System.Text.Json;
using Dapper;
using Motor.Aplicacao;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Npgsql;
using NpgsqlTypes;

namespace Motor.Infraestrutura;

internal sealed class ConferenciaRepo : RepoBase, IConferenciaRepo
{
    public ConferenciaRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    public async Task<(Guid Id, ResultadoConferencia Resultado)> InserirOuReaproveitarAsync(NovaConferencia c)
    {
        // já existe? reaproveita (idempotência por cf+entregavel+entrada_hash)
        var existente = await Conn.QuerySingleOrDefaultAsync<(Guid id, string res)?>(
            @"SELECT id, resultado AS res FROM conferencias.conferencia
              WHERE cf=@cf AND entregavel_id=@e AND entrada_hash=@h",
            new { cf = c.Cf, e = c.EntregavelId, h = c.EntradaHash }, Tx);
        if (existente is { } ex)
            return (ex.id, ParseResultado(ex.res));

        // Mesma ordenação canônica usada por EntradaHash.Calcular (fato_id ordinal): o audit
        // persistido tem de reproduzir exatamente a entrada representada por entrada_hash (§9.2).
        var fatosUsadosJson = JsonSerializer.Serialize(
            c.FatosUsados
                .OrderBy(f => f.FatoId.ToString("D"), StringComparer.Ordinal)
                .Select(f => new { fato_id = f.FatoId, versao = f.Versao }));

        var cmd = new NpgsqlCommand(
            @"INSERT INTO conferencias.conferencia
              (cf, caso_id, entregavel_id, regra_versao_id, fatos_usados, entrada_hash, resultado,
               esperado_centavos, obtido_centavos, diferenca, severidade, classe, semantica_calculo)
              VALUES (@cf, @caso, @e, @rv, @fu, @h, @res, @esp, @obt, @dif, @sev, @cl, @sem)
              ON CONFLICT (cf, entregavel_id, entrada_hash) DO NOTHING
              RETURNING id", Conn, Tx);
        cmd.Parameters.AddWithValue("cf", c.Cf);
        cmd.Parameters.AddWithValue("caso", c.CasoId);
        cmd.Parameters.AddWithValue("e", c.EntregavelId);
        cmd.Parameters.AddWithValue("rv", c.RegraVersaoId);
        cmd.Parameters.Add(new NpgsqlParameter("fu", NpgsqlDbType.Jsonb) { Value = fatosUsadosJson });
        cmd.Parameters.AddWithValue("h", c.EntradaHash);
        cmd.Parameters.AddWithValue("res", NomeResultado(c.Resultado));
        cmd.Parameters.AddWithValue("esp", (object?)c.EsperadoCentavos ?? DBNull.Value);
        cmd.Parameters.AddWithValue("obt", (object?)c.ObtidoCentavos ?? DBNull.Value);
        cmd.Parameters.Add(new NpgsqlParameter("dif", NpgsqlDbType.Jsonb) { Value = c.Diferenca.GetRawText() });
        cmd.Parameters.AddWithValue("sev", c.Severidade);
        cmd.Parameters.AddWithValue("cl", c.Classe);
        cmd.Parameters.AddWithValue("sem", c.SemanticaCalculo);
        var inserido = await cmd.ExecuteScalarAsync();
        if (inserido is Guid gid)
            return (gid, c.Resultado);

        // corrida: alguém inseriu entre o SELECT e o INSERT; relê
        var r2 = await Conn.QuerySingleAsync<(Guid id, string res)>(
            @"SELECT id, resultado AS res FROM conferencias.conferencia
              WHERE cf=@cf AND entregavel_id=@e AND entrada_hash=@h",
            new { cf = c.Cf, e = c.EntregavelId, h = c.EntradaHash }, Tx);
        return (r2.id, ParseResultado(r2.res));
    }

    public async Task<ConferenciaTipoCatalogo?> TipoCatalogoAsync(string cf)
    {
        var row = await Conn.QuerySingleOrDefaultAsync<(string sev, string classe)?>(
            @"SELECT severidade AS sev, classe_padrao AS classe
              FROM conferencias.conferencia_tipo WHERE chave = @cf", new { cf }, Tx);
        return row is null ? null : new ConferenciaTipoCatalogo(row.Value.sev, row.Value.classe);
    }

    public async Task<ConferenciaVigente?> VigenteDaCfAsync(Guid entregavelId, string cf)
    {
        var r = await Conn.QuerySingleOrDefaultAsync<(Guid id, string res, string sev, bool temOv)?>(
            @"SELECT id, resultado AS res, severidade AS sev, (override_autor IS NOT NULL) AS temOv
              FROM conferencias.conferencia
              WHERE entregavel_id = @e AND cf = @cf
              ORDER BY em DESC LIMIT 1",
            new { e = entregavelId, cf }, Tx);
        return r is null ? null : new ConferenciaVigente(r.Value.id, r.Value.res, r.Value.sev, r.Value.temOv);
    }

    public async Task GravarOverrideAsync(Guid conferenciaId, string autor, string motivo)
    {
        await Conn.ExecuteAsync(
            @"UPDATE conferencias.conferencia
              SET override_autor = @a, override_motivo = @m, override_em = now()
              WHERE id = @id",
            new { id = conferenciaId, a = autor, m = motivo }, Tx);
    }

    public async Task<bool> TemOutraDivergenteBSemOverrideAsync(Guid entregavelId, Guid excetoConferenciaId)
    {
        // Conferência vigente (maior em) por CF deste entregável; alguma divergente B sem override,
        // além da que acabou de receber override (§8.1 passo 4).
        var n = await Conn.ExecuteScalarAsync<int>(
            @"WITH vigente AS (
                  SELECT DISTINCT ON (cf) id, resultado, severidade, override_autor
                  FROM conferencias.conferencia
                  WHERE entregavel_id = @e
                  ORDER BY cf, em DESC
              )
              SELECT count(*) FROM vigente
              WHERE id <> @exc AND resultado = 'divergente' AND severidade = 'B' AND override_autor IS NULL",
            new { e = entregavelId, exc = excetoConferenciaId }, Tx);
        return n > 0;
    }

    private static string NomeResultado(ResultadoConferencia r) => r switch
    {
        ResultadoConferencia.Ok => "ok",
        ResultadoConferencia.Divergente => "divergente",
        ResultadoConferencia.Informativo => "informativo",
        _ => throw new ArgumentOutOfRangeException(nameof(r), r, null),
    };

    private static ResultadoConferencia ParseResultado(string s) => s switch
    {
        "ok" => ResultadoConferencia.Ok,
        "divergente" => ResultadoConferencia.Divergente,
        "informativo" => ResultadoConferencia.Informativo,
        _ => throw new ArgumentOutOfRangeException(nameof(s), s, null),
    };
}
