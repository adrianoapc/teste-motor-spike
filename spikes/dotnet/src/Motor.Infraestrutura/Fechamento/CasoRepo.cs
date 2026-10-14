using System.Text.Json;
using Dapper;
using Motor.Aplicacao;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Npgsql;
using NpgsqlTypes;

namespace Motor.Infraestrutura;

internal sealed class CasoRepo : RepoBase, ICasoRepo
{
    public CasoRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    private static Caso Mapear((Guid id, string titular, string comp, string snapshot, string carteira,
        Guid regraVid, string estado, int version) r) =>
        new(r.id, r.titular, Competencia.Analisar(r.comp.Trim()), Json.Parse(r.snapshot),
            r.carteira, r.regraVid, r.estado, r.version);

    private const string Cols =
        "id, titular_id AS titular, competencia AS comp, snapshot::text AS snapshot, carteira, " +
        "regra_entregaveis_versao_id AS regraVid, estado, version";

    public async Task<Caso?> PorChaveAsync(string titularId, Competencia competencia)
    {
        var r = await Conn.QuerySingleOrDefaultAsync<(Guid, string, string, string, string, Guid, string, int)?>(
            $"SELECT {Cols} FROM fechamento.caso_competencia WHERE titular_id = @t AND competencia = @c",
            new { t = titularId, c = competencia.ToString() }, Tx);
        return r is null ? null : Mapear(r.Value);
    }

    public async Task<Caso?> PorIdAsync(Guid casoId)
    {
        var r = await Conn.QuerySingleOrDefaultAsync<(Guid, string, string, string, string, Guid, string, int)?>(
            $"SELECT {Cols} FROM fechamento.caso_competencia WHERE id = @id",
            new { id = casoId }, Tx);
        return r is null ? null : Mapear(r.Value);
    }

    public async Task TravarCasoAsync(string titularId)
    {
        // Lock por TITULAR. A reavaliação de um caso aberto lê
        // fatos de OUTRAS competências do mesmo titular (entradas competencia_relativa -1/-2 do C7),
        // então publicar o fato de 202607 pode destravar o E03 do caso 202609. Travar só
        // (titular,competência) deixaria publisher e opener de competências diferentes em locks
        // distintos — um não veria o estado não-comitado do outro, e o E03 ficaria preso em
        // aguardando_insumo. O lock por titular garante exclusão mútua entre QUALQUER abertura e
        // QUALQUER publicação do titular; liberado no fim da transação.
        var chave = $"caso|{titularId}";
        var bytes = System.Security.Cryptography.SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(chave));
        var k1 = BitConverter.ToInt32(bytes, 0);
        var k2 = BitConverter.ToInt32(bytes, 4);
        await Conn.ExecuteAsync("SELECT pg_advisory_xact_lock(@k1, @k2)", new { k1, k2 }, Tx);
    }

    public async Task<Caso?> CriarAsync(string titularId, Competencia competencia, JsonElement snapshot,
        string carteira, Guid regraVersaoId)
    {
        // INSERT atômico tolerante a conflito: duas aberturas concorrentes do mesmo
        // (titular, competência) não quebram com 23505 — a perdedora recebe id nulo (§ idempotência).
        var cmd = new NpgsqlCommand(
            @"INSERT INTO fechamento.caso_competencia
              (titular_id, competencia, snapshot, carteira, regra_entregaveis_versao_id, estado)
              VALUES (@t, @c, @s, @ca, @rv, 'aberto')
              ON CONFLICT (titular_id, competencia) DO NOTHING
              RETURNING id", Conn, Tx);
        cmd.Parameters.AddWithValue("t", titularId);
        cmd.Parameters.AddWithValue("c", competencia.ToString());
        cmd.Parameters.Add(new NpgsqlParameter("s", NpgsqlDbType.Jsonb) { Value = snapshot.GetRawText() });
        cmd.Parameters.AddWithValue("ca", carteira);
        cmd.Parameters.AddWithValue("rv", regraVersaoId);
        var res = await cmd.ExecuteScalarAsync();
        if (res is not Guid id) return null; // conflito: outra transação já abriu o caso
        return new Caso(id, titularId, competencia, snapshot, carteira, regraVersaoId, "aberto", 1);
    }

    public async Task<IReadOnlyList<Caso>> AbertosDoTitularAsync(string titularId)
    {
        var rows = await Conn.QueryAsync<(Guid, string, string, string, string, Guid, string, int)>(
            $"SELECT {Cols} FROM fechamento.caso_competencia WHERE titular_id = @t AND estado = 'aberto' FOR UPDATE",
            new { t = titularId }, Tx);
        return rows.Select(Mapear).ToList();
    }

    public async Task EncerrarAsync(Guid casoId)
    {
        await Conn.ExecuteAsync(
            @"UPDATE fechamento.caso_competencia
              SET estado = 'encerrado', encerrado_em = now(), version = version + 1 WHERE id = @id",
            new { id = casoId }, Tx);
    }
}
