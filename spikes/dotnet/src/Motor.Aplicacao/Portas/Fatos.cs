using System.Text.Json;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fatos;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;

namespace Motor.Aplicacao;

public interface IFatoRepo
{
    /// <summary>Trava transacional (pg_advisory_xact_lock) na chave do fato, serializando
    /// publicações concorrentes da MESMA chave para que a alocação de versão não colida
    /// no índice único (titular_id, competencia, tipo, tributo, versao).</summary>
    Task TravarChaveAsync(string titularId, Competencia competencia, string tipo, string tributo);
    Task<FatoVigente?> VigenteAsync(string titularId, Competencia competencia, string tipo, string tributo);
    /// <summary>Grava nova versão do fato e devolve (id, versao).</summary>
    Task<(Guid Id, int Versao)> GravarAsync(PublicarFatoCmd cmd, int versao, string hash, Guid? substituiId);
    /// <summary>Todos os fatos vigentes do caso (mesmo titular+competência).</summary>
    Task<IReadOnlyList<FatoVigente>> VigentesDoCasoAsync(string titularId, Competencia competencia);
}
