using System.Text.Json;
using Motor.Dominio.Fechamento;

namespace Motor.Dominio.Fatos;

/// <summary>Fato vigente de uma chave (§6). Entrada das fórmulas das conferências.</summary>
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
