using System.Text.RegularExpressions;
using Xunit;

namespace Motor.Testes.Arquitetura;

/// <summary>Um módulo de negócio não lê tabela de outro módulo (structure.md): cada pasta de repositório da
/// infraestrutura só pode citar o esquema SQL do seu módulo. A leitura entre módulos passa pela porta do dono,
/// na camada de aplicação.</summary>
public class ModulosTeste
{
    private static readonly string[] Esquemas = ["fechamento", "fatos", "conferencias", "trabalho", "eventos", "regras"];

    private static readonly Regex TabelaCitada = new(@"\b(" + string.Join("|", Esquemas) + @")\.[a-z_]+", RegexOptions.Compiled);

    private static string PastaInfraestrutura()
    {
        var dir = new DirectoryInfo(AppContext.BaseDirectory);
        while (dir is not null && !Directory.Exists(Path.Combine(dir.FullName, "src", "Motor.Infraestrutura")))
            dir = dir.Parent;
        Assert.True(dir is not null, "src/Motor.Infraestrutura não encontrado a partir do diretório de testes");
        return Path.Combine(dir!.FullName, "src", "Motor.Infraestrutura");
    }

    /// <summary>Pasta (módulo) -> esquema que ela pode citar. O diagnóstico de fila na raiz lê a fila do módulo eventos.</summary>
    public static TheoryData<string, string> Modulos => new()
    {
        { "Fechamento", "fechamento" },
        { "Fatos", "fatos" },
        { "Conferencias", "conferencias" },
        { "Trabalho", "trabalho" },
        { "Eventos", "eventos" },
        { "Regras", "regras" },
    };

    [Theory, MemberData(nameof(Modulos))]
    public void Repositorios_so_citam_o_esquema_do_proprio_modulo(string pasta, string esquemaProprio)
    {
        var arquivos = Directory.GetFiles(Path.Combine(PastaInfraestrutura(), pasta), "*.cs");
        Assert.NotEmpty(arquivos);
        var violacoes = arquivos
            .SelectMany(a => TabelaCitada.Matches(File.ReadAllText(a)).Select(m => (Arquivo: Path.GetFileName(a), Tabela: m.Value)))
            .Where(x => !x.Tabela.StartsWith(esquemaProprio + ".", StringComparison.Ordinal))
            .Select(x => $"{x.Arquivo}: {x.Tabela}")
            .Distinct()
            .ToList();
        Assert.True(violacoes.Count == 0, $"módulo {pasta} lê tabela de outro módulo: " + string.Join(", ", violacoes));
    }

    [Fact]
    public void Fora_das_pastas_de_modulo_so_o_diagnostico_de_fila_toca_tabela()
    {
        var raiz = Directory.GetFiles(PastaInfraestrutura(), "*.cs");
        var citadas = raiz
            .SelectMany(a => TabelaCitada.Matches(File.ReadAllText(a)).Select(m => (Arquivo: Path.GetFileName(a), Tabela: m.Value)))
            .Where(x => x.Tabela != "eventos.fila")
            .ToList();
        Assert.True(citadas.Count == 0, "SQL fora de uma pasta de módulo: " + string.Join(", ", citadas.Select(x => $"{x.Arquivo}: {x.Tabela}")));
    }
}
