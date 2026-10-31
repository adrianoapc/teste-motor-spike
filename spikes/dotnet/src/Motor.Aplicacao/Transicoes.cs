using Motor.Dominio.Fechamento;

namespace Motor.Aplicacao;

/// <summary>Montagem única das transições de estado (§4.1) e do evento do tipo do entregável (§9.3),
/// compartilhada por avaliação do caso, conclusão de tarefa, override e invalidação.
/// A hora (<c>estado_desde</c>, <c>em</c>) é sempre do banco: nenhum relógio da aplicação entra aqui.</summary>
internal static class Transicoes
{
    public static async Task TransicionarAsync(IRepositorios r, Caso caso, Entregavel e, EstadoEntregavel para, string motivo,
        string? causadoPorTipo = "comando", string? causadoPorId = null, string ator = "sistema")
    {
        await r.Entregaveis.TransicionarAsync(e, new NovaTransicao(
            e.Id, caso.Id, e.Estado, para, motivo, causadoPorTipo, causadoPorId, ator, caso.RegraVersaoId));
        // §18.4: ao transicionar para o estado de cumprimento, a prazo_em_risco aberta do entregável
        // se resolve ('cumprido') na MESMA transação. Fica aqui, na função única de transição, para
        // nenhum caminho (conclusão, override, protocolo, reavaliação) esquecer. O repo só resolve se
        // o novo estado realmente atingiu o estado_cumprimento, então chamar sempre é seguro.
        await r.Prazos.ResolverRiscoCumpridoAsync(e.Id);
    }

    /// <summary>Evento <c>evento_publicado</c> do tipo do entregável, se o catálogo definir um (§9.3).</summary>
    public static async Task PublicarEventoDoTipoAsync(IRepositorios r, Caso caso, Entregavel e)
    {
        var cat = await r.Entregaveis.TipoCatalogoAsync(e.Tipo);
        if (cat?.EventoPublicado is { } nome)
            await r.Eventos.GravarComIdAsync(nome, caso.Id, e.Id, "entregavel_id", e.Id);
    }
}
