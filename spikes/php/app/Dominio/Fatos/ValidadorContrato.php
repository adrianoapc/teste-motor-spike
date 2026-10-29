<?php

declare(strict_types=1);

namespace App\Dominio\Fatos;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/**
 * Valida uma publicação contra o contrato ativo do tipo (§6.2, passo 4).
 *
 * Ordem do §6.2: o passo 1 (forma do corpo) e os passos 2/3 (tipo ativo e fonte)
 * ficam fora daqui — tipo e fonte dependem da existência do contrato, resolvida no
 * caso de uso. Este serviço cobre o passo 4: qualificador (tributo), valor_centavos
 * e payload contra o JSON Schema, devolvendo TODAS as violações numa lista.
 *
 * O prefixo do caminho (`payload.`, `saidas[i].`) é do chamador: o validador fala
 * em caminhos relativos ao payload e prefixa com o que receber.
 */
final readonly class ValidadorContrato
{
    public function __construct(private string $prefixoPayload = 'payload') {}

    /**
     * @param  mixed  $payload  stdClass|array como veio (objetos preservados)
     * @return list<Violacao> vazio = válido
     */
    public function validar(ContratoTipoFato $contrato, string $tributo, ?int $valorCentavos, mixed $payload): array
    {
        $violacoes = [];

        // Qualificador (coluna tributo).
        if ($contrato->usaTributo()) {
            if ($tributo === '') {
                $violacoes[] = new Violacao('tributo', 'obrigatorio');
            } elseif ($contrato->valoresDeTributo() !== [] && ! in_array($tributo, $contrato->valoresDeTributo(), true)) {
                $violacoes[] = new Violacao('tributo', 'fora_da_lista');
            }
        } elseif ($tributo !== '') {
            $violacoes[] = new Violacao('tributo', 'proibido');
        }

        // valor_centavos.
        if ($contrato->valorCentavos === 'obrigatorio' && $valorCentavos === null) {
            $violacoes[] = new Violacao('valor_centavos', 'obrigatorio');
        } elseif ($contrato->valorCentavos === 'proibido' && $valorCentavos !== null) {
            $violacoes[] = new Violacao('valor_centavos', 'proibido');
        }

        // Payload contra o JSON Schema (draft 2020-12).
        foreach ($this->violacoesDoEsquema($contrato->esquema, $payload) as $v) {
            $violacoes[] = $v;
        }

        return $violacoes;
    }

    /**
     * @return list<Violacao>
     */
    private function violacoesDoEsquema(object $esquema, mixed $payload): array
    {
        $validador = new Validator;
        // O payload chega como stdClass/array; o opis quer o mesmo formato de json_decode.
        $dados = Helper::toJSON($payload);
        $resultado = $validador->validate($dados, $esquema);

        $erro = $resultado->error();
        if ($erro === null) {
            return [];
        }

        $violacoes = $this->folhas($erro);

        // Sem folha reconhecível (erro sem subErros e sem keyword mapeável): registra o erro raiz.
        if ($violacoes === []) {
            $violacoes[] = $this->violacaoDe($erro);
        }

        return $violacoes;
    }

    /**
     * Percorre a árvore de erros do opis coletando as folhas (erros mais profundos),
     * que são os que apontam o campo e a regra reais.
     *
     * @return list<Violacao>
     */
    private function folhas(ValidationError $erro): array
    {
        $subs = $erro->subErrors();
        if ($subs === []) {
            return [$this->violacaoDe($erro)];
        }
        $violacoes = [];
        foreach ($subs as $sub) {
            if (! $sub instanceof ValidationError) {
                continue;
            }
            foreach ($this->folhas($sub) as $v) {
                $violacoes[] = $v;
            }
        }

        return $violacoes;
    }

    private function violacaoDe(ValidationError $erro): Violacao
    {
        $keyword = $erro->keyword();
        $caminhoBase = $this->prefixoPayload;
        $segmentos = array_values(array_map(static fn (int|string $s): string => (string) $s, $erro->data()->fullPath()));

        if ($keyword === 'required') {
            // args['missing'] lista os required ausentes; o caminho aponta o objeto pai.
            $args = $erro->args();
            $faltando = $args['missing'] ?? [];
            if (is_array($faltando) && isset($faltando[0]) && is_string($faltando[0])) {
                $segmentos[] = $faltando[0];
            }

            return new Violacao($this->juntar($caminhoBase, $segmentos), 'obrigatorio');
        }

        $regra = $keyword === 'type' ? 'tipo' : 'esquema';

        return new Violacao($this->juntar($caminhoBase, $segmentos), $regra);
    }

    /**
     * Monta `payload.a.b` ou `payload.parcelas[0].socio`: índices inteiros viram `[i]`,
     * chaves de texto viram `.chave`.
     *
     * @param  list<string>  $segmentos
     */
    private function juntar(string $base, array $segmentos): string
    {
        $caminho = $base;
        foreach ($segmentos as $seg) {
            if (preg_match('/^\d+$/D', $seg) === 1) {
                $caminho .= '['.$seg.']';
            } else {
                $caminho .= '.'.$seg;
            }
        }

        return $caminho;
    }
}
