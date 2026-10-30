using System.Text.Json;
using Motor.Dominio.Fechamento;

namespace Motor.Aplicacao;

/// <summary>Caso carregado do banco.</summary>
public sealed record Caso(
    Guid Id,
    string TitularId,
    Competencia Competencia,
    JsonElement Snapshot,
    string Carteira,
    Guid RegraVersaoId,
    string Estado,
    int Version);

/// <summary>Entregável carregado do banco.</summary>
public sealed record Entregavel(
    Guid Id,
    Guid CasoId,
    string Tipo,
    EstadoEntregavel Estado,
    string Executor,
    string Area,
    int Version);

/// <summary>Dependência materializada de um entregável.</summary>
public sealed record Dependencia(Guid EntregavelId, Guid DependeDeId, EstadoEntregavel EstadoMinimo);

/// <summary>Dados para gravar uma transição (§4.1).</summary>
public sealed record NovaTransicao(
    Guid EntregavelId,
    Guid CasoId,
    EstadoEntregavel? De,
    EstadoEntregavel Para,
    string Motivo,
    string? CausadoPorTipo,
    string? CausadoPorId,
    string Ator,
    Guid RegraVersaoId);

/// <summary>Pedido de publicação de fato (§6). <c>ChaveIdempotencia</c>: §6.5 (opcional, 1..200).</summary>
public sealed record PublicarFatoCmd(
    string TitularId,
    Competencia Competencia,
    string Tipo,
    string Tributo,
    long? ValorCentavos,
    JsonElement Payload,
    string Fonte,
    string? OrigemRef,
    DateTimeOffset ObservadoEm,
    string? ChaveIdempotencia = null);

/// <summary>Efeito da publicação (§6.4). <c>Obsoleto</c> e <c>ConflitoFonte</c> entraram na v5.</summary>
public enum EfeitoFato { Novo, NovaVersao, SemMudanca, Obsoleto, ConflitoFonte }

/// <summary>Erro de validação de contrato (§6.2) devolvido ao API para virar 422/403.</summary>
public enum FatoErro { None, TipoDesconhecido, FonteNaoAutorizada, PayloadInvalido }

/// <summary>Resultado da publicação. Em sucesso: <c>FatoId</c>, <c>Versao</c>, <c>Efeito</c> e
/// <c>Repetido</c> (§6.5). Em erro de contrato (§6.2): <c>Erro</c> e, no caso de payload, <c>Violacoes</c>.</summary>
public sealed record ResultadoFato(
    Guid FatoId,
    int Versao,
    EfeitoFato Efeito,
    bool Repetido = false,
    FatoErro Erro = FatoErro.None,
    IReadOnlyList<Motor.Dominio.Fatos.Violacao>? Violacoes = null)
{
    public static ResultadoFato DeErro(FatoErro erro, IReadOnlyList<Motor.Dominio.Fatos.Violacao>? violacoes = null) =>
        new(Guid.Empty, 0, EfeitoFato.SemMudanca, false, erro, violacoes);
}
