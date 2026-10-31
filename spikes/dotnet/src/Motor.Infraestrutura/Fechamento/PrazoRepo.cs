using Dapper;
using Motor.Aplicacao;
using Motor.Dominio.Fechamento;
using Npgsql;
using NpgsqlTypes;

namespace Motor.Infraestrutura;

/// <summary>Grava as datas de prazo (§18). UPDATE das três datas no entregável + INSERT no histórico
/// (fechamento.entregavel_prazo). Nenhuma transição é gravada aqui (§18.3).</summary>
internal sealed class PrazoRepo : RepoBase, IPrazosRepo
{
    public PrazoRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    public async Task AtualizarDatasAsync(Guid entregavelId, Guid casoId,
        DateOnly vencimento, DateOnly meta, DateOnly acao, string motivo,
        Guid calendarioVersaoId, Guid catalogoVersaoId, Guid prazosVersaoId)
    {
        // As três datas sempre juntas (constraint entregavel_datas_juntas) e em ordem (acao<=meta<=vencimento).
        // version NÃO muda: prazo é atributo, não estado (§18 princípio).
        await Conn.ExecuteAsync(
            @"UPDATE fechamento.entregavel
              SET vencimento = @v, meta = @m, acao = @a
              WHERE id = @id",
            new
            {
                v = vencimento.ToDateTime(TimeOnly.MinValue),
                m = meta.ToDateTime(TimeOnly.MinValue),
                a = acao.ToDateTime(TimeOnly.MinValue),
                id = entregavelId,
            }, Tx);

        await Conn.ExecuteAsync(
            @"INSERT INTO fechamento.entregavel_prazo
              (entregavel_id, caso_id, vencimento, meta, acao, motivo,
               calendario_versao_id, catalogo_versao_id, prazos_versao_id)
              VALUES (@e, @c, @v, @m, @a, @motivo, @cal, @cat, @prz)",
            new
            {
                e = entregavelId,
                c = casoId,
                v = vencimento.ToDateTime(TimeOnly.MinValue),
                m = meta.ToDateTime(TimeOnly.MinValue),
                a = acao.ToDateTime(TimeOnly.MinValue),
                motivo,
                cal = calendarioVersaoId,
                cat = catalogoVersaoId,
                prz = prazosVersaoId,
            }, Tx);
    }

    public async Task<IReadOnlyList<CasoComPrazos>> CasosAbertosComPrazosAsync()
    {
        var rows = await Conn.QueryAsync<(Guid id, string titular, string comp, string snapshot, Guid prazosVid)>(
            @"SELECT id, titular_id AS titular, competencia AS comp, snapshot::text AS snapshot,
                     regra_prazos_versao_id AS prazosVid
              FROM fechamento.caso_competencia
              WHERE estado = 'aberto' AND regra_prazos_versao_id IS NOT NULL
              ORDER BY titular_id", transaction: Tx);
        return rows.Select(r => new CasoComPrazos(
            r.id, r.titular, Competencia.Analisar(r.comp.Trim()), Json.Parse(r.snapshot), r.prazosVid)).ToList();
    }

    public async Task<IReadOnlyList<EntregavelComDatas>> EntregaveisComPrazoAsync(Guid casoId)
    {
        var rows = await Conn.QueryAsync<(Guid id, string tipo, string estado, DateTime venc, DateTime meta, DateTime acao)>(
            @"SELECT id, tipo, estado, vencimento AS venc, meta, acao
              FROM fechamento.entregavel
              WHERE caso_id = @c AND vencimento IS NOT NULL
              ORDER BY tipo",
            new { c = casoId }, Tx);
        return rows.Select(r => new EntregavelComDatas(
            r.id, r.tipo, Estados.Analisar(r.estado),
            DateOnly.FromDateTime(r.venc), DateOnly.FromDateTime(r.meta), DateOnly.FromDateTime(r.acao))).ToList();
    }

    public async Task<Guid?> AbrirRiscoDeMultaAsync(Guid casoId, Guid entregavelId, string dono, DateOnly vencimento)
    {
        // Abertura única (§18.4): só se não houver prazo_em_risco aberta nesse entregável.
        // classe 'O' (operacional), prazo = vencimento do entregável. Nenhuma transição.
        return await Conn.QuerySingleOrDefaultAsync<Guid?>(
            @"INSERT INTO trabalho.excecao (caso_id, entregavel_id, tipo, classe, dono, estado, prazo)
              SELECT @c, @e, 'prazo_em_risco', 'O', @d, 'aberta', @prazo
              WHERE NOT EXISTS (
                  SELECT 1 FROM trabalho.excecao
                  WHERE entregavel_id = @e AND tipo = 'prazo_em_risco' AND estado = 'aberta')
              RETURNING id",
            new { c = casoId, e = entregavelId, d = dono, prazo = vencimento.ToDateTime(TimeOnly.MinValue) }, Tx);
    }

    public async Task ResolverRiscoCumpridoAsync(Guid entregavelId)
    {
        // §18.4: resolve a prazo_em_risco ABERTA do entregável SOMENTE quando seu estado atual já
        // atingiu o estado de cumprimento (ordem do estado atual >= ordem do estado_cumprimento do
        // item de prazo do caso). Tudo no banco, usando a função indicadores.prazo_item (V010) e a
        // tabela de ordem fechamento.estado_entregavel — assim a decisão não depende de reler a regra
        // na aplicação e vale para qualquer caminho que passe pela função única de transição (§4.1).
        await Conn.ExecuteAsync(
            @"UPDATE trabalho.excecao x
              SET estado = 'resolvida', resolucao = 'cumprido', resolvida_em = now()
              FROM fechamento.entregavel e
              JOIN fechamento.caso_competencia c ON c.id = e.caso_id
              JOIN LATERAL indicadores.prazo_item(c.regra_prazos_versao_id, e.tipo) p ON true
              JOIN fechamento.estado_entregavel ea ON ea.estado = e.estado
              JOIN fechamento.estado_entregavel ec ON ec.estado = p.estado_cumprimento
              WHERE x.entregavel_id = @e
                AND x.tipo = 'prazo_em_risco' AND x.estado = 'aberta'
                AND e.id = @e
                AND ea.ordem IS NOT NULL AND ea.ordem >= ec.ordem",
            new { e = entregavelId }, Tx);
    }

    public async Task<IReadOnlyList<CandidatoRisco>> CandidatosRiscoAsync(DateOnly dataRef)
    {
        // §18.4 = exatamente a base do indicador I8 (gera_multa, não cumprido, caso aberto, data_ref>=acao).
        // Reusa a função da V010 e junta o entregável para obter o caso_id. Dono = carteira do caso.
        var rows = await Conn.QueryAsync<(Guid entregavelId, string titular, string carteira, DateTime venc, Guid casoId)>(
            @"SELECT i.entregavel_id AS entregavelId, i.titular_id AS titular, i.carteira, i.vencimento AS venc,
                     e.caso_id AS casoId
              FROM indicadores.i8_multas_em_risco(@d::date) i
              JOIN fechamento.entregavel e ON e.id = i.entregavel_id
              ORDER BY i.titular_id, e.tipo",
            new { d = dataRef.ToString("yyyy-MM-dd") }, Tx);
        return rows.Select(r => new CandidatoRisco(
            r.casoId, r.entregavelId, r.titular, r.carteira, DateOnly.FromDateTime(r.venc))).ToList();
    }
}
