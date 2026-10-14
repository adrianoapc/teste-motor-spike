using System.Text.Json;
using Dapper;
using Motor.Aplicacao;
using Motor.Dominio.Conferencias;
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

    private static FatoVigente Mapear((Guid id, string titular, string comp, string tipo, string tributo,
        int versao, long? valor, string payload, string hash) r) =>
        new(r.id, r.titular, Competencia.Analisar(r.comp.Trim()), r.tipo, r.tributo, r.versao,
            r.valor, Json.Parse(r.payload), r.hash);

    private const string Cols =
        "id, titular_id AS titular, competencia AS comp, tipo, tributo, versao, " +
        "valor_centavos AS valor, payload::text AS payload, hash";

    public async Task<FatoVigente?> VigenteAsync(string titularId, Competencia competencia, string tipo, string tributo)
    {
        var r = await Conn.QuerySingleOrDefaultAsync<(Guid, string, string, string, string, int, long?, string, string)?>(
            $"SELECT {Cols} FROM fatos.fato_vigente WHERE titular_id=@t AND competencia=@c AND tipo=@tp AND tributo=@tr",
            new { t = titularId, c = competencia.ToString(), tp = tipo, tr = tributo }, Tx);
        return r is null ? null : Mapear(r.Value);
    }

    public async Task<(Guid Id, int Versao)> GravarAsync(PublicarFatoCmd cmd, int versao, string hash, Guid? substituiId)
    {
        var c = new NpgsqlCommand(
            @"INSERT INTO fatos.fato
              (titular_id, competencia, tipo, tributo, versao, valor_centavos, payload, fonte, origem_ref, hash, substitui_id, observado_em)
              VALUES (@t, @c, @tp, @tr, @v, @val, @pl, @f, @or, @h, @sub, @obs) RETURNING id", Conn, Tx);
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
        var id = (Guid)(await c.ExecuteScalarAsync())!;
        return (id, versao);
    }

    public async Task<IReadOnlyList<FatoVigente>> VigentesDoCasoAsync(string titularId, Competencia competencia)
    {
        var rows = await Conn.QueryAsync<(Guid, string, string, string, string, int, long?, string, string)>(
            $"SELECT {Cols} FROM fatos.fato_vigente WHERE titular_id=@t AND competencia=@c",
            new { t = titularId, c = competencia.ToString() }, Tx);
        return rows.Select(Mapear).ToList();
    }
}
