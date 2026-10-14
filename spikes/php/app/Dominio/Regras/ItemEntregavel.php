<?php

declare(strict_types=1);

namespace App\Dominio\Regras;

/**
 * Um item de entregável da regra 'fechamento.entregaveis'.
 */
final readonly class ItemEntregavel
{
    /**
     * @param  list<array<string, scalar>>  $quando
     * @param  list<DependenciaRegra>  $dependeDe
     * @param  list<EntradaRegra>  $entradas
     * @param  list<ConferenciaRegra>  $conferencias
     */
    public function __construct(
        public string $tipo,
        public string $executor,
        public array $quando,
        public array $dependeDe,
        public array $entradas,
        public ?string $saidaTipo,
        public array $conferencias,
        public bool $encerraCaso,
        public ?PosValidacao $posValidacao,
        public ?Acompanhamento $acompanhamento,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     */
    public static function de(array $item, string $estadoMinimoPadrao): self
    {
        $saida = $item['saida'] ?? null;
        $saidaTipo = (is_array($saida) && isset($saida['tipo']) && is_string($saida['tipo'])) ? $saida['tipo'] : null;

        $pos = $item['pos_validacao'] ?? null;
        $acomp = $item['acompanhamento'] ?? null;
        /** @var array<string, mixed> $posArr */
        $posArr = is_array($pos) ? $pos : [];
        /** @var array<string, mixed> $acompArr */
        $acompArr = is_array($acomp) ? $acomp : [];

        return new self(
            tipo: is_string($item['tipo'] ?? null) ? $item['tipo'] : '',
            executor: is_string($item['executor'] ?? null) ? $item['executor'] : 'sistema',
            quando: self::listaDeObjetos($item['quando'] ?? []),
            dependeDe: self::dependencias($item['depende_de'] ?? [], $estadoMinimoPadrao),
            entradas: self::entradas($item['entradas'] ?? []),
            saidaTipo: $saidaTipo,
            conferencias: self::conferencias($item['conferencias'] ?? []),
            encerraCaso: ($item['encerra_caso'] ?? false) === true,
            posValidacao: is_array($pos) ? PosValidacao::de($posArr) : null,
            acompanhamento: is_array($acomp) ? Acompanhamento::de($acompArr) : null,
        );
    }

    /**
     * @return list<array<string, scalar>>
     */
    private static function listaDeObjetos(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [];
        }
        $saida = [];
        foreach ($valor as $obj) {
            if (is_array($obj)) {
                $pares = [];
                foreach ($obj as $k => $v) {
                    if (is_string($k) && is_scalar($v)) {
                        $pares[$k] = $v;
                    }
                }
                $saida[] = $pares;
            }
        }

        return $saida;
    }

    /**
     * @return list<DependenciaRegra>
     */
    private static function dependencias(mixed $valor, string $estadoMinimoPadrao): array
    {
        if (! is_array($valor)) {
            return [];
        }
        $saida = [];
        foreach ($valor as $dep) {
            if (is_array($dep) && is_string($dep['tipo'] ?? null)) {
                $estadoMin = is_string($dep['estado_minimo'] ?? null) ? $dep['estado_minimo'] : $estadoMinimoPadrao;
                $saida[] = new DependenciaRegra($dep['tipo'], $estadoMin, self::listaDeObjetos($dep['quando'] ?? []));
            }
        }

        return $saida;
    }

    /**
     * @return list<EntradaRegra>
     */
    private static function entradas(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [];
        }
        $saida = [];
        foreach ($valor as $ent) {
            if (is_array($ent) && is_string($ent['tipo'] ?? null)) {
                $tributo = is_string($ent['tributo'] ?? null) ? $ent['tributo'] : '';
                $rel = is_int($ent['competencia_relativa'] ?? null) ? $ent['competencia_relativa'] : 0;
                $saida[] = new EntradaRegra($ent['tipo'], $tributo, $rel, self::listaDeObjetos($ent['quando'] ?? []));
            }
        }

        return $saida;
    }

    /**
     * @return list<ConferenciaRegra>
     */
    private static function conferencias(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [];
        }
        $saida = [];
        foreach ($valor as $cf) {
            if (is_array($cf) && is_string($cf['cf'] ?? null)) {
                $saida[] = new ConferenciaRegra($cf['cf'], self::listaDeObjetos($cf['quando'] ?? []));
            }
        }

        return $saida;
    }
}
