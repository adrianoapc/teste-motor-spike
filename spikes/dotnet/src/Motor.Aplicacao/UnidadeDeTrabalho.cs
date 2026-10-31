using System.Text.Json;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;

namespace Motor.Aplicacao;

/// <summary>Uma unidade de trabalho = uma transação. Todos os repositórios desta instância
/// operam na mesma transação. Confirmada com <see cref="Confirmar"/> ou descartada no Dispose.</summary>
public interface IUnidadeDeTrabalho : IAsyncDisposable
{
    IRepositorios Repos { get; }
    Task ConfirmarAsync();
    /// <summary>Instante do banco (now()), para gravar observado_em etc.</summary>
    Task<DateTimeOffset> AgoraAsync();
}

/// <summary>Fábrica de unidades de trabalho (uma transação por comando).</summary>
public interface IFabricaUnidade
{
    Task<IUnidadeDeTrabalho> AbrirAsync();
    /// <summary>Conexão simples sem transação, para leituras de saúde/fila.</summary>
    Task<bool> BancoRespondeAsync();
    Task<FilaStatus> ContarFilaAsync();
}

public sealed record FilaStatus(int Pendentes, int ComErro);

/// <summary>Conjunto de repositórios ligados a uma transação.</summary>
public interface IRepositorios
{
    IRegrasRepo Regras { get; }
    ICasoRepo Casos { get; }
    IEntregavelRepo Entregaveis { get; }
    IPrazosRepo Prazos { get; }
    IFatoRepo Fatos { get; }
    IConferenciaRepo Conferencias { get; }
    ITarefaRepo Tarefas { get; }
    IExcecaoRepo Excecoes { get; }
    IEventoRepo Eventos { get; }
}
