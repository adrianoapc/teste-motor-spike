<?php

declare(strict_types=1);

namespace App\Aplicacao\Conferencias;

/**
 * Saída bruta de uma fórmula de conferência (§9.1): valores e os fatos usados.
 * A classificação (ok/divergente, severidade) é feita depois, pelo Conferidor
 * com a tolerância (§9.2).
 */
final readonly class ResultadoFormula
{
    /**
     * @param  list<array{fato_id: string, versao: int}>  $fatosUsados
     * @param  array<string, mixed>  $diferencaExtra  campos extra da diferença (CF-06)
     */
    public function __construct(
        public ?int $esperadoCentavos,
        public ?int $obtidoCentavos,
        public array $fatosUsados,
        public bool $comparacaoTexto = false,
        public bool $textoIgual = false,
        public array $diferencaExtra = [],
        public ?string $fatoAusente = null,
    ) {}

    /**
     * @param  list<array{fato_id: string, versao: int}>  $fatosUsados  fatos já consultados (existentes), para o entrada_hash mudar com a versão deles
     */
    public static function fatoAusente(string $descricao, array $fatosUsados = []): self
    {
        return new self(null, null, $fatosUsados, fatoAusente: $descricao);
    }
}
