<?php

declare(strict_types=1);

namespace App\Dominio\Prazos;

/**
 * Calendário de vencimentos (§18.1/§18.2, v6), função pura. Guarda os feriados nacionais e as
 * prorrogações da versão em uso de `calendario.vencimentos`. Opera só com datas (sem relógio):
 * o motor é sem relógio, quem precisa de "hoje" passa `data_ref`.
 *
 * - Dia útil: não é sábado, não é domingo e não está em `feriados`.
 * - antecipa: anda para trás até o primeiro dia útil (inclusive o próprio, se já for útil).
 * - posterga: anda para frente até o primeiro dia útil.
 */
final class Calendario
{
    /** @var array<string, true> feriados indexados por 'AAAA-MM-DD' */
    private array $feriados;

    /** @var list<array<string, mixed>> */
    private array $prorrogacoes;

    /**
     * @param  list<string>  $feriados  datas 'AAAA-MM-DD'
     * @param  list<array<string, mixed>>  $prorrogacoes
     */
    public function __construct(array $feriados, array $prorrogacoes)
    {
        $this->feriados = array_fill_keys($feriados, true);
        $this->prorrogacoes = $prorrogacoes;
    }

    /**
     * Lê o conteúdo de uma versão de `calendario.vencimentos`.
     *
     * @param  array<string, mixed>  $conteudo
     */
    public static function de(array $conteudo): self
    {
        $feriadosBruto = $conteudo['feriados'] ?? null;
        /** @var list<string> $feriados */
        $feriados = is_array($feriadosBruto) ? array_values(array_filter($feriadosBruto, 'is_string')) : [];

        $prorrBruto = $conteudo['prorrogacoes'] ?? null;
        /** @var list<array<string, mixed>> $prorrogacoes */
        $prorrogacoes = is_array($prorrBruto) ? array_values(array_filter($prorrBruto, 'is_array')) : [];

        return new self($feriados, $prorrogacoes);
    }

    public function ehDiaUtil(Data $d): bool
    {
        $dow = $d->diaDaSemana(); // 6 = sábado, 7 = domingo (ISO-8601)
        if ($dow === 6 || $dow === 7) {
            return false;
        }

        return ! isset($this->feriados[$d->iso()]);
    }

    /** Volta ao dia útil anterior (inclusive o próprio, se já for útil). */
    public function antecipa(Data $d): Data
    {
        while (! $this->ehDiaUtil($d)) {
            $d = $d->maisDias(-1);
        }

        return $d;
    }

    /** Avança ao próximo dia útil (inclusive o próprio, se já for útil). */
    public function posterga(Data $d): Data
    {
        while (! $this->ehDiaUtil($d)) {
            $d = $d->maisDias(1);
        }

        return $d;
    }

    /** Anda $n dias úteis para trás a partir de $d (n >= 0; 0 = o próprio $d, sem ajuste). */
    public function recuarDiasUteis(Data $d, int $n): Data
    {
        for ($i = 0; $i < $n; $i++) {
            $d = $d->maisDias(-1);
            while (! $this->ehDiaUtil($d)) {
                $d = $d->maisDias(-1);
            }
        }

        return $d;
    }

    /** Prorrogação (nova_data) para uma obrigação e competência, ou null. */
    public function prorrogacao(string $obrigacao, string $competencia): ?Data
    {
        foreach ($this->prorrogacoes as $p) {
            $nova = $p['nova_data'] ?? null;
            if (($p['obrigacao'] ?? null) === $obrigacao && ($p['competencia'] ?? null) === $competencia && is_string($nova)) {
                return Data::de($nova);
            }
        }

        return null;
    }
}
