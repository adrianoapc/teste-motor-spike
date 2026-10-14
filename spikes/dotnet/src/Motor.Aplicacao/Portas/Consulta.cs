using System.Text.Json;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;

namespace Motor.Aplicacao;

public sealed record CasoConsulta(
    Guid CasoId, string TitularId, string Competencia, string Estado,
    IReadOnlyList<EntregavelConsulta> Entregaveis,
    IReadOnlyList<TarefaConsulta> TarefasAbertas,
    IReadOnlyList<ExcecaoConsulta> ExcecoesAbertas);

public sealed record EntregavelConsulta(Guid EntregavelId, string Tipo, string Estado, string Executor);
public sealed record TarefaConsulta(Guid TarefaId, string EntregavelTipo);
public sealed record ExcecaoConsulta(Guid ExcecaoId, string Tipo, string Classe);
