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
        await using var conn = new NpgsqlConnection(_conexao);
        await conn.OpenAsync();
        var um = await conn.ExecuteScalarAsync<int>("SELECT 1");
        return um == 1;
    }

    public async Task<(int pendentes, int comErro)> ContarFilaAsync()
    {
        // Processamento síncrono nesta fase: pendentes é sempre 0 (design).
        await using var conn = new NpgsqlConnection(_conexao);
        await conn.OpenAsync();
        var pend = await conn.ExecuteScalarAsync<int>(
            "SELECT count(*)::int FROM eventos.fila WHERE concluido_em IS NULL");
        var erro = await conn.ExecuteScalarAsync<int>(
            "SELECT count(*)::int FROM eventos.fila WHERE ultimo_erro IS NOT NULL AND concluido_em IS NULL");
        return (pend, erro);
    }

    public async Task<CasoConsulta?> ConsultarCasoAsync(string titularId, string competencia)
    {
        await using var conn = new NpgsqlConnection(_conexao);
        await conn.OpenAsync();

        var caso = await conn.QuerySingleOrDefaultAsync<(Guid id, string titular, string comp, string estado)?>(
            @"SELECT id, titular_id AS titular, competencia AS comp, estado
              FROM fechamento.caso_competencia WHERE titular_id=@t AND competencia=@c",
            new { t = titularId, c = competencia });
        if (caso is null) return null;
        var casoId = caso.Value.id;

        var ents = (await conn.QueryAsync<(Guid id, string tipo, string estado, string executor)>(
            @"SELECT id, tipo, estado, executor FROM fechamento.entregavel WHERE caso_id=@c ORDER BY tipo",
            new { c = casoId }))
            .Select(e => new EntregavelConsulta(e.id, e.tipo, e.estado, e.executor)).ToList();

        var tarefas = (await conn.QueryAsync<(Guid id, string tipo)>(
            @"SELECT t.id, e.tipo FROM trabalho.tarefa_humana t
              JOIN fechamento.entregavel e ON e.id = t.entregavel_id
              WHERE t.caso_id=@c AND t.estado='aberta'", new { c = casoId }))
            .Select(t => new TarefaConsulta(t.id, t.tipo)).ToList();

        var excecoes = (await conn.QueryAsync<(Guid id, string tipo, string classe)>(
            @"SELECT id, tipo, classe FROM trabalho.excecao WHERE caso_id=@c AND estado='aberta'",
            new { c = casoId }))
            .Select(x => new ExcecaoConsulta(x.id, x.tipo, x.classe)).ToList();

        return new CasoConsulta(casoId, caso.Value.titular, caso.Value.comp.Trim(), caso.Value.estado,
            ents, tarefas, excecoes);
    }
}
