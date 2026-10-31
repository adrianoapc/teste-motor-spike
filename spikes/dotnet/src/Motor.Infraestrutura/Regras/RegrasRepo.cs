using System.Text.Json;
using Dapper;
using Motor.Aplicacao;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Npgsql;
using NpgsqlTypes;

namespace Motor.Infraestrutura;

internal sealed class RegrasRepo : RepoBase, IRegrasRepo
{
    public RegrasRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    public async Task<RegraVersao?> VersaoEmUsoAsync(string chave)
    {
        var row = await Conn.QuerySingleOrDefaultAsync<(Guid id, string conteudo)?>(
            @"SELECT id, conteudo::text AS conteudo FROM regras.regra_versao
              WHERE regra_chave = @chave AND status IN ('provisoria','ativa') LIMIT 1",
            new { chave }, Tx);
        if (row is null) return null;
        return new RegraVersao(row.Value.id, Json.Parse(row.Value.conteudo));
    }

    public async Task<JsonElement?> ConteudoPorIdAsync(Guid versaoId)
    {
        var conteudo = await Conn.QuerySingleOrDefaultAsync<string?>(
            @"SELECT conteudo::text FROM regras.regra_versao WHERE id = @id",
            new { id = versaoId }, Tx);
        return conteudo is null ? null : Json.Parse(conteudo);
    }

    public async Task<int> NumeroDaVersaoAsync(Guid versaoId)
    {
        return await Conn.QuerySingleAsync<int>(
            @"SELECT versao FROM regras.regra_versao WHERE id = @id",
            new { id = versaoId }, Tx);
    }
}
