<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Aplicacao\Portas\RepositorioEvento;
use App\Aplicacao\Portas\RepositorioExcecao;

/**
 * Abertura de exceções (§9.4): abertura única por (alvo, tipo) e o evento `excecao.aberta`.
 * Único lugar que abre exceção; Invalidador, Acompanhar e AvaliarCaso passam por aqui.
 */
final readonly class Excecoes
{
    public function __construct(
        private RepositorioExcecao $excecoes,
        private RepositorioEvento $eventos,
    ) {}

    /**
     * Abre a exceção só se não houver outra aberta do mesmo tipo para o alvo (o entregável, ou o caso
     * quando `$entregavelId` é null) e publica `excecao.aberta`. Devolve o id aberto, ou null se já havia.
     */
    public function abrirUnica(string $casoId, ?string $entregavelId, string $tipo, string $classe, string $dono, ?string $prazo = null, ?string $motivoCodigo = null): ?string
    {
        if ($this->excecoes->existeAberta($casoId, $entregavelId, $tipo)) {
            return null;
        }

        $id = $this->excecoes->abrir($casoId, $entregavelId, $tipo, $classe, $dono, $prazo, $motivoCodigo);
        $this->eventos->gravar('excecao.aberta', $casoId, $entregavelId, ['excecao_id' => $id]);

        return $id;
    }
}
