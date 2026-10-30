using System.Text.Json;
using Dapper;
using Motor.Aplicacao;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fatos;
using Motor.Dominio.Fechamento;
using Npgsql;
using NpgsqlTypes;

namespace Motor.Infraestrutura;

internal sealed class FatoRepo : RepoBase, IFatoRepo
{
    public FatoRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    public async Task TravarChaveAsync(string titularId, Competencia competencia, string tipo, string tributo)
    {
        // Lock de 2 inteiros derivado da chave do fato; liberado automaticamente no fim da transação.
        var chave = $"{titularId}|{competencia}|{tipo}|{tributo}";
        var bytes = System.Security.Cryptography.SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(chave));
        var k1 = BitConverter.ToInt32(bytes, 0);
        var k2 = BitConverter.ToInt32(bytes, 4);
        await Conn.ExecuteAsync("SELECT pg_advisory_xact_lock(@k1, @k2)", new { k1, k2 }, Tx);
    }

    public async Task<ContratoTipoFato?> ContratoAtivoAsync(string tipo)
    {
        // A única versão 'ativa' do tipo (índice tipo_fato_uma_ativa garante no máximo uma).
        var r = await Conn.QuerySingleOrDefaultAsync<(int versao, string esquema, string qualificador,
            string valorCentavos, string[] camposHash, string ordenarArrays, string[] fontes)?>(
            @"SELECT versao, esquema::text AS esquema, qualificador::text AS qualificador,
                     valor_centavos AS valorCentavos, campos_hash AS camposHash,
                     ordenar_arrays::text AS ordenarArrays, fontes_permitidas AS fontes
              FROM fatos.tipo_fato_versao WHERE tipo=@tp AND estado='ativa'",
            new { tp = tipo }, Tx);
        if (r is null) return null;
        var v = r.Value;
        using var esquema = JsonDocument.Parse(v.esquema);
        using var qualificador = JsonDocument.Parse(v.qualificador);
        using var ordenar = JsonDocument.Parse(v.ordenarArrays);
        return ContratoTipoFato.De(tipo, v.versao, esquema.RootElement, qualificador.RootElement,
            v.valorCentavos, v.camposHash, ordenar.RootElement, v.fontes);
    }

    private static FatoVigente Mapear((Guid id, string titular, string comp, string tipo, string tributo,
        int versao, long? valor, string payload, string hash, int? tipoVersao, string fonte, DateTime obs) r) =>
        new(r.id, r.titular, Competencia.Analisar(r.comp.Trim()), r.tipo, r.tributo, r.versao,
            r.valor, Json.Parse(r.payload), r.hash, r.tipoVersao, r.fonte,
            new DateTimeOffset(DateTime.SpecifyKind(r.obs, DateTimeKind.Utc)));

    private const string Cols =
        "id, titular_id AS titular, competencia AS comp, tipo, tributo, versao, " +
        "valor_centavos AS valor, payload::text AS payload, hash, tipo_versao AS tipoVersao, " +
        "fonte, observado_em AS obs";

    public async Task<FatoVigente?> VigenteAsync(string titularId, Competencia competencia, string tipo, string tributo)
    {
        var r = await Conn.QuerySingleOrDefaultAsync<(Guid, string, string, string, string, int, long?, string, string, int?, string, DateTime)?>(
            $"SELECT {Cols} FROM fatos.fato_vigente WHERE titular_id=@t AND competencia=@c AND tipo=@tp AND tributo=@tr",
            new { t = titularId, c = competencia.ToString(), tp = tipo, tr = tributo }, Tx);
        return r is null ? null : Mapear(r.Value);
    }

    public async Task<(Guid Id, int Versao)> GravarAsync(PublicarFatoCmd cmd, int versao, string hash,
        Guid? substituiId, int tipoVersao)
    {
        var c = new NpgsqlCommand(
            @"INSERT INTO fatos.fato
              (titular_id, competencia, tipo, tributo, versao, valor_centavos, payload, fonte, origem_ref, hash, substitui_id, observado_em, tipo_versao)
              VALUES (@t, @c, @tp, @tr, @v, @val, @pl, @f, @or, @h, @sub, @obs, @tv) RETURNING id", Conn, Tx);
        c.Parameters.AddWithValue("t", cmd.TitularId);
        c.Parameters.AddWithValue("c", cmd.Competencia.ToString());
        c.Parameters.AddWithValue("tp", cmd.Tipo);
        c.Parameters.AddWithValue("tr", cmd.Tributo);
        c.Parameters.AddWithValue("v", versao);
        c.Parameters.AddWithValue("val", (object?)cmd.ValorCentavos ?? DBNull.Value);
        c.Parameters.Add(new NpgsqlParameter("pl", NpgsqlDbType.Jsonb) { Value = cmd.Payload.GetRawText() });
        c.Parameters.AddWithValue("f", cmd.Fonte);
        c.Parameters.AddWithValue("or", (object?)cmd.OrigemRef ?? DBNull.Value);
        c.Parameters.AddWithValue("h", hash);
        c.Parameters.AddWithValue("sub", (object?)substituiId ?? DBNull.Value);
        c.Parameters.AddWithValue("obs", cmd.ObservadoEm.ToUniversalTime());
        c.Parameters.AddWithValue("tv", tipoVersao);
        var id = (Guid)(await c.ExecuteScalarAsync())!;
        return (id, versao);
    }

    public async Task<IReadOnlyList<FatoVigente>> VigentesDoCasoAsync(string titularId, Competencia competencia)
    {
        var rows = await Conn.QueryAsync<(Guid, string, string, string, string, int, long?, string, string, int?, string, DateTime)>(
            $"SELECT {Cols} FROM fatos.fato_vigente WHERE titular_id=@t AND competencia=@c",
            new { t = titularId, c = competencia.ToString() }, Tx);
        return rows.Select(Mapear).ToList();
    }

    public async Task<RecebimentoRepetido?> RecebimentoPorChaveAsync(string fonte, string chaveIdempotencia)
    {
        // A resposta repetida vem do PRIMEIRO recebimento com a mesma (fonte, chave): o efeito e o fato
        // gravados então. Índice único recebimento_idempotencia garante no máximo um.
        var r = await Conn.QuerySingleOrDefaultAsync<(Guid fatoId, string efeito, int versao)?>(
            @"SELECT rec.fato_id AS fatoId, rec.efeito AS efeito, f.versao AS versao
              FROM fatos.recebimento rec JOIN fatos.fato f ON f.id = rec.fato_id
              WHERE rec.fonte=@f AND rec.chave_idempotencia=@k",
            new { f = fonte, k = chaveIdempotencia }, Tx);
        return r is null ? null : new RecebimentoRepetido(r.Value.fatoId, r.Value.versao, r.Value.efeito);
    }

    public async Task TravarChaveIdempotenciaAsync(string fonte, string chaveIdempotencia)
    {
        // Serializa a checagem-e-inserção de recebimento por (fonte, chave) ENTRE TITULARES: o índice
        // recebimento_idempotencia é global, então o lock de titular não cobre dois publishers de
        // titulares diferentes com a mesma chave. Chave calculada no Postgres (hashtextextended), igual
        // ao lock de titular (§13), para ser idêntica nas três spikes. Liberado no fim da transação.
        await Conn.ExecuteAsync(
            "SELECT pg_advisory_xact_lock(hashtextextended('idem|' || @f || '|' || @k, 0))",
            new { f = fonte, k = chaveIdempotencia }, Tx);
    }

    public async Task GravarRecebimentoAsync(NovoRecebimento rec)    {
        var c = new NpgsqlCommand(
            @"INSERT INTO fatos.recebimento
              (titular_id, competencia, tipo, tributo, fonte, chave_idempotencia, observado_em, tipo_versao, hash, efeito, fato_id, payload, valor_centavos)
              VALUES (@t, @c, @tp, @tr, @f, @k, @obs, @tv, @h, @ef, @fid, @pl, @val)", Conn, Tx);
        c.Parameters.AddWithValue("t", rec.TitularId);
        c.Parameters.AddWithValue("c", rec.Competencia.ToString());
        c.Parameters.AddWithValue("tp", rec.Tipo);
        c.Parameters.AddWithValue("tr", rec.Tributo);
        c.Parameters.AddWithValue("f", rec.Fonte);
        c.Parameters.AddWithValue("k", (object?)rec.ChaveIdempotencia ?? DBNull.Value);
        c.Parameters.AddWithValue("obs", rec.ObservadoEm.ToUniversalTime());
        c.Parameters.AddWithValue("tv", rec.TipoVersao);
        c.Parameters.AddWithValue("h", rec.Hash);
        c.Parameters.AddWithValue("ef", rec.Efeito);
        c.Parameters.AddWithValue("fid", rec.FatoId);
        c.Parameters.Add(new NpgsqlParameter("pl", NpgsqlDbType.Jsonb) { Value = rec.Payload.GetRawText() });
        c.Parameters.AddWithValue("val", (object?)rec.ValorCentavos ?? DBNull.Value);
        await c.ExecuteNonQueryAsync();
    }
}
