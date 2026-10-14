using System.Text.Json;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;

namespace Motor.Aplicacao;

/// <summary>Catálogo do tipo de conferência (conferencias.conferencia_tipo).</summary>
public sealed record ConferenciaTipoCatalogo(string Severidade, string ClassePadrao);

/// <summary>Dados completos para gravar uma conferência (§9.2).</summary>
public sealed record NovaConferencia(
    string Cf, Guid CasoId, Guid EntregavelId, Guid RegraVersaoId,
    IReadOnlyList<FatoUsado> FatosUsados, string EntradaHash,
    ResultadoConferencia Resultado, long? EsperadoCentavos, long? ObtidoCentavos,
    JsonElement Diferenca, string Severidade, string Classe, int SemanticaCalculo = 1);

public interface IConferenciaRepo
{
    /// <summary>Insere se não existir (idempotência por cf+entregavel+entrada_hash); devolve
    /// (id, resultado) da conferência vigente para aquela chave.</summary>
    Task<(Guid Id, ResultadoConferencia Resultado)> InserirOuReaproveitarAsync(NovaConferencia c);

    /// <summary>Catálogo do tipo de conferência (severidade e classe padrão).</summary>
    Task<ConferenciaTipoCatalogo?> TipoCatalogoAsync(string cf);

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
