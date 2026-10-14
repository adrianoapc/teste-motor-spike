<?php

declare(strict_types=1);

namespace App\Dominio\Fechamento;

/**
 * Contexto do caso (§3): o snapshot da empresa mais o campo derivado
 * mes_do_trimestre. Usado pelas condições `quando`.
 */
final readonly class Contexto
{
    /**
     * @param  array<string, scalar>  $campos  campos do contexto já achatados
     */
    private function __construct(public array $campos) {}

    /**
     * @param  array<string, mixed>  $snapshot  dados da empresa (contrato §Empresa)
     */
    public static function de(array $snapshot, Competencia $competencia): self
    {
        $campos = [];
        foreach ($snapshot as $chave => $valor) {
            if (is_scalar($valor)) {
                $campos[$chave] = $valor;
            }
        }
        $campos['mes_do_trimestre'] = $competencia->mesDoTrimestre();

        return new self($campos);
    }

    public function valor(string $campo): string|int|float|bool|null
    {
        return $this->campos[$campo] ?? null;
    }

    public function temCampo(string $campo): bool
    {
        return array_key_exists($campo, $this->campos);
    }
}
