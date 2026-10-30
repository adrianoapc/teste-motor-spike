using System.Diagnostics;
using Npgsql;
using Xunit;

namespace Motor.Testes.Unidade;

/// <summary>Integração de concorrência (§13): a trava consultiva por titular serializa mutações do MESMO
/// titular e NÃO bloqueia titulares diferentes. Vai direto ao Postgres com a mesma chave que o motor usa
/// (<c>hashtextextended('titular|' || titular_id, 0)</c>), sem subir a API.
///
/// Roda só quando <c>MOTOR_DB_TESTE</c> (DSN Npgsql) aponta para um Postgres com as migrações aplicadas;
/// o <c>dotnet test</c> offline pula (Skip). No CI/host: rode com
/// <c>MOTOR_DB_TESTE="Host=localhost;Port=5432;Database=spike_dotnet;Username=spike;Password=spike"</c>.</summary>
public class ConcorrenciaTitularTeste
{
    private static string? Dsn => Environment.GetEnvironmentVariable("MOTOR_DB_TESTE");

    private static async Task<NpgsqlConnection> AbrirAsync()
    {
        var c = new NpgsqlConnection(Dsn);
        await c.OpenAsync();
        return c;
    }

    /// <summary>Toma a trava por titular numa transação (igual ao motor, §13).</summary>
    private static async Task TravarAsync(NpgsqlConnection c, NpgsqlTransaction tx, string titular)
    {
        await using var cmd = new NpgsqlCommand(
            "SELECT pg_advisory_xact_lock(hashtextextended('titular|' || @t, 0))", c, tx);
        cmd.Parameters.AddWithValue("t", titular);
        await cmd.ExecuteNonQueryAsync();
    }

    [SkippableFact]
    public async Task Mesmo_titular_nao_se_intercala()
    {
        Skip.If(string.IsNullOrEmpty(Dsn), "MOTOR_DB_TESTE ausente");
        var titular = "TTEST-" + Guid.NewGuid().ToString("N")[..8];

        await using var cA = await AbrirAsync();
        await using var txA = await cA.BeginTransactionAsync();
        await TravarAsync(cA, txA, titular); // A segura a trava

        // B tenta a MESMA trava: tem de ficar bloqueado enquanto A não comita.
        await using var cB = await AbrirAsync();
        await using var txB = await cB.BeginTransactionAsync();
        var bPegou = Task.Run(async () =>
        {
            await TravarAsync(cB, txB, titular);
        });

        var bloqueadoPorUmPouco = await Task.WhenAny(bPegou, Task.Delay(500)) != bPegou;
        Assert.True(bloqueadoPorUmPouco, "B não deveria pegar a trava do mesmo titular enquanto A a segura");

        await txA.CommitAsync(); // libera
        var sw = Stopwatch.StartNew();
        await bPegou;            // agora B pega
        Assert.True(sw.ElapsedMilliseconds < 2000, "B deveria pegar a trava logo após o commit de A");
        await txB.CommitAsync();
    }

    [SkippableFact]
    public async Task Titulares_diferentes_nao_se_bloqueiam()
    {
        Skip.If(string.IsNullOrEmpty(Dsn), "MOTOR_DB_TESTE ausente");
        var t1 = "TTEST-" + Guid.NewGuid().ToString("N")[..8];
        var t2 = "TTEST-" + Guid.NewGuid().ToString("N")[..8];

        await using var cA = await AbrirAsync();
        await using var txA = await cA.BeginTransactionAsync();
        await TravarAsync(cA, txA, t1); // A segura t1

        // B toma a trava de t2 (titular diferente): não pode esperar por A.
        await using var cB = await AbrirAsync();
        await using var txB = await cB.BeginTransactionAsync();
        var bPegou = Task.Run(async () => await TravarAsync(cB, txB, t2));

        var pegouRapido = await Task.WhenAny(bPegou, Task.Delay(2000)) == bPegou;
        Assert.True(pegouRapido, "titular diferente não deveria ser bloqueado");

        await txA.CommitAsync();
        await txB.CommitAsync();
    }
}
