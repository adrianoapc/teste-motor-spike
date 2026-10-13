using System.Globalization;
using System.Text;
using Motor.Dominio.Fatos;

namespace Motor.Dominio.Conferencias;

/// <summary>Um fato usado numa conferência, para rastro e idempotência (§9.2).</summary>
public readonly record struct FatoUsado(Guid FatoId, int Versao);

/// <summary>Monta o <c>entrada_hash</c> de uma conferência (§9.2):
/// SHA-256 do JSON canônico {"cf","fatos_usados","regra_versao_id"},
/// com fatos_usados = lista de {"fato_id","versao"} ORDENADA por fato_id.</summary>
public static class EntradaHash
{
    public static string Calcular(string cf, IEnumerable<FatoUsado> fatos, Guid regraVersaoId)
    {
        var ordenados = fatos.OrderBy(f => f.FatoId.ToString(), StringComparer.Ordinal).ToList();
        var sb = new StringBuilder();
        sb.Append("{\"cf\":");
        EscreverString(sb, cf);
        sb.Append(",\"fatos_usados\":[");
        for (var i = 0; i < ordenados.Count; i++)
        {
            if (i > 0) sb.Append(',');
            sb.Append("{\"fato_id\":");
            EscreverString(sb, ordenados[i].FatoId.ToString("D"));
            sb.Append(",\"versao\":");
            sb.Append(ordenados[i].Versao.ToString(CultureInfo.InvariantCulture));
            sb.Append('}');
        }
        sb.Append("],\"regra_versao_id\":");
        EscreverString(sb, regraVersaoId.ToString("D"));
        sb.Append('}');
        return HashCanonico.Sha256Hex(sb.ToString());
    }

    private static void EscreverString(StringBuilder sb, string valor)
    {
        sb.Append('"');
        foreach (var c in valor)
        {
            switch (c)
            {
                case '"': sb.Append("\\\""); break;
                case '\\': sb.Append("\\\\"); break;
                default: sb.Append(c); break;
            }
        }
        sb.Append('"');
    }
}
