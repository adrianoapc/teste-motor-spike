using System.Text.Json;
using System.Text.RegularExpressions;
using Motor.Api;
using Motor.Aplicacao;
using Motor.Dominio.Fechamento;
using Motor.Infraestrutura;

var builder = WebApplication.CreateBuilder(args);

// Logs: uma linha JSON por registro, em stdout (tech.md).
builder.Logging.ClearProviders();
builder.Logging.AddJsonConsole(o =>
{
    o.IncludeScopes = false;
    o.JsonWriterOptions = new JsonWriterOptions { Indented = false };
});

var conexao = Environment.GetEnvironmentVariable("MOTOR_DB")
    ?? throw new InvalidOperationException("variável de ambiente MOTOR_DB ausente");

builder.Services.AddSingleton<IFabricaUnidade>(_ => new FabricaUnidade(conexao));
builder.Services.AddSingleton<MotorServico>();

var app = builder.Build();

var jsonOpts = new JsonSerializerOptions(JsonSerializerDefaults.Web);

IResult Erro(int status, string erro, string mensagem) =>
    Results.Json(new { erro, mensagem }, jsonOpts, statusCode: status);

// ---- /health ----
app.MapGet("/health", async (IFabricaUnidade fab) =>
{
    var ok = await fab.BancoRespondeAsync();
    return ok ? Results.Json(new { status = "ok" }, jsonOpts) : Erro(503, "banco_indisponivel", "banco não respondeu");
});

// ---- /admin/fila ----
app.MapGet("/admin/fila", async (IFabricaUnidade fab) =>
{
    var fila = await fab.ContarFilaAsync();
    return Results.Json(new { pendentes = fila.Pendentes, com_erro = fila.ComErro }, jsonOpts);
});

// ---- /competencias/abrir ----
app.MapPost("/competencias/abrir", async (HttpRequest req, MotorServico motor) =>
{
    JsonDocument doc;
    try { doc = await JsonDocument.ParseAsync(req.Body, Validacao.OpcoesParse); }
    catch (JsonException) { return Erro(422, "corpo_invalido", "JSON inválido"); }
    using (doc)
    {
        var raiz = doc.RootElement;
        if (raiz.ValueKind != JsonValueKind.Object)
            return Erro(422, "corpo_invalido", "corpo deve ser um objeto JSON");
        if (!Validacao.CorpoSemNul(raiz, out var motivoNul))
            return Erro(422, "corpo_invalido", motivoNul);
        if (!Validacao.CompetenciaValida(raiz, out var compTexto))
            return Erro(422, "corpo_invalido", "competência ausente ou inválida");
        if (!raiz.TryGetProperty("ator", out var ator) || ator.ValueKind != JsonValueKind.String || string.IsNullOrEmpty(ator.GetString()))
            return Erro(422, "corpo_invalido", "ator ausente");
        if (!raiz.TryGetProperty("empresas", out var empresas) || empresas.ValueKind != JsonValueKind.Array || empresas.GetArrayLength() == 0)
            return Erro(422, "corpo_invalido", "empresas ausente ou vazio");

        var lista = new List<MotorServico.EmpresaDto>();
        foreach (var e in empresas.EnumerateArray())
        {
            if (!Validacao.EmpresaValida(e, out var motivo))
                return Erro(422, "corpo_invalido", $"empresa inválida: {motivo}");
            var titular = e.GetProperty("titular_id").GetString()!;
            var carteira = e.GetProperty("carteira").GetString()!;
            lista.Add(new MotorServico.EmpresaDto(titular, e.Clone(), carteira));
        }

        var competencia = Competencia.Analisar(compTexto);
        var res = await motor.AbrirCompetenciasAsync(competencia, lista);
        return Results.Json(new
        {
            criados = res.Criados.Select(x => new { titular_id = x.TitularId, caso_id = x.CasoId }),
            existentes = res.Existentes.Select(x => new { titular_id = x.TitularId, caso_id = x.CasoId }),
        }, jsonOpts);
    }
});

