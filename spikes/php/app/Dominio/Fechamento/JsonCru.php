<?php

declare(strict_types=1);

namespace App\Dominio\Fechamento;

use JsonException;

/**
 * Decodificador JSON sem coerção de número para float (semântica §6, contrato/vetores-hash.json).
 *
 * `json_decode` do PHP converte número não inteiro (ou inteiro além de int64) em float binário, perdendo
 * dígitos. Aqui:
 *  - inteiro que cabe em int64 (e não é "-0") → int nativo;
 *  - qualquer outro número (fração, expoente, "-0", fora de int64) → NumeroJson, marcador de "número
 *    inválido para o payload" (guarda só o lexema, para mensagens; nunca vira float);
 *  - objetos → stdClass, listas → array (preserva {} vs []).
 *
 * Regra de negócio (decisão do usuário, PR #7): números do `payload` só podem ser inteiros int64;
 * qualquer NumeroJson, em qualquer profundidade, é rejeitado com 422 `corpo_invalido`
 * (ver `somenteInteiros`). Logo o JSON canônico do hash só contém inteiros decimais simples, idênticos
 * em qualquer linguagem. Esta classe REVOGA a convenção anterior ("lexema exato como enviado").
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

    /** true se todo número em qualquer profundidade é int64 nativo (nenhum NumeroJson, nenhum float). */
    public static function somenteInteiros(mixed $v): bool
    {
        if ($v instanceof NumeroJson || is_float($v)) {
            return false;
        }
        if ($v instanceof \stdClass) {
            foreach (get_object_vars($v) as $x) {
                if (! self::somenteInteiros($x)) {
                    return false;
                }
            }
        } elseif (is_array($v)) {
            foreach ($v as $x) {
                if (! self::somenteInteiros($x)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Serializa o valor decodificado (NumeroJson não é serializável: é rejeitado antes, na borda). Com $ordenar, ordena chaves de objeto (JSON canônico, §6).
     * Sem espaços, UTF-8 e barras sem escape.
     */
    public static function codificar(mixed $v, bool $ordenar): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;
        if ($v instanceof NumeroJson) {
            throw new JsonException('número não inteiro/int64 não entra no JSON canônico: '.$v->lexema);
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
