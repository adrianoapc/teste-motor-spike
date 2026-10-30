<?php

declare(strict_types=1);

namespace App\Aplicacao\Suporte;

use App\Dominio\Fatos\Violacao;
use RuntimeException;

/**
 * Erro de negócio com código curto (snake_case) e status HTTP.
 * A camada Api o traduz para {"erro","mensagem"} (contrato); com violações (§6.2),
 * acrescenta {"violacoes":[{"campo","regra"}]}.
 */
final class ErroDeNegocio extends RuntimeException
{
    /** @param  list<Violacao>  $violacoes */
    public function __construct(
        public readonly string $codigo,
        string $mensagem,
        public readonly int $status,
        public readonly array $violacoes = [],
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

    /** Tipo de fato sem versão ativa (§6.2 passo 2). */
    public static function tipoDesconhecido(string $tipo): self
    {
        return new self('tipo_desconhecido', "tipo de fato sem contrato ativo: {$tipo}", 422);
    }

    /** Fonte fora de fontes_permitidas (§6.2 passo 3). */
    public static function fonteNaoAutorizada(string $fonte, string $tipo): self
    {
        return new self('fonte_nao_autorizada', "fonte '{$fonte}' não pode publicar o tipo '{$tipo}'", 403);
    }

    /**
     * Payload/qualificador/valor fora do contrato (§6.2 passo 4), com todas as violações.
     *
     * @param  list<Violacao>  $violacoes
     */
    public static function payloadInvalido(array $violacoes): self
    {
        return new self('payload_invalido', 'fato fora do contrato do tipo', 422, $violacoes);
    }
}
