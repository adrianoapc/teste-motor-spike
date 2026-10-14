using Motor.Dominio.Conferencias;
using Motor.Dominio.Regras;

namespace Motor.Aplicacao;

/// <summary>Regras fiscais em uso (§2): tolerância e parâmetros. Lidas do banco pelo <c>status</c>,
/// nunca por número de versão. <c>ProlaboreBp</c> nulo = ausente/malformado (CF-08 vira operando inválido).</summary>
public sealed record FiscalEmUso(Tolerancia Tolerancia, Guid ToleranciaVersaoId, long? ProlaboreBp)
{
    public static async Task<FiscalEmUso> CarregarAsync(IRegrasRepo regras)
    {
        var tol = await regras.VersaoEmUsoAsync("fiscal.tolerancia")
            ?? throw new InvalidOperationException("fiscal.tolerancia sem versão em uso");
        var parametros = await regras.VersaoEmUsoAsync("fiscal.parametros");
        return new FiscalEmUso(
            ParametrosFiscais.LerTolerancia(tol.Conteudo),
            tol.Id,
            ParametrosFiscais.LerProlaboreBp(parametros?.Conteudo));
    }
}
