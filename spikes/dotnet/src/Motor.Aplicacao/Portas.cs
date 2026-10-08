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
    Task<(int pendentes, int comErro)> ContarFilaAsync();
    /// <summary>Leitura do caso para GET /casos (read-only).</summary>
    Task<CasoConsulta?> ConsultarCasoAsync(string titularId, string competencia);
}

public sealed record CasoConsulta(
    Guid CasoId, string TitularId, string Competencia, string Estado,
    IReadOnlyList<EntregavelConsulta> Entregaveis,
    IReadOnlyList<TarefaConsulta> TarefasAbertas,
    IReadOnlyList<ExcecaoConsulta> ExcecoesAbertas);

public sealed record EntregavelConsulta(Guid EntregavelId, string Tipo, string Estado, string Executor);
public sealed record TarefaConsulta(Guid TarefaId, string EntregavelTipo);
public sealed record ExcecaoConsulta(Guid ExcecaoId, string Tipo, string Classe);

/// <summary>Conjunto de repositórios ligados a uma transação.</summary>
public interface IRepositorios
{
    IRegrasRepo Regras { get; }
    ICatalogoRepo Catalogo { get; }
    ICasoRepo Casos { get; }
    IEntregavelRepo Entregaveis { get; }
    IFatoRepo Fatos { get; }
    IConferenciaRepo Conferencias { get; }
    ITarefaRepo Tarefas { get; }
    IExcecaoRepo Excecoes { get; }
    IEventoRepo Eventos { get; }
}

public interface IRegrasRepo
{
    /// <summary>Versão em uso (status provisoria|ativa) de uma chave de regra. (id, conteudo)</summary>
    Task<(Guid Id, JsonElement Conteudo)?> VersaoEmUsoAsync(string chave);

    /// <summary>Conteúdo de uma versão de regra pelo seu id — usado para carregar a versão
    /// FIXADA no caso (caso.RegraVersaoId), e não a versão atualmente em uso. (§13, imutabilidade)</summary>
    Task<JsonElement?> ConteudoPorIdAsync(Guid versaoId);
}

public sealed record EntregavelTipoCatalogo(string Chave, string Area, string? EventoPublicado);

public sealed record ConferenciaTipoCatalogo(
    string Chave, string EntregavelTipo, string Severidade, string ClassePadrao, bool NoSpike);

public interface ICatalogoRepo
{
    Task<EntregavelTipoCatalogo?> EntregavelTipoAsync(string tipo);
    Task<ConferenciaTipoCatalogo?> ConferenciaTipoAsync(string cf);
}

public interface ICasoRepo
{
    /// <summary>Trava transacional (pg_advisory_xact_lock) na chave do CASO (titular+competência).
    /// Tomada tanto na abertura quanto na publicação de fato para que uma transação sempre
    /// observe o estado comitado da outra — sem isto, abrir e publicar o mesmo (titular,competência)
    /// em paralelo deixa o entregável preso em aguardando_insumo (§6).</summary>
    Task TravarCasoAsync(string titularId, Competencia competencia);
    Task<Caso?> PorChaveAsync(string titularId, Competencia competencia);
    Task<Caso?> CriarAsync(string titularId, Competencia competencia, JsonElement snapshot,
        string carteira, Guid regraVersaoId);
    /// <summary>Casos ABERTOS do titular, com trava, para reavaliação após fato.</summary>
    Task<IReadOnlyList<Caso>> AbertosDoTitularAsync(string titularId);
    Task EncerrarAsync(Guid casoId, DateTimeOffset quando);
}

public interface IEntregavelRepo
{
    /// <summary>Carrega os entregáveis do caso COM trava de linha (FOR UPDATE), ordenados por tipo.</summary>
    Task<IReadOnlyList<Entregavel>> DoCasoComTravaAsync(Guid casoId);
    Task<Entregavel?> PorChaveAsync(Guid casoId, string tipo);
    /// <summary>Como PorChaveAsync, mas trava a LINHA (FOR UPDATE) — para reivindicar o
    /// entregável antes de validar estado em conclusão de tarefa concorrente (§8).</summary>
    Task<Entregavel?> PorChaveComTravaAsync(Guid casoId, string tipo);
    Task<Entregavel> CriarAsync(Guid casoId, string tipo, string executor, string area);
    Task GravarDependenciaAsync(Guid entregavelId, Guid dependeDeId, EstadoEntregavel estadoMinimo);
    Task<IReadOnlyList<Dependencia>> DependenciasDoAsync(Guid entregavelId);
    /// <summary>Aplica a transição: UPDATE estado/estado_desde/version + INSERT na transição (§4.1).</summary>
    Task TransicionarAsync(Entregavel atual, NovaTransicao transicao, DateTimeOffset agora);
    Task LigarFatoAsync(Guid entregavelId, Guid fatoId, string papel);
}

public interface IFatoRepo
{
    /// <summary>Trava transacional (pg_advisory_xact_lock) na chave do fato, serializando
    /// publicações concorrentes da MESMA chave para que a alocação de versão não colida
    /// no índice único (titular_id, competencia, tipo, tributo, versao).</summary>
    Task TravarChaveAsync(string titularId, Competencia competencia, string tipo, string tributo);
    Task<FatoVigente?> VigenteAsync(string titularId, Competencia competencia, string tipo, string tributo);
    /// <summary>Grava nova versão do fato e devolve (id, versao).</summary>
    Task<(Guid Id, int Versao)> GravarAsync(PublicarFatoCmd cmd, int versao, string hash, Guid? substituiId);
    /// <summary>Todos os fatos vigentes do caso (mesmo titular+competência).</summary>
    Task<IReadOnlyList<FatoVigente>> VigentesDoCasoAsync(string titularId, Competencia competencia);
}

/// <summary>Dados completos para gravar uma conferência (§9.2).</summary>
public sealed record NovaConferencia(
    string Cf, Guid CasoId, Guid EntregavelId, Guid RegraVersaoId,
    IReadOnlyList<FatoUsado> FatosUsados, string EntradaHash,
    ResultadoConferencia Resultado, long? EsperadoCentavos, long? ObtidoCentavos,
    JsonElement Diferenca, string Severidade, string Classe);

public interface IConferenciaRepo
{
    /// <summary>Insere se não existir (idempotência por cf+entregavel+entrada_hash); devolve
    /// (id, resultado) da conferência vigente para aquela chave.</summary>
    Task<(Guid Id, ResultadoConferencia Resultado)> InserirOuReaproveitarAsync(NovaConferencia c);
}

public interface ITarefaRepo
{
    Task<Guid> CriarAsync(Guid casoId, Guid entregavelId, string tipo, string responsavel);
    Task<Guid?> AbertaDoEntregavelAsync(Guid entregavelId);
    Task ConcluirAsync(Guid tarefaId, DateTimeOffset quando);
    Task CancelarAbertasAsync(Guid entregavelId);
}

public interface IExcecaoRepo
{
    Task<Guid> AbrirAsync(Guid casoId, Guid entregavelId, string tipo, string classe, string dono);
}

public interface IEventoRepo
{
    Task GravarAsync(string nome, Guid? casoId, Guid? entregavelId, JsonElement payload);
    /// <summary>Conveniência: payload de um único id.</summary>
    Task GravarComIdAsync(string nome, Guid? casoId, Guid? entregavelId, string chave, Guid valor);
}
