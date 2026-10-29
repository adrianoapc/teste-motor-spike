using System.Text.Json;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fatos;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;

namespace Motor.Aplicacao;

/// <summary>Recebimento já gravado com a mesma (fonte, chave_idempotencia): resposta repetida (§6.5).</summary>
public sealed record RecebimentoRepetido(Guid FatoId, int Versao, string Efeito);

/// <summary>Linha a gravar em fatos.recebimento (§6.4): toda publicação válida.</summary>
public sealed record NovoRecebimento(
    string TitularId,
    Competencia Competencia,
    string Tipo,
    string Tributo,
    string Fonte,
    string? ChaveIdempotencia,
    DateTimeOffset ObservadoEm,
    int TipoVersao,
    string Hash,
    string Efeito,
    Guid FatoId,
    JsonElement Payload,
    long? ValorCentavos);

public interface IFatoRepo
{
    /// <summary>Trava transacional (pg_advisory_xact_lock) na chave do fato, serializando
    /// publicações concorrentes da MESMA chave para que a alocação de versão não colida
    /// no índice único (titular_id, competencia, tipo, tributo, versao).</summary>
    Task TravarChaveAsync(string titularId, Competencia competencia, string tipo, string tributo);

    /// <summary>Contrato ATIVO do tipo (a única versão <c>ativa</c>), lido do banco a cada publicação
    /// (§6.1, §13). <c>null</c> = tipo sem versão ativa (-> 422 tipo_desconhecido).</summary>
    Task<ContratoTipoFato?> ContratoAtivoAsync(string tipo);

    Task<FatoVigente?> VigenteAsync(string titularId, Competencia competencia, string tipo, string tributo);
    /// <summary>Grava nova versão do fato e devolve (id, versao). <c>tipoVersao</c> = versão do contrato em uso.</summary>
    Task<(Guid Id, int Versao)> GravarAsync(PublicarFatoCmd cmd, int versao, string hash, Guid? substituiId, int tipoVersao);
    /// <summary>Todos os fatos vigentes do caso (mesmo titular+competência).</summary>
    Task<IReadOnlyList<FatoVigente>> VigentesDoCasoAsync(string titularId, Competencia competencia);

    /// <summary>Recebimento já existente com a mesma (fonte, chave_idempotencia), se houver (§6.5).</summary>
    Task<RecebimentoRepetido?> RecebimentoPorChaveAsync(string fonte, string chaveIdempotencia);
    /// <summary>Grava a linha de recebimento (§6.4). Toda publicação válida grava uma.</summary>
    Task GravarRecebimentoAsync(NovoRecebimento recebimento);
}
