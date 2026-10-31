using System.Text.Json;
using Dapper;
using Motor.Aplicacao;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Npgsql;
using NpgsqlTypes;

namespace Motor.Infraestrutura;

public sealed class UnidadeDeTrabalho : IUnidadeDeTrabalho
{
    private readonly NpgsqlConnection _conn;
    private readonly NpgsqlTransaction _tx;
    public IRepositorios Repos { get; }

    public UnidadeDeTrabalho(NpgsqlConnection conn, NpgsqlTransaction tx)
    {
        _conn = conn;
        _tx = tx;
        Repos = new Repositorios(conn, tx);
    }

    public async Task ConfirmarAsync() => await _tx.CommitAsync();

    public async Task<DateTimeOffset> AgoraAsync()
    {
        // Npgsql mapeia timestamptz para DateTime (Kind=Utc); envolvemos em DateTimeOffset UTC.
        var dt = await _conn.ExecuteScalarAsync<DateTime>("SELECT now()", transaction: _tx);
        return new DateTimeOffset(DateTime.SpecifyKind(dt, DateTimeKind.Utc));
    }

    public async ValueTask DisposeAsync()
    {
        await _tx.DisposeAsync();
        await _conn.DisposeAsync();
    }
}

internal static class Json
{
    public static JsonElement Parse(string? texto)
    {
        using var doc = JsonDocument.Parse(string.IsNullOrEmpty(texto) ? "{}" : texto);
        return doc.RootElement.Clone();
    }
}

internal sealed class Repositorios : IRepositorios
{
    public Repositorios(NpgsqlConnection c, NpgsqlTransaction t)
    {
        Regras = new RegrasRepo(c, t);
        Casos = new CasoRepo(c, t);
        Entregaveis = new EntregavelRepo(c, t);
        Prazos = new PrazoRepo(c, t);
        Fatos = new FatoRepo(c, t);
        Conferencias = new ConferenciaRepo(c, t);
        Tarefas = new TarefaRepo(c, t);
        Excecoes = new ExcecaoRepo(c, t);
        Motivos = new MotivoCatalogoRepo(c, t);
        Eventos = new EventoRepo(c, t);
    }

    public IRegrasRepo Regras { get; }
    public ICasoRepo Casos { get; }
    public IEntregavelRepo Entregaveis { get; }
    public IPrazosRepo Prazos { get; }
    public IFatoRepo Fatos { get; }
    public IConferenciaRepo Conferencias { get; }
    public ITarefaRepo Tarefas { get; }
    public IExcecaoRepo Excecoes { get; }
    public IMotivoCatalogoRepo Motivos { get; }
    public IEventoRepo Eventos { get; }
}

internal abstract class RepoBase
{
    protected readonly NpgsqlConnection Conn;
    protected readonly NpgsqlTransaction Tx;
    protected RepoBase(NpgsqlConnection c, NpgsqlTransaction t) { Conn = c; Tx = t; }
}
