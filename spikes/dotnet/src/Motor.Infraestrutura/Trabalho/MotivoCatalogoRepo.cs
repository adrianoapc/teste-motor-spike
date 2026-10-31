using Dapper;
using Motor.Aplicacao;
using Npgsql;

namespace Motor.Infraestrutura;

/// <summary>Catálogo de motivos (trabalho.motivo_catalogo, V010, §18.5) e a exceção de impedimento.</summary>
internal sealed class MotivoCatalogoRepo : RepoBase, IMotivoCatalogoRepo
{
    public MotivoCatalogoRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    public async Task<MotivoCatalogo?> PorCodigoAsync(string codigo)
    {
        var row = await Conn.QuerySingleOrDefaultAsync<(string codigo, string aplicaA, bool exigeTexto)?>(
            @"SELECT codigo, aplica_a AS aplicaA, exige_texto AS exigeTexto
              FROM trabalho.motivo_catalogo WHERE codigo = @c",
            new { c = codigo }, Tx);
        return row is null ? null : new MotivoCatalogo(row.Value.codigo, row.Value.aplicaA, row.Value.exigeTexto);
    }

    public async Task<Guid?> AbrirImpedimentoAsync(Guid casoId, Guid entregavelId, string dono, string motivoCodigo)
    {
        // §18.5 abertura única: só se não houver impedimento_externo aberto no entregável.
        // classe 'S' (subjetiva/sistêmica), com motivo_codigo. Nenhuma transição.
        return await Conn.QuerySingleOrDefaultAsync<Guid?>(
            @"INSERT INTO trabalho.excecao (caso_id, entregavel_id, tipo, classe, dono, estado, motivo_codigo)
              SELECT @c, @e, 'impedimento_externo', 'S', @d, 'aberta', @mc
              WHERE NOT EXISTS (
                  SELECT 1 FROM trabalho.excecao
                  WHERE entregavel_id = @e AND tipo = 'impedimento_externo' AND estado = 'aberta')
              RETURNING id",
            new { c = casoId, e = entregavelId, d = dono, mc = motivoCodigo }, Tx);
    }

    public async Task<bool> ResolverImpedimentoAsync(Guid entregavelId)
    {
        var n = await Conn.ExecuteAsync(
            @"UPDATE trabalho.excecao
              SET estado = 'resolvida', resolucao = 'resolvido', resolvida_em = now()
              WHERE entregavel_id = @e AND tipo = 'impedimento_externo' AND estado = 'aberta'",
            new { e = entregavelId }, Tx);
        return n > 0;
    }
}
