using System.Text.Json;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;

namespace Motor.Aplicacao;

public interface IEventoRepo
{
    Task GravarAsync(string nome, Guid? casoId, Guid? entregavelId, JsonElement payload);
    /// <summary>Conveniência: payload de um único id.</summary>
    Task GravarComIdAsync(string nome, Guid? casoId, Guid? entregavelId, string chave, Guid valor);
    /// <summary>Conveniência: payload com dois ids (ex.: documento.disponibilizado leva
    /// entregavel_id e fato_id da guia de entrada do E11, §10.1/§12).</summary>
    Task GravarComDoisIdsAsync(string nome, Guid? casoId, Guid? entregavelId,
        string chave1, Guid valor1, string chave2, Guid valor2);
}