// ---- /fatos ----
app.MapPost("/fatos", async (HttpRequest req, MotorServico motor) =>
{
    JsonDocument doc;
    try { doc = await JsonDocument.ParseAsync(req.Body, Validacao.OpcoesParse); }
    catch (JsonException) { return Erro(422, "corpo_invalido", "JSON inválido"); }
    using (doc)
    {
        var raiz = doc.RootElement;
        if (raiz.ValueKind != JsonValueKind.Object)
            return Erro(422, "corpo_invalido", "corpo deve ser um objeto JSON");
        if (!Validacao.CorpoSemNul(raiz, out var motivoNul))
            return Erro(422, "corpo_invalido", motivoNul);
        if (!Validacao.CampoTextoNaoVazio(raiz, "titular_id", out var titular))
            return Erro(422, "corpo_invalido", "titular_id ausente");
        if (!Validacao.CompetenciaValida(raiz, out var compTexto))
            return Erro(422, "corpo_invalido", "competência ausente ou inválida");
        if (!Validacao.CampoTextoNaoVazio(raiz, "tipo", out var tipo))
            return Erro(422, "corpo_invalido", "tipo ausente");
        if (!Validacao.CampoTextoNaoVazio(raiz, "fonte", out var fonte))
            return Erro(422, "corpo_invalido", "fonte ausente");
        if (!raiz.TryGetProperty("observado_em", out var obsEl) || obsEl.ValueKind != JsonValueKind.String
            || !DateTimeOffset.TryParse(obsEl.GetString(), out var observadoEm))
            return Erro(422, "corpo_invalido", "observado_em ausente ou inválido");

        if (!Validacao.TextoOpcionalValido(raiz, "tributo", out var tributoOpc) )
            return Erro(422, "corpo_invalido", "tributo deve ser string");
        var tributo = tributoOpc ?? "";
        if (!Validacao.ValorCentavosValido(raiz, out var valor))
            return Erro(422, "corpo_invalido", "valor_centavos deve ser inteiro (int64) ou null");
        if (!Validacao.PayloadValido(raiz, out var payload, out var motivoPayload))
            return Erro(422, "corpo_invalido", motivoPayload);
        if (!Validacao.TextoOpcionalValido(raiz, "origem_ref", out var origemRef, aceitaNull: true))
            return Erro(422, "corpo_invalido", "origem_ref deve ser string");

        var cmd = new PublicarFatoCmd(titular, Competencia.Analisar(compTexto), tipo, tributo, valor, payload, fonte, origemRef, observadoEm);
        var res = await motor.PublicarFatoAsync(cmd);
        return Results.Json(new
        {
            fato_id = res.FatoId,
            versao = res.Versao,
            efeito = res.Efeito switch
            {
                EfeitoFato.Novo => "novo",
                EfeitoFato.NovaVersao => "nova_versao",
                _ => "sem_mudanca",
            },
        }, jsonOpts);
    }
});

// ---- /casos/{titular_id}/{competencia}/entregaveis/{tipo}/concluir ----
app.MapPost("/casos/{titularId}/{competencia}/entregaveis/{tipo}/concluir",
    async (string titularId, string competencia, string tipo, HttpRequest req, MotorServico motor) =>
{
    if (!Validacao.CompetenciaTextoValida(competencia))
        return Erro(422, "corpo_invalido", "competência inválida");
    if (Validacao.ContemNul(competencia) || Validacao.ContemNul(titularId) || Validacao.ContemNul(tipo))
        return Erro(422, "corpo_invalido", "parâmetro de caminho com U+0000");
    JsonDocument doc;
    try { doc = await JsonDocument.ParseAsync(req.Body, Validacao.OpcoesParse); }
    catch (JsonException) { return Erro(422, "corpo_invalido", "JSON inválido"); }
    using (doc)
    {
        var raiz = doc.RootElement;
        if (raiz.ValueKind != JsonValueKind.Object)
            return Erro(422, "corpo_invalido", "corpo deve ser um objeto JSON");
        if (!Validacao.CorpoSemNul(raiz, out var motivoNul))
            return Erro(422, "corpo_invalido", motivoNul);
        if (!Validacao.CampoTextoNaoVazio(raiz, "ator", out var ator))
            return Erro(422, "corpo_invalido", "ator ausente");
        if (!raiz.TryGetProperty("saidas", out var saidas) || saidas.ValueKind != JsonValueKind.Array || saidas.GetArrayLength() == 0)
            return Erro(422, "corpo_invalido", "saidas ausente ou vazio");

        var lista = new List<MotorServico.SaidaDto>();
        foreach (var s in saidas.EnumerateArray())
        {
            if (s.ValueKind != JsonValueKind.Object)
                return Erro(422, "corpo_invalido", "cada saída deve ser um objeto JSON");
            if (!Validacao.TextoOpcionalValido(s, "tributo", out var tributoOpc))
                return Erro(422, "corpo_invalido", "saida.tributo deve ser string");
            var tributo = tributoOpc ?? "";
            if (!Validacao.ValorCentavosValido(s, out var valor))
                return Erro(422, "corpo_invalido", "saida.valor_centavos deve ser inteiro (int64) ou null");
            if (!Validacao.PayloadValido(s, out var payload, out var motivoPayload))
                return Erro(422, "corpo_invalido", $"saida.{motivoPayload}");
            lista.Add(new MotorServico.SaidaDto(tributo, valor, payload));
        }

        var res = await motor.ConcluirTarefaAsync(titularId, Competencia.Analisar(competencia), tipo, ator, lista);
        return res.Erro switch
        {
            MotorServico.ConcluirErro.NaoEncontrado => Erro(404, "nao_encontrado", res.Mensagem ?? "não encontrado"),
            MotorServico.ConcluirErro.Conflito => Erro(409, "entregavel_nao_pronto", res.Mensagem ?? "estado não permite"),
            MotorServico.ConcluirErro.Invalido => Erro(422, "corpo_invalido", res.Mensagem ?? "corpo inválido"),
            _ => Results.Json(new
            {
                entregavel_id = res.Entregavel!.Id,
                tipo = res.Entregavel.Tipo,
                estado = res.Entregavel.Estado.Nome(),
            }, jsonOpts),
        };
    }
});

