<?php

declare(strict_types=1);

namespace App\Dominio\Fechamento;

use JsonException;

/**
 * JSON que preserva os números (design: decisão registrada na rodada 8 da revisão do PR #4).
 *
 * `json_decode` do PHP converte número não inteiro (ou inteiro além de int64) em float binário,
 * o que perde dígitos (0.1234567890123456789 → 0.12345678901234568) e alteraria o fato guardado e
 * o hash canônico (§6). Aqui:
 *  - inteiro que cabe em int64 (e não é "-0") → int nativo;
 *  - qualquer outro número → NumeroJson com o lexema exato, serializado de volta caractere a caractere;
 *  - objetos → stdClass, listas → array (preserva {} vs []).
 * No hash canônico, o número entra com o seu lexema (1.0 e 1 são valores diferentes, e 1.50 ≠ 1.5): o
 * hash é sobre a representação enviada, não sobre o valor numérico. Os demais spikes precisam adotar a mesma
 * convenção (lexema como enviado) para os hashes baterem em números não inteiros.
 */
final class JsonCru
{
    private const PROFUNDIDADE_MAX = 512;

    private int $i = 0;

    private function __construct(private readonly string $s) {}

    /** @throws JsonException */
    public static function decodificar(string $json): mixed
    {
        $p = new self($json);
        $p->espacos();
        $v = $p->valor(1);
        $p->espacos();
        if ($p->i !== strlen($json)) {
            throw new JsonException('conteúdo após o fim do JSON');
        }

        return $v;
    }

    /**
     * Serializa preservando NumeroJson. Com $ordenar, ordena chaves de objeto (JSON canônico, §6).
     * Sem espaços, UTF-8 e barras sem escape.
     */
    public static function codificar(mixed $v, bool $ordenar): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;
        if ($v instanceof NumeroJson) {
            return $v->lexema;
        }
        if ($v instanceof \stdClass) {
            $pares = get_object_vars($v);
            if ($ordenar) {
                uksort($pares, static fn (string|int $a, string|int $b): int => strcmp((string) $a, (string) $b));
            }
            $partes = [];
            foreach ($pares as $k => $x) {
                $partes[] = json_encode((string) $k, $flags).':'.self::codificar($x, $ordenar);
            }

            return '{'.implode(',', $partes).'}';
        }
        if (is_array($v)) {
            return '['.implode(',', array_map(static fn (mixed $x): string => self::codificar($x, $ordenar), array_values($v))).']';
        }

        return json_encode($v, $flags);
    }

    private function valor(int $profundidade): mixed
    {
        if ($profundidade > self::PROFUNDIDADE_MAX) {
            throw new JsonException('profundidade máxima excedida');
        }
        $c = $this->s[$this->i] ?? '';
        if ($c === '{') {
            return $this->objeto($profundidade);
        }
        if ($c === '[') {
            return $this->lista($profundidade);
        }
        if ($c === '"') {
            return $this->texto();
        }
        foreach (['true' => true, 'false' => false, 'null' => null] as $palavra => $valor) {
            if (substr($this->s, $this->i, strlen($palavra)) === $palavra) {
                $this->i += strlen($palavra);

                return $valor;
            }
        }

        return $this->numero();
    }

    private function objeto(int $profundidade): \stdClass
    {
        $o = new \stdClass;
        $this->i++;
        $this->espacos();
        if (($this->s[$this->i] ?? '') === '}') {
            $this->i++;

            return $o;
        }
        while (true) {
            $this->espacos();
            if (($this->s[$this->i] ?? '') !== '"') {
                throw new JsonException('chave de objeto esperada');
            }
            $chave = $this->texto();
            $this->espacos();
            if (($this->s[$this->i] ?? '') !== ':') {
                throw new JsonException('":" esperado');
            }
            $this->i++;
            $this->espacos();
            $o->{$chave} = $this->valor($profundidade + 1);
            $this->espacos();
            $c = $this->s[$this->i] ?? '';
            $this->i++;
            if ($c === '}') {
                return $o;
            }
            if ($c !== ',') {
                throw new JsonException('"," ou "}" esperado');
            }
        }
    }

    /** @return list<mixed> */
    private function lista(int $profundidade): array
    {
        $a = [];
        $this->i++;
        $this->espacos();
        if (($this->s[$this->i] ?? '') === ']') {
            $this->i++;

            return $a;
        }
        while (true) {
            $this->espacos();
            $a[] = $this->valor($profundidade + 1);
            $this->espacos();
            $c = $this->s[$this->i] ?? '';
            $this->i++;
            if ($c === ']') {
                return $a;
            }
            if ($c !== ',') {
                throw new JsonException('"," ou "]" esperado');
            }
        }
    }

    private function texto(): string
    {
        $ini = $this->i;
        $this->i++;
        $n = strlen($this->s);
        while ($this->i < $n) {
            $c = $this->s[$this->i];
            if ($c === '\\') {
                $this->i += 2;

                continue;
            }
            if ($c === '"') {
                $this->i++;
                $decodificado = json_decode(substr($this->s, $ini, $this->i - $ini), false, 512, JSON_THROW_ON_ERROR);
                if (! is_string($decodificado)) {
                    throw new JsonException('texto inválido');
                }

                return $decodificado;
            }
            $this->i++;
        }
        throw new JsonException('texto não terminado');
    }

    private function numero(): int|NumeroJson
    {
        if (preg_match('/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $this->s, $m, 0, $this->i) !== 1) {
            throw new JsonException('valor JSON inválido');
        }
        $lexema = $m[0];
        $this->i += strlen($lexema);
        if (preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $lexema) === 1 && $lexema !== '-0' && (string) (int) $lexema === $lexema) {
            return (int) $lexema;
        }

        return new NumeroJson($lexema);
    }

    private function espacos(): void
    {
        $n = strlen($this->s);
        while ($this->i < $n && ($this->s[$this->i] === ' ' || $this->s[$this->i] === "\t" || $this->s[$this->i] === "\n" || $this->s[$this->i] === "\r")) {
            $this->i++;
        }
    }
}
