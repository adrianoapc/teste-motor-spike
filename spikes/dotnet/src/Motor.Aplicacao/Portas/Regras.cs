using System.Text.Json;
using Motor.Dominio.Conferencias;
using Motor.Dominio.Fechamento;
using Motor.Dominio.Regras;

namespace Motor.Aplicacao;

public sealed record RegraVersao(Guid Id, JsonElement Conteudo);

public interface IRegrasRepo
{
    /// <summary>Versão em uso (status provisoria|ativa) de uma chave de regra.</summary>
    Task<RegraVersao?> VersaoEmUsoAsync(string chave);

    /// <summary>Conteúdo de uma versão de regra pelo seu id — usado para carregar a versão
    /// FIXADA no caso (caso.RegraVersaoId), e não a versão atualmente em uso. (§13, imutabilidade)</summary>
    Task<JsonElement?> ConteudoPorIdAsync(Guid versaoId);
}
