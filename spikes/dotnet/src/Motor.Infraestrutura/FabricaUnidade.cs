using Dapper;
using Motor.Aplicacao;
using Npgsql;

namespace Motor.Infraestrutura;

/// <summary>Fábrica de unidades de trabalho. Lê a string de conexão de MOTOR_DB.
/// Sem estado de negócio entre requisições: cada comando abre uma conexão/transação própria.</summary>
public sealed class FabricaUnidade : IFabricaUnidade
{
    private readonly string _conexao;

    public FabricaUnidade(string conexao) => _conexao = conexao;

    public async Task<IUnidadeDeTrabalho> AbrirAsync()
    {
        var conn = new NpgsqlConnection(_conexao);
        await conn.OpenAsync();
        var tx = await conn.BeginTransactionAsync();
        return new UnidadeDeTrabalho(conn, tx);
    }

    public async Task<bool> BancoRespondeAsync()
    {
        // Probe de readiness: uma falha de conexão (banco inalcançável, recusado, timeout) deve virar
        // resultado "não saudável" (=> 503 banco_indisponivel na rota), nunca uma exceção não tratada
        // que produziria 500. Capturamos as falhas de banco/rede e devolvemos false.
        try
        {
            await using var conn = new NpgsqlConnection(_conexao);
            await conn.OpenAsync();
            var um = await conn.ExecuteScalarAsync<int>("SELECT 1");
            return um == 1;
        }
        catch (Exception ex) when (ex is NpgsqlException or System.Data.Common.DbException
            or System.Net.Sockets.SocketException or TimeoutException or InvalidOperationException)
        {
            return false;
        }
    }

    public async Task<FilaStatus> ContarFilaAsync()
    {
        // Processamento síncrono nesta fase: pendentes é sempre 0 (design).
        await using var conn = new NpgsqlConnection(_conexao);
        await conn.OpenAsync();
        var pend = await conn.ExecuteScalarAsync<int>(
            "SELECT count(*)::int FROM eventos.fila WHERE concluido_em IS NULL");
        var erro = await conn.ExecuteScalarAsync<int>(
            "SELECT count(*)::int FROM eventos.fila WHERE ultimo_erro IS NOT NULL AND concluido_em IS NULL");
        return new FilaStatus(pend, erro);
    }
}
