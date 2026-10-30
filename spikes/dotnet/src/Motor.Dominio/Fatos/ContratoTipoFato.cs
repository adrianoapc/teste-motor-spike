using System.Text.Json;

namespace Motor.Dominio.Fatos;

/// <summary>Regra da coluna <c>tributo</c> (o qualificador da chave) de um tipo de fato (§6.1).
/// <c>Usa=false</c> exige <c>tributo=''</c>; <c>Usa=true</c> exige tributo não vazio e, se
/// <c>Valores</c> não for vazio, dentro da lista.</summary>
public sealed record Qualificador(bool Usa, IReadOnlyList<string> Valores)
{
    public static Qualificador DeJson(JsonElement e)
    {
        var usa = e.TryGetProperty("usa", out var u) && u.ValueKind == JsonValueKind.True;
        var valores = new List<string>();
        if (e.TryGetProperty("valores", out var vs) && vs.ValueKind == JsonValueKind.Array)
            foreach (var v in vs.EnumerateArray())
                if (v.ValueKind == JsonValueKind.String) valores.Add(v.GetString()!);
        return new Qualificador(usa, valores);
    }
}

/// <summary>Política de <c>valor_centavos</c> de um tipo de fato (§6.1).</summary>
public enum PoliticaValor { Obrigatorio, Opcional, Proibido }

/// <summary>Contrato versionado (a versão <c>ativa</c>) de um tipo de fato (§6.1), lido do banco
/// a cada publicação (§13: nada em memória entre requisições). Imutável.</summary>
public sealed record ContratoTipoFato(
    string Tipo,
    int Versao,
    JsonElement Esquema,
    Qualificador Qualificador,
    PoliticaValor Valor,
    IReadOnlyList<string> CamposHash,
    IReadOnlyDictionary<string, IReadOnlyList<string>> OrdenarArrays,
    IReadOnlyList<string> FontesPermitidas)
{
    public static PoliticaValor PoliticaDe(string s) => s switch
    {
        "obrigatorio" => PoliticaValor.Obrigatorio,
        "opcional" => PoliticaValor.Opcional,
        "proibido" => PoliticaValor.Proibido,
        _ => throw new ArgumentOutOfRangeException(nameof(s), s, "valor_centavos de contrato desconhecido"),
    };

    /// <summary>Monta o contrato a partir das colunas de <c>fatos.tipo_fato_versao</c> (JSON cru nas jsonb).</summary>
    public static ContratoTipoFato De(string tipo, int versao, JsonElement esquema, JsonElement qualificador,
        string valorCentavos, IReadOnlyList<string> camposHash, JsonElement ordenarArrays,
        IReadOnlyList<string> fontesPermitidas)
    {
        var ordenar = new Dictionary<string, IReadOnlyList<string>>(StringComparer.Ordinal);
        if (ordenarArrays.ValueKind == JsonValueKind.Object)
            foreach (var prop in ordenarArrays.EnumerateObject())
            {
                var campos = new List<string>();
                if (prop.Value.ValueKind == JsonValueKind.Array)
                    foreach (var c in prop.Value.EnumerateArray())
                        if (c.ValueKind == JsonValueKind.String) campos.Add(c.GetString()!);
                ordenar[prop.Name] = campos;
            }
        return new ContratoTipoFato(tipo, versao, esquema.Clone(), Qualificador.DeJson(qualificador),
            PoliticaDe(valorCentavos), camposHash, ordenar, fontesPermitidas);
    }
}
