using System.Text.Json;
using Dapper;
using Motor.Aplicacao;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Npgsql;
using NpgsqlTypes;

namespace Motor.Infraestrutura;

internal sealed class EventoRepo : RepoBase, IEventoRepo
{
    public EventoRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    public async Task GravarAsync(string nome, Guid? casoId, Guid? entregavelId, JsonElement payload)
    {
        var cmd = new NpgsqlCommand(
            @"INSERT INTO eventos.evento (nome, caso_id, entregavel_id, payload)
              VALUES (@n, @c, @e, @p)", Conn, Tx);
        cmd.Parameters.AddWithValue("n", nome);
        cmd.Parameters.AddWithValue("c", (object?)casoId ?? DBNull.Value);
        cmd.Parameters.AddWithValue("e", (object?)entregavelId ?? DBNull.Value);
        cmd.Parameters.Add(new NpgsqlParameter("p", NpgsqlDbType.Jsonb) { Value = payload.GetRawText() });
        await cmd.ExecuteNonQueryAsync();
    }

    public async Task GravarComIdAsync(string nome, Guid? casoId, Guid? entregavelId, string chave, Guid valor)
    {
        var payload = JsonSerializer.Serialize(new Dictionary<string, string> { [chave] = valor.ToString() });
        using var doc = JsonDocument.Parse(payload);
        await GravarAsync(nome, casoId, entregavelId, doc.RootElement);
    }

    public async Task GravarComDoisIdsAsync(string nome, Guid? casoId, Guid? entregavelId,
        string chave1, Guid valor1, string chave2, Guid valor2)
    {
        var payload = JsonSerializer.Serialize(new Dictionary<string, string>
        {
            [chave1] = valor1.ToString(),
            [chave2] = valor2.ToString(),
        });
        using var doc = JsonDocument.Parse(payload);
        await GravarAsync(nome, casoId, entregavelId, doc.RootElement);
    }
}
