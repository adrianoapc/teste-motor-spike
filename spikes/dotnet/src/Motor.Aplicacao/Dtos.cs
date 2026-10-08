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

/// <summary>Fato vigente de uma chave.</summary>
public sealed record FatoVigente(
    Guid Id,
    string TitularId,
    Competencia Competencia,
    string Tipo,
    string Tributo,
    int Versao,
    long? ValorCentavos,
    JsonElement Payload,
    string Hash);

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

/// <summary>Pedido de publicação de fato (§6).</summary>
public sealed record PublicarFatoCmd(
    string TitularId,
    Competencia Competencia,
    string Tipo,
    string Tributo,
    long? ValorCentavos,
    JsonElement Payload,
    string Fonte,
    string? OrigemRef,
    DateTimeOffset ObservadoEm);

public enum EfeitoFato { Novo, NovaVersao, SemMudanca }

public sealed record ResultadoFato(Guid FatoId, int Versao, EfeitoFato Efeito);
