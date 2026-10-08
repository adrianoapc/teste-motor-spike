using System.Text.Json;
using Dapper;
using Motor.Aplicacao;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Npgsql;
using NpgsqlTypes;

namespace Motor.Infraestrutura;

public sealed class UnidadeDeTrabalho : IUnidadeDeTrabalho
{
    private readonly NpgsqlConnection _conn;
    private readonly NpgsqlTransaction _tx;
    public IRepositorios Repos { get; }

    public UnidadeDeTrabalho(NpgsqlConnection conn, NpgsqlTransaction tx)
    {
        _conn = conn;
        _tx = tx;
        Repos = new Repositorios(conn, tx);
    }

    public async Task ConfirmarAsync() => await _tx.CommitAsync();

    public async Task<DateTimeOffset> AgoraAsync()
    {
        // Npgsql mapeia timestamptz para DateTime (Kind=Utc); envolvemos em DateTimeOffset UTC.
        var dt = await _conn.ExecuteScalarAsync<DateTime>("SELECT now()", transaction: _tx);
        return new DateTimeOffset(DateTime.SpecifyKind(dt, DateTimeKind.Utc));
    }

    public async ValueTask DisposeAsync()
    {
        await _tx.DisposeAsync();
        await _conn.DisposeAsync();
    }
}

internal static class Json
{
    public static JsonElement Parse(string? texto)
    {
        using var doc = JsonDocument.Parse(string.IsNullOrEmpty(texto) ? "{}" : texto);
        return doc.RootElement.Clone();
    }
}

internal sealed class Repositorios : IRepositorios
{
    public Repositorios(NpgsqlConnection c, NpgsqlTransaction t)
    {
        Regras = new RegrasRepo(c, t);
        Catalogo = new CatalogoRepo(c, t);
        Casos = new CasoRepo(c, t);
        Entregaveis = new EntregavelRepo(c, t);
        Fatos = new FatoRepo(c, t);
        Conferencias = new ConferenciaRepo(c, t);
        Tarefas = new TarefaRepo(c, t);
        Excecoes = new ExcecaoRepo(c, t);
        Eventos = new EventoRepo(c, t);
    }

    public IRegrasRepo Regras { get; }
    public ICatalogoRepo Catalogo { get; }
    public ICasoRepo Casos { get; }
    public IEntregavelRepo Entregaveis { get; }
    public IFatoRepo Fatos { get; }
    public IConferenciaRepo Conferencias { get; }
    public ITarefaRepo Tarefas { get; }
    public IExcecaoRepo Excecoes { get; }
    public IEventoRepo Eventos { get; }
}

internal abstract class RepoBase
{
    protected readonly NpgsqlConnection Conn;
    protected readonly NpgsqlTransaction Tx;
    protected RepoBase(NpgsqlConnection c, NpgsqlTransaction t) { Conn = c; Tx = t; }
}

internal sealed class RegrasRepo : RepoBase, IRegrasRepo
{
    public RegrasRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    public async Task<(Guid Id, JsonElement Conteudo)?> VersaoEmUsoAsync(string chave)
    {
        var row = await Conn.QuerySingleOrDefaultAsync<(Guid id, string conteudo)?>(
            @"SELECT id, conteudo::text AS conteudo FROM regras.regra_versao
              WHERE regra_chave = @chave AND status IN ('provisoria','ativa') LIMIT 1",
            new { chave }, Tx);
        if (row is null) return null;
        return (row.Value.id, Json.Parse(row.Value.conteudo));
    }

    public async Task<JsonElement?> ConteudoPorIdAsync(Guid versaoId)
    {
        var conteudo = await Conn.QuerySingleOrDefaultAsync<string?>(
            @"SELECT conteudo::text FROM regras.regra_versao WHERE id = @id",
            new { id = versaoId }, Tx);
        return conteudo is null ? null : Json.Parse(conteudo);
    }
}

