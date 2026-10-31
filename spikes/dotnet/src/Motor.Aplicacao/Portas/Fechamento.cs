using System.Text.Json;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;

namespace Motor.Aplicacao;

/// <summary>Catálogo do tipo de entregável (fechamento.entregavel_tipo).</summary>
public sealed record EntregavelTipoCatalogo(string Area, string? EventoPublicado);

public interface ICasoRepo
{
    /// <summary>Trava transacional (pg_advisory_xact_lock) por TITULAR. Tomada na abertura, na
    /// publicação de fato e na conclusão para que uma transação sempre observe o estado comitado
    /// da outra. É por titular (não por titular+competência) porque a reavaliação de um caso aberto
    /// lê fatos de outras competências do mesmo titular (entradas competencia_relativa -1/-2 do C7):
    /// publicar o fato de 202607 destrava o E03 do caso 202609.</summary>
    Task TravarCasoAsync(string titularId);
    Task<Caso?> PorChaveAsync(string titularId, Competencia competencia);
    /// <summary>Caso por id (para agrupar sementes de invalidação por caso, §11.1).</summary>
    Task<Caso?> PorIdAsync(Guid casoId);
    Task<Caso?> CriarAsync(string titularId, Competencia competencia, JsonElement snapshot,
        string carteira, Guid regraVersaoId, Guid? regraPrazosVersaoId);
    /// <summary>Casos ABERTOS do titular, com trava, para reavaliação após fato.</summary>
    Task<IReadOnlyList<Caso>> AbertosDoTitularAsync(string titularId);
    Task EncerrarAsync(Guid casoId);
}

/// <summary>Persistência das datas de prazo (§18). Grava as três datas no entregável e uma linha
/// de histórico em fechamento.entregavel_prazo. Nada aqui cria estado ou transição.</summary>
public interface IPrazosRepo
{
    /// <summary>Grava vencimento/meta/acao no entregável e insere a linha de histórico (motivo
    /// 'abertura' ou 'calendario_v&lt;N&gt;'). Usada na abertura (§5 passo 3) e em AplicarCalendario (§18.3).</summary>
    Task AtualizarDatasAsync(Guid entregavelId, Guid casoId,
        DateOnly vencimento, DateOnly meta, DateOnly acao, string motivo,
        Guid calendarioVersaoId, Guid catalogoVersaoId, Guid prazosVersaoId);
}

public interface IEntregavelRepo
{
    /// <summary>Carrega os entregáveis do caso COM trava de linha (FOR UPDATE), ordenados por tipo.</summary>
    Task<IReadOnlyList<Entregavel>> DoCasoComTravaAsync(Guid casoId);
    /// <summary>Entregáveis do caso, ordenados por tipo, sem trava (consulta GET /casos).</summary>
    Task<IReadOnlyList<Entregavel>> DoCasoAsync(Guid casoId);
    /// <summary>Catálogo do tipo de entregável (área e evento publicado ao validar).</summary>
    Task<EntregavelTipoCatalogo?> TipoCatalogoAsync(string tipo);
    Task<Entregavel?> PorChaveAsync(Guid casoId, string tipo);
    /// <summary>Como PorChaveAsync, mas trava a LINHA (FOR UPDATE) — para reivindicar o
    /// entregável antes de validar estado em conclusão de tarefa concorrente (§8).</summary>
    Task<Entregavel?> PorChaveComTravaAsync(Guid casoId, string tipo);
    Task<Entregavel> CriarAsync(Guid casoId, string tipo, string executor, string area);
    Task GravarDependenciaAsync(Guid entregavelId, Guid dependeDeId, EstadoEntregavel estadoMinimo);
    /// <summary>Todas as dependências materializadas dos entregáveis do caso (imutáveis depois da abertura, §5.4).</summary>
    Task<IReadOnlyList<Dependencia>> DependenciasDoCasoAsync(Guid casoId);
    /// <summary>Aplica a transição: UPDATE estado/estado_desde/version + INSERT na transição (§4.1).</summary>
    Task TransicionarAsync(Entregavel atual, NovaTransicao transicao);
    Task LigarFatoAsync(Guid entregavelId, Guid fatoId, string papel);
    /// <summary>Entregáveis (qualquer caso) que têm o fato dado ligado em entregavel_fato,
    /// qualquer papel — sementes da invalidação (§11.1).</summary>
    Task<IReadOnlyList<Entregavel>> ComFatoLigadoAsync(Guid fatoId);
    /// <summary>Entregáveis do mesmo caso que dependem (depende_de_id) do entregável dado —
    /// cascata da invalidação (§11.2).</summary>
    Task<IReadOnlyList<Entregavel>> DependentesDeAsync(Guid casoId, Guid dependeDeId);
}
