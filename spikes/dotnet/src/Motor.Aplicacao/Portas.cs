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
    /// <summary>Trava transacional (pg_advisory_xact_lock) por TITULAR. Tomada na abertura, na
    /// publicação de fato e na conclusão para que uma transação sempre observe o estado comitado
    /// da outra. É por titular (não por titular+competência) porque a reavaliação de um caso aberto
    /// lê fatos de outras competências do mesmo titular (entradas competencia_relativa -1/-2 do C7):
    /// publicar o fato de 202607 destrava o E03 do caso 202609. O parâmetro competencia é mantido
    /// na assinatura por clareza no call-site, mas não entra na chave do lock.</summary>
    Task TravarCasoAsync(string titularId, Competencia competencia);
    Task<Caso?> PorChaveAsync(string titularId, Competencia competencia);
    /// <summary>Caso por id (para agrupar sementes de invalidação por caso, §11.1).</summary>
    Task<Caso?> PorIdAsync(Guid casoId);
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
    /// <summary>Entregáveis (qualquer caso) que têm o fato dado ligado em entregavel_fato,
    /// qualquer papel — sementes da invalidação (§11.1).</summary>
    Task<IReadOnlyList<Entregavel>> ComFatoLigadoAsync(Guid fatoId);
    /// <summary>Entregáveis do mesmo caso que dependem (depende_de_id) do entregável dado —
    /// cascata da invalidação (§11.2).</summary>
    Task<IReadOnlyList<Entregavel>> DependentesDeAsync(Guid casoId, Guid dependeDeId);
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

    /// <summary>Conferência VIGENTE de uma CF num entregável = a mais recente (maior em) daquela
    /// CF naquele entregável (§8.1).</summary>
    Task<ConferenciaVigente?> VigenteDaCfAsync(Guid entregavelId, string cf);

    /// <summary>Grava override (autor, motivo, em) na conferência vigente da CF (§8.1).</summary>
    Task GravarOverrideAsync(Guid conferenciaId, string autor, string motivo);

    /// <summary>Existe ALGUMA conferência vigente divergente, severidade B, sem override, em
    /// qualquer CF deste entregável, exceto a conferência dada (§8.1 passo 4)?</summary>
    Task<bool> TemOutraDivergenteBSemOverrideAsync(Guid entregavelId, Guid excetoConferenciaId);
}

/// <summary>Conferência vigente de uma CF num entregável (§8.1).</summary>
public sealed record ConferenciaVigente(
    Guid Id, string Resultado, string Severidade, bool TemOverride);

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

    /// <summary>Abre uma exceção de entregável só se NÃO houver outra aberta do mesmo tipo para o
    /// mesmo entregável (§9.4 abertura única). Devolve o id novo, ou null se já existia aberta.</summary>
    Task<Guid?> AbrirUnicaAsync(Guid casoId, Guid entregavelId, string tipo, string classe, string dono);

    /// <summary>Abre uma exceção SEM entregável (competencia_encerrada, §11.3) só se não houver
    /// outra aberta do mesmo tipo para o mesmo caso. Devolve o id novo, ou null se já existia.</summary>
    Task<Guid?> AbrirUnicaSemEntregavelAsync(Guid casoId, string tipo, string classe, string dono);

    /// <summary>Resolve (estado=resolvida, resolucao, resolvida_em=now) as exceções ABERTAS do
    /// entregável cujo tipo COMEÇA com 'CF-' (§9.4 resolução por fato novo).</summary>
    Task ResolverCfAbertasAsync(Guid entregavelId, string resolucao);

    /// <summary>Resolve as exceções ABERTAS do entregável cujo tipo está na lista dada
    /// (acompanhamento §10.3: entrega_falhou, recebimento_pendente).</summary>
    Task ResolverPorTiposAsync(Guid entregavelId, IReadOnlyList<string> tipos, string resolucao);
}

public interface IEventoRepo
{
    Task GravarAsync(string nome, Guid? casoId, Guid? entregavelId, JsonElement payload);
    /// <summary>Conveniência: payload de um único id.</summary>
    Task GravarComIdAsync(string nome, Guid? casoId, Guid? entregavelId, string chave, Guid valor);
    /// <summary>Conveniência: payload com dois ids (ex.: documento.disponibilizado leva
    /// entregavel_id e fato_id da guia de entrada do E11, §10.1/§12).</summary>
    Task GravarComDoisIdsAsync(string nome, Guid? casoId, Guid? entregavelId,
        string chave1, Guid valor1, string chave2, Guid valor2);
}