// ---- POST /casos/{titular_id}/{competencia}/entregaveis/{tipo}/override (§8.1) ----
app.MapPost("/casos/{titularId}/{competencia}/entregaveis/{tipo}/override",
    async (string titularId, string competencia, string tipo, HttpRequest req, MotorServico motor) =>
{
    if (!Validacao.CompetenciaTextoValida(competencia))
        return Erro(422, "corpo_invalido", "competência inválida");
    if (Validacao.ContemNul(competencia) || Validacao.ContemNul(titularId) || Validacao.ContemNul(tipo))
        return Erro(422, "corpo_invalido", "parâmetro de caminho com U+0000");
    JsonDocument doc;
    try { doc = await JsonDocument.ParseAsync(req.Body, Validacao.OpcoesParse); }
    catch (JsonException) { return Erro(422, "corpo_invalido", "JSON inválido"); }
    using (doc)
    {
        var raiz = doc.RootElement;
        if (raiz.ValueKind != JsonValueKind.Object)
            return Erro(422, "corpo_invalido", "corpo deve ser um objeto JSON");
        if (!Validacao.CorpoSemNul(raiz, out var motivoNul))
            return Erro(422, "corpo_invalido", motivoNul);
        if (!Validacao.CampoTextoNaoVazio(raiz, "ator", out var ator))
            return Erro(422, "corpo_invalido", "ator ausente");
        if (!Validacao.CampoTextoNaoVazio(raiz, "cf", out var cf) || !Regex.IsMatch(cf, @"\ACF-[0-9]{2}\z"))
            return Erro(422, "corpo_invalido", "cf ausente ou fora do padrão CF-nn");
        if (!Validacao.CampoTextoNaoVazio(raiz, "motivo", out var motivo) || motivo.Length < 5)
            return Erro(422, "corpo_invalido", "motivo ausente ou com menos de 5 caracteres");

        var res = await motor.RegistrarOverrideAsync(titularId, Competencia.Analisar(competencia), tipo, ator, cf, motivo);
        return res.Erro switch
        {
            MotorServico.OverrideErro.NaoEncontrado => Erro(404, "nao_encontrado", res.Mensagem ?? "não encontrado"),
            MotorServico.OverrideErro.Conflito => Erro(409, "override_nao_permitido", res.Mensagem ?? "estado não permite"),
            MotorServico.OverrideErro.Invalido => Erro(422, "corpo_invalido", res.Mensagem ?? "corpo inválido"),
            _ => Results.Json(new
            {
                entregavel_id = res.Entregavel!.Id,
                tipo = res.Entregavel.Tipo,
                estado = res.Entregavel.Estado.Nome(),
            }, jsonOpts),
        };
    }
});

// ---- GET /casos/{titular_id}/{competencia} ----
app.MapGet("/casos/{titularId}/{competencia}", async (string titularId, string competencia, MotorServico motor) =>
{
    if (!Validacao.CompetenciaTextoValida(competencia))
        return Erro(422, "corpo_invalido", "competência inválida");
    if (Validacao.ContemNul(titularId))
        return Erro(422, "corpo_invalido", "parâmetro de caminho com U+0000");
    var caso = await motor.ConsultarCasoAsync(titularId, Competencia.Analisar(competencia));
    if (caso is null) return Erro(404, "nao_encontrado", "caso inexistente");
    return Results.Json(new
    {
        caso_id = caso.CasoId,
        titular_id = caso.TitularId,
        competencia = caso.Competencia,
        estado = caso.Estado,
        entregaveis = caso.Entregaveis.Select(e => new
        {
            entregavel_id = e.EntregavelId,
            tipo = e.Tipo,
            estado = e.Estado,
            executor = e.Executor,
        }),
        tarefas_abertas = caso.TarefasAbertas.Select(t => new
        {
            tarefa_id = t.TarefaId,
            entregavel_tipo = t.EntregavelTipo,
        }),
        excecoes_abertas = caso.ExcecoesAbertas.Select(x => new
        {
            excecao_id = x.ExcecaoId,
            tipo = x.Tipo,
            classe = x.Classe,
        }),
    }, jsonOpts);
});

app.Run();

/// <summary>Marcador para WebApplicationFactory nos testes.</summary>
public partial class Program { }
