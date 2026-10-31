using System.Text.Json;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;

namespace Motor.Aplicacao;

public sealed record TarefaAberta(Guid TarefaId, Guid EntregavelId);

public interface ITarefaRepo
{
    Task<Guid> CriarAsync(Guid casoId, Guid entregavelId, string tipo, string responsavel);
    Task<Guid?> AbertaDoEntregavelAsync(Guid entregavelId);
    Task ConcluirAsync(Guid tarefaId);
    /// <summary>Tarefas abertas do caso, para a consulta GET /casos.</summary>
    Task<IReadOnlyList<TarefaAberta>> AbertasDoCasoAsync(Guid casoId);
    Task CancelarAbertasAsync(Guid entregavelId);
}

public interface IExcecaoRepo
{
    /// <summary>Abre uma exceção de entregável só se NÃO houver outra aberta do mesmo tipo para o
    /// mesmo entregável (§9.4 abertura única). Devolve o id novo, ou null se já existia aberta.</summary>
    Task<Guid?> AbrirUnicaAsync(Guid casoId, Guid entregavelId, string tipo, string classe, string dono);

    /// <summary>Abre uma exceção SEM entregável (competencia_encerrada, §11.3) só se não houver
    /// outra aberta do mesmo tipo para o mesmo caso. Devolve o id novo, ou null se já existia.</summary>
    Task<Guid?> AbrirUnicaSemEntregavelAsync(Guid casoId, string tipo, string classe, string dono);

    /// <summary>Resolve (estado=resolvida, resolucao, resolvida_em=now) as exceções ABERTAS do
    /// entregável cujo tipo COMEÇA com 'CF-' (§9.4 resolução por fato novo).</summary>
    Task ResolverCfAbertasAsync(Guid entregavelId, string resolucao);

    /// <summary>Resolve as exceções ABERTAS do entregável cujo tipo está na lista dada
    /// (acompanhamento §10.3: entrega_falhou, recebimento_pendente).</summary>
    Task ResolverPorTiposAsync(Guid entregavelId, IReadOnlyList<string> tipos, string resolucao);

    /// <summary>Exceções abertas do caso, para a consulta GET /casos.</summary>
    Task<IReadOnlyList<ExcecaoConsulta>> AbertasDoCasoAsync(Guid casoId);
}

/// <summary>Catálogo de motivos (trabalho.motivo_catalogo, V010, §18.5). Valida código por uso.</summary>
public interface IMotivoCatalogoRepo
{
    /// <summary>Um motivo do catálogo pelo código, ou null se não existe.</summary>
    Task<MotivoCatalogo?> PorCodigoAsync(string codigo);

    /// <summary>Abre a exceção <c>impedimento_externo</c> (classe S, dono = carteira, com motivo_codigo)
    /// do entregável, só se não houver outra aberta nele (§18.5 abertura única). Devolve o id, ou null
    /// se já existia aberta. Nenhuma transição.</summary>
    Task<Guid?> AbrirImpedimentoAsync(Guid casoId, Guid entregavelId, string dono, string motivoCodigo);

    /// <summary>Resolve (resolvida, resolucao = 'resolvido', now) o <c>impedimento_externo</c> ABERTO do
    /// entregável (§18.5). Devolve true se resolveu algo, false se não havia impedimento aberto.</summary>
    Task<bool> ResolverImpedimentoAsync(Guid entregavelId);
}

/// <summary>Motivo do catálogo (§18.5): a que uso se aplica e se exige texto.</summary>
public sealed record MotivoCatalogo(string Codigo, string AplicaA, bool ExigeTexto);
