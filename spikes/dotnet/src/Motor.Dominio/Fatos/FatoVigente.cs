using System.Text.Json;
using Motor.Dominio.Fechamento;

namespace Motor.Dominio.Fatos;

/// <summary>Fato vigente de uma chave (§6). Entrada das fórmulas das conferências.
/// <c>TipoVersao</c> é a versão do contrato em uso quando o fato foi gravado (nulo só em fato
/// gravado antes da V009); o hash v5 é sempre recalculado sob o contrato ATIVO (§6.4).
/// <c>Fonte</c> e <c>ObservadoEm</c> decidem obsoleto/conflito_fonte (§6.4).</summary>
public sealed record FatoVigente(
    Guid Id,
    string TitularId,
    Competencia Competencia,
    string Tipo,
    string Tributo,
    int Versao,
    long? ValorCentavos,
    JsonElement Payload,
    string Hash,
    int? TipoVersao = null,
    string Fonte = "",
    DateTimeOffset ObservadoEm = default);