internal sealed class CatalogoRepo : RepoBase, ICatalogoRepo
{
    public CatalogoRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    public async Task<EntregavelTipoCatalogo?> EntregavelTipoAsync(string tipo)
    {
        var row = await Conn.QuerySingleOrDefaultAsync<(string chave, string area, string? evento)?>(
            @"SELECT chave, area, evento_publicado AS evento
              FROM fechamento.entregavel_tipo WHERE chave = @tipo", new { tipo }, Tx);
        return row is null ? null : new EntregavelTipoCatalogo(row.Value.chave, row.Value.area, row.Value.evento);
    }

    public async Task<ConferenciaTipoCatalogo?> ConferenciaTipoAsync(string cf)
    {
        var row = await Conn.QuerySingleOrDefaultAsync<(string chave, string et, string sev, string classe, bool noSpike)?>(
            @"SELECT chave, entregavel_tipo AS et, severidade AS sev, classe_padrao AS classe, no_spike AS noSpike
              FROM conferencias.conferencia_tipo WHERE chave = @cf", new { cf }, Tx);
        return row is null ? null
            : new ConferenciaTipoCatalogo(row.Value.chave, row.Value.et, row.Value.sev, row.Value.classe, row.Value.noSpike);
    }
}

internal sealed class CasoRepo : RepoBase, ICasoRepo
{
    public CasoRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    private static Caso Mapear((Guid id, string titular, string comp, string snapshot, string carteira,
        Guid regraVid, string estado, int version) r) =>
        new(r.id, r.titular, Competencia.Analisar(r.comp.Trim()), Json.Parse(r.snapshot),
            r.carteira, r.regraVid, r.estado, r.version);

    private const string Cols =
        "id, titular_id AS titular, competencia AS comp, snapshot::text AS snapshot, carteira, " +
        "regra_entregaveis_versao_id AS regraVid, estado, version";

    public async Task<Caso?> PorChaveAsync(string titularId, Competencia competencia)
    {
        var r = await Conn.QuerySingleOrDefaultAsync<(Guid, string, string, string, string, Guid, string, int)?>(
            $"SELECT {Cols} FROM fechamento.caso_competencia WHERE titular_id = @t AND competencia = @c",
            new { t = titularId, c = competencia.ToString() }, Tx);
        return r is null ? null : Mapear(r.Value);
    }

    public async Task TravarCasoAsync(string titularId, Competencia competencia)
    {
        // Lock por TITULAR (competência ignorada de propósito). A reavaliação de um caso aberto lê
        // fatos de OUTRAS competências do mesmo titular (entradas competencia_relativa -1/-2 do C7),
        // então publicar o fato de 202607 pode destravar o E03 do caso 202609. Travar só
        // (titular,competência) deixaria publisher e opener de competências diferentes em locks
        // distintos — um não veria o estado não-comitado do outro, e o E03 ficaria preso em
        // aguardando_insumo. O lock por titular garante exclusão mútua entre QUALQUER abertura e
        // QUALQUER publicação do titular; liberado no fim da transação.
        var chave = $"caso|{titularId}";
        var bytes = System.Security.Cryptography.SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(chave));
        var k1 = BitConverter.ToInt32(bytes, 0);
        var k2 = BitConverter.ToInt32(bytes, 4);
        await Conn.ExecuteAsync("SELECT pg_advisory_xact_lock(@k1, @k2)", new { k1, k2 }, Tx);
    }

    public async Task<Caso?> CriarAsync(string titularId, Competencia competencia, JsonElement snapshot,
        string carteira, Guid regraVersaoId)
    {
        // INSERT atômico tolerante a conflito: duas aberturas concorrentes do mesmo
        // (titular, competência) não quebram com 23505 — a perdedora recebe id nulo (§ idempotência).
        var cmd = new NpgsqlCommand(
            @"INSERT INTO fechamento.caso_competencia
              (titular_id, competencia, snapshot, carteira, regra_entregaveis_versao_id, estado)
              VALUES (@t, @c, @s, @ca, @rv, 'aberto')
              ON CONFLICT (titular_id, competencia) DO NOTHING
              RETURNING id", Conn, Tx);
        cmd.Parameters.AddWithValue("t", titularId);
        cmd.Parameters.AddWithValue("c", competencia.ToString());
        cmd.Parameters.Add(new NpgsqlParameter("s", NpgsqlDbType.Jsonb) { Value = snapshot.GetRawText() });
        cmd.Parameters.AddWithValue("ca", carteira);
        cmd.Parameters.AddWithValue("rv", regraVersaoId);
        var res = await cmd.ExecuteScalarAsync();
        if (res is not Guid id) return null; // conflito: outra transação já abriu o caso
        return new Caso(id, titularId, competencia, snapshot, carteira, regraVersaoId, "aberto", 1);
    }

    public async Task<IReadOnlyList<Caso>> AbertosDoTitularAsync(string titularId)
    {
        var rows = await Conn.QueryAsync<(Guid, string, string, string, string, Guid, string, int)>(
            $"SELECT {Cols} FROM fechamento.caso_competencia WHERE titular_id = @t AND estado = 'aberto' FOR UPDATE",
            new { t = titularId }, Tx);
        return rows.Select(Mapear).ToList();
    }

    public async Task EncerrarAsync(Guid casoId, DateTimeOffset quando)
    {
        await Conn.ExecuteAsync(
            @"UPDATE fechamento.caso_competencia
              SET estado = 'encerrado', encerrado_em = now(), version = version + 1 WHERE id = @id",
            new { id = casoId }, Tx);
    }
}

internal sealed class EntregavelRepo : RepoBase, IEntregavelRepo
{
    public EntregavelRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    private static Entregavel Mapear((Guid id, Guid caso, string tipo, string estado, string executor, string area, int version) r) =>
        new(r.id, r.caso, r.tipo, Estados.Analisar(r.estado), r.executor, r.area, r.version);

    private const string Cols = "id, caso_id AS caso, tipo, estado, executor, area, version";

    public async Task<IReadOnlyList<Entregavel>> DoCasoComTravaAsync(Guid casoId)
    {
        var rows = await Conn.QueryAsync<(Guid, Guid, string, string, string, string, int)>(
            $"SELECT {Cols} FROM fechamento.entregavel WHERE caso_id = @c ORDER BY tipo FOR UPDATE",
            new { c = casoId }, Tx);
        return rows.Select(Mapear).ToList();
    }

    public async Task<Entregavel?> PorChaveAsync(Guid casoId, string tipo)
    {
        var r = await Conn.QuerySingleOrDefaultAsync<(Guid, Guid, string, string, string, string, int)?>(
            $"SELECT {Cols} FROM fechamento.entregavel WHERE caso_id = @c AND tipo = @t",
            new { c = casoId, t = tipo }, Tx);
        return r is null ? null : Mapear(r.Value);
    }

    public async Task<Entregavel?> PorChaveComTravaAsync(Guid casoId, string tipo)
    {
        var r = await Conn.QuerySingleOrDefaultAsync<(Guid, Guid, string, string, string, string, int)?>(
            $"SELECT {Cols} FROM fechamento.entregavel WHERE caso_id = @c AND tipo = @t FOR UPDATE",
            new { c = casoId, t = tipo }, Tx);
        return r is null ? null : Mapear(r.Value);
    }

    public async Task<Entregavel> CriarAsync(Guid casoId, string tipo, string executor, string area)
    {
        var id = await Conn.ExecuteScalarAsync<Guid>(
            @"INSERT INTO fechamento.entregavel (caso_id, tipo, estado, executor, area)
              VALUES (@c, @t, 'aguardando_insumo', @e, @a) RETURNING id",
            new { c = casoId, t = tipo, e = executor, a = area }, Tx);
        return new Entregavel(id, casoId, tipo, EstadoEntregavel.AguardandoInsumo, executor, area, 1);
    }

    public async Task GravarDependenciaAsync(Guid entregavelId, Guid dependeDeId, EstadoEntregavel estadoMinimo)
    {
        await Conn.ExecuteAsync(
            @"INSERT INTO fechamento.entregavel_dependencia (entregavel_id, depende_de_id, estado_minimo)
              VALUES (@e, @d, @m) ON CONFLICT DO NOTHING",
            new { e = entregavelId, d = dependeDeId, m = estadoMinimo.Nome() }, Tx);
    }

    public async Task<IReadOnlyList<Dependencia>> DependenciasDoAsync(Guid entregavelId)
    {
        var rows = await Conn.QueryAsync<(Guid e, Guid d, string m)>(
            @"SELECT entregavel_id AS e, depende_de_id AS d, estado_minimo AS m
              FROM fechamento.entregavel_dependencia WHERE entregavel_id = @e",
            new { e = entregavelId }, Tx);
        return rows.Select(x => new Dependencia(x.e, x.d, Estados.Analisar(x.m))).ToList();
    }

    public async Task TransicionarAsync(Entregavel atual, NovaTransicao tr, DateTimeOffset agora)
    {
        // UPDATE do entregável com checagem otimista de version (§13)
        var afetadas = await Conn.ExecuteAsync(
            @"UPDATE fechamento.entregavel
              SET estado = @novo, estado_desde = clock_timestamp(), version = version + 1
              WHERE id = @id AND version = @ver",
            new { novo = tr.Para.Nome(), id = atual.Id, ver = atual.Version }, Tx);
        if (afetadas != 1)
            throw new InvalidOperationException($"conflito de versão ao transicionar {atual.Tipo} ({atual.Id})");

        await Conn.ExecuteAsync(
            @"INSERT INTO fechamento.entregavel_transicao
              (entregavel_id, caso_id, de_estado, para_estado, motivo, causado_por_tipo, causado_por_id, ator, regra_versao_id)
              VALUES (@e, @c, @de, @para, @motivo, @ct, @ci, @ator, @rv)",
            new
            {
                e = tr.EntregavelId,
                c = tr.CasoId,
                de = tr.De?.Nome(),
                para = tr.Para.Nome(),
                motivo = tr.Motivo,
                ct = tr.CausadoPorTipo,
                ci = tr.CausadoPorId,
                ator = tr.Ator,
                rv = tr.RegraVersaoId,
            }, Tx);
    }

    public async Task LigarFatoAsync(Guid entregavelId, Guid fatoId, string papel)
    {
        await Conn.ExecuteAsync(
            @"INSERT INTO fechamento.entregavel_fato (entregavel_id, fato_id, papel)
              VALUES (@e, @f, @p) ON CONFLICT DO NOTHING",
            new { e = entregavelId, f = fatoId, p = papel }, Tx);
    }
}

internal sealed class FatoRepo : RepoBase, IFatoRepo
{
    public FatoRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    public async Task TravarChaveAsync(string titularId, Competencia competencia, string tipo, string tributo)
    {
        // Lock de 2 inteiros derivado da chave do fato; liberado automaticamente no fim da transação.
        var chave = $"{titularId}|{competencia}|{tipo}|{tributo}";
        var bytes = System.Security.Cryptography.SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(chave));
        var k1 = BitConverter.ToInt32(bytes, 0);
        var k2 = BitConverter.ToInt32(bytes, 4);
        await Conn.ExecuteAsync("SELECT pg_advisory_xact_lock(@k1, @k2)", new { k1, k2 }, Tx);
    }

    private static FatoVigente Mapear((Guid id, string titular, string comp, string tipo, string tributo,
        int versao, long? valor, string payload, string hash) r) =>
        new(r.id, r.titular, Competencia.Analisar(r.comp.Trim()), r.tipo, r.tributo, r.versao,
            r.valor, Json.Parse(r.payload), r.hash);

    private const string Cols =
        "id, titular_id AS titular, competencia AS comp, tipo, tributo, versao, " +
        "valor_centavos AS valor, payload::text AS payload, hash";

    public async Task<FatoVigente?> VigenteAsync(string titularId, Competencia competencia, string tipo, string tributo)
    {
        var r = await Conn.QuerySingleOrDefaultAsync<(Guid, string, string, string, string, int, long?, string, string)?>(
            $"SELECT {Cols} FROM fatos.fato_vigente WHERE titular_id=@t AND competencia=@c AND tipo=@tp AND tributo=@tr",
            new { t = titularId, c = competencia.ToString(), tp = tipo, tr = tributo }, Tx);
        return r is null ? null : Mapear(r.Value);
    }

    public async Task<(Guid Id, int Versao)> GravarAsync(PublicarFatoCmd cmd, int versao, string hash, Guid? substituiId)
    {
        var c = new NpgsqlCommand(
            @"INSERT INTO fatos.fato
              (titular_id, competencia, tipo, tributo, versao, valor_centavos, payload, fonte, origem_ref, hash, substitui_id, observado_em)
              VALUES (@t, @c, @tp, @tr, @v, @val, @pl, @f, @or, @h, @sub, @obs) RETURNING id", Conn, Tx);
        c.Parameters.AddWithValue("t", cmd.TitularId);
        c.Parameters.AddWithValue("c", cmd.Competencia.ToString());
        c.Parameters.AddWithValue("tp", cmd.Tipo);
        c.Parameters.AddWithValue("tr", cmd.Tributo);
        c.Parameters.AddWithValue("v", versao);
        c.Parameters.AddWithValue("val", (object?)cmd.ValorCentavos ?? DBNull.Value);
        c.Parameters.Add(new NpgsqlParameter("pl", NpgsqlDbType.Jsonb) { Value = cmd.Payload.GetRawText() });
        c.Parameters.AddWithValue("f", cmd.Fonte);
        c.Parameters.AddWithValue("or", (object?)cmd.OrigemRef ?? DBNull.Value);
        c.Parameters.AddWithValue("h", hash);
        c.Parameters.AddWithValue("sub", (object?)substituiId ?? DBNull.Value);
        c.Parameters.AddWithValue("obs", cmd.ObservadoEm.ToUniversalTime());
        var id = (Guid)(await c.ExecuteScalarAsync())!;
        return (id, versao);
    }

    public async Task<IReadOnlyList<FatoVigente>> VigentesDoCasoAsync(string titularId, Competencia competencia)
    {
        var rows = await Conn.QueryAsync<(Guid, string, string, string, string, int, long?, string, string)>(
            $"SELECT {Cols} FROM fatos.fato_vigente WHERE titular_id=@t AND competencia=@c",
            new { t = titularId, c = competencia.ToString() }, Tx);
        return rows.Select(Mapear).ToList();
    }
}

internal sealed class ConferenciaRepo : RepoBase, IConferenciaRepo
{
    public ConferenciaRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    public async Task<(Guid Id, ResultadoConferencia Resultado)> InserirOuReaproveitarAsync(NovaConferencia c)
    {
        // já existe? reaproveita (idempotência por cf+entregavel+entrada_hash)
        var existente = await Conn.QuerySingleOrDefaultAsync<(Guid id, string res)?>(
            @"SELECT id, resultado AS res FROM conferencias.conferencia
              WHERE cf=@cf AND entregavel_id=@e AND entrada_hash=@h",
            new { cf = c.Cf, e = c.EntregavelId, h = c.EntradaHash }, Tx);
        if (existente is { } ex)
            return (ex.id, ParseResultado(ex.res));

        // Mesma ordenação canônica usada por EntradaHash.Calcular (fato_id ordinal): o audit
        // persistido tem de reproduzir exatamente a entrada representada por entrada_hash (§9.2).
        var fatosUsadosJson = JsonSerializer.Serialize(
            c.FatosUsados
                .OrderBy(f => f.FatoId.ToString("D"), StringComparer.Ordinal)
                .Select(f => new { fato_id = f.FatoId, versao = f.Versao }));

        var cmd = new NpgsqlCommand(
            @"INSERT INTO conferencias.conferencia
              (cf, caso_id, entregavel_id, regra_versao_id, fatos_usados, entrada_hash, resultado,
               esperado_centavos, obtido_centavos, diferenca, severidade, classe)
              VALUES (@cf, @caso, @e, @rv, @fu, @h, @res, @esp, @obt, @dif, @sev, @cl)
              ON CONFLICT (cf, entregavel_id, entrada_hash) DO NOTHING
              RETURNING id", Conn, Tx);
        cmd.Parameters.AddWithValue("cf", c.Cf);
        cmd.Parameters.AddWithValue("caso", c.CasoId);
        cmd.Parameters.AddWithValue("e", c.EntregavelId);
        cmd.Parameters.AddWithValue("rv", c.RegraVersaoId);
        cmd.Parameters.Add(new NpgsqlParameter("fu", NpgsqlDbType.Jsonb) { Value = fatosUsadosJson });
        cmd.Parameters.AddWithValue("h", c.EntradaHash);
        cmd.Parameters.AddWithValue("res", NomeResultado(c.Resultado));
        cmd.Parameters.AddWithValue("esp", (object?)c.EsperadoCentavos ?? DBNull.Value);
        cmd.Parameters.AddWithValue("obt", (object?)c.ObtidoCentavos ?? DBNull.Value);
        cmd.Parameters.Add(new NpgsqlParameter("dif", NpgsqlDbType.Jsonb) { Value = c.Diferenca.GetRawText() });
        cmd.Parameters.AddWithValue("sev", c.Severidade);
        cmd.Parameters.AddWithValue("cl", c.Classe);
        var inserido = await cmd.ExecuteScalarAsync();
        if (inserido is Guid gid)
            return (gid, c.Resultado);

        // corrida: alguém inseriu entre o SELECT e o INSERT; relê
        var r2 = await Conn.QuerySingleAsync<(Guid id, string res)>(
            @"SELECT id, resultado AS res FROM conferencias.conferencia
              WHERE cf=@cf AND entregavel_id=@e AND entrada_hash=@h",
            new { cf = c.Cf, e = c.EntregavelId, h = c.EntradaHash }, Tx);
        return (r2.id, ParseResultado(r2.res));
    }

    private static string NomeResultado(ResultadoConferencia r) => r switch
    {
        ResultadoConferencia.Ok => "ok",
        ResultadoConferencia.Divergente => "divergente",
        ResultadoConferencia.Informativo => "informativo",
        _ => throw new ArgumentOutOfRangeException(nameof(r), r, null),
    };

    private static ResultadoConferencia ParseResultado(string s) => s switch
    {
        "ok" => ResultadoConferencia.Ok,
        "divergente" => ResultadoConferencia.Divergente,
        "informativo" => ResultadoConferencia.Informativo,
        _ => throw new ArgumentOutOfRangeException(nameof(s), s, null),
    };
}

internal sealed class TarefaRepo : RepoBase, ITarefaRepo
{
    public TarefaRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    public async Task<Guid> CriarAsync(Guid casoId, Guid entregavelId, string tipo, string responsavel)
    {
        return await Conn.ExecuteScalarAsync<Guid>(
            @"INSERT INTO trabalho.tarefa_humana (caso_id, entregavel_id, tipo, responsavel, estado)
              VALUES (@c, @e, @t, @r, 'aberta') RETURNING id",
            new { c = casoId, e = entregavelId, t = tipo, r = responsavel }, Tx);
    }

    public async Task<Guid?> AbertaDoEntregavelAsync(Guid entregavelId)
    {
        return await Conn.QuerySingleOrDefaultAsync<Guid?>(
            @"SELECT id FROM trabalho.tarefa_humana
              WHERE entregavel_id = @e AND estado = 'aberta' ORDER BY criada_em LIMIT 1",
            new { e = entregavelId }, Tx);
    }

    public async Task ConcluirAsync(Guid tarefaId, DateTimeOffset quando)
    {
        await Conn.ExecuteAsync(
            @"UPDATE trabalho.tarefa_humana SET estado='concluida', concluida_em=now() WHERE id=@id",
            new { id = tarefaId }, Tx);
    }

    public async Task CancelarAbertasAsync(Guid entregavelId)
    {
        await Conn.ExecuteAsync(
            @"UPDATE trabalho.tarefa_humana SET estado='cancelada'
              WHERE entregavel_id=@e AND estado='aberta'", new { e = entregavelId }, Tx);
    }
}

internal sealed class ExcecaoRepo : RepoBase, IExcecaoRepo
{
    public ExcecaoRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    public async Task<Guid> AbrirAsync(Guid casoId, Guid entregavelId, string tipo, string classe, string dono)
    {
        return await Conn.ExecuteScalarAsync<Guid>(
            @"INSERT INTO trabalho.excecao (caso_id, entregavel_id, tipo, classe, dono, estado)
              VALUES (@c, @e, @t, @cl, @d, 'aberta') RETURNING id",
            new { c = casoId, e = entregavelId, t = tipo, cl = classe, d = dono }, Tx);
    }
}

internal sealed class EventoRepo : RepoBase, IEventoRepo
{
    public EventoRepo(NpgsqlConnection c, NpgsqlTransaction t) : base(c, t) { }

    public async Task GravarAsync(string nome, Guid? casoId, Guid? entregavelId, JsonElement payload)
    {
        var cmd = new NpgsqlCommand(
            @"INSERT INTO eventos.evento (nome, caso_id, entregavel_id, payload)
              VALUES (@n, @c, @e, @p)", Conn, Tx);
        cmd.Parameters.AddWithValue("n", nome);
        cmd.Parameters.AddWithValue("c", (object?)casoId ?? DBNull.Value);
        cmd.Parameters.AddWithValue("e", (object?)entregavelId ?? DBNull.Value);
        cmd.Parameters.Add(new NpgsqlParameter("p", NpgsqlDbType.Jsonb) { Value = payload.GetRawText() });
        await cmd.ExecuteNonQueryAsync();
    }

    public async Task GravarComIdAsync(string nome, Guid? casoId, Guid? entregavelId, string chave, Guid valor)
    {
        var payload = JsonSerializer.Serialize(new Dictionary<string, string> { [chave] = valor.ToString() });
        using var doc = JsonDocument.Parse(payload);
        await GravarAsync(nome, casoId, entregavelId, doc.RootElement);
    }
}
