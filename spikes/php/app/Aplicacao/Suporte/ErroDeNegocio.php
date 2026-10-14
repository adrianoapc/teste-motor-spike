<?php

declare(strict_types=1);

namespace App\Aplicacao\Suporte;

use RuntimeException;

/**
 * Erro de negócio com código curto (snake_case) e status HTTP.
 * A camada Api o traduz para {"erro","mensagem"} (contrato).
 */
final class ErroDeNegocio extends RuntimeException
{
    public function __construct(
        public readonly string $codigo,
        string $mensagem,
        public readonly int $status,
    ) {
        parent::__construct($mensagem);
    }

    public static function naoEncontrado(string $mensagem): self
    {
        return new self('nao_encontrado', $mensagem, 404);
    }

    public static function conflito(string $codigo, string $mensagem): self
    {
        return new self($codigo, $mensagem, 409);
    }

    public static function invalido(string $codigo, string $mensagem): self
    {
        return new self($codigo, $mensagem, 422);
    }
}
