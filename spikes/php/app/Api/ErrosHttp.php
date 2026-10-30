<?php

declare(strict_types=1);

namespace App\Api;

use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Regras\RegraInvalida;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Mapeia exceções para o corpo de erro do contrato: {"erro","mensagem"}.
 * O Laravel, por padrão, responde 422 com {"message","errors"}; aqui trocamos
 * por {"erro","mensagem"} (design: armadilhas do PHP).
 */
final class ErrosHttp
{
    public static function registrar(Exceptions $exceptions): void
    {
        $exceptions->render(function (ValidationException $e): JsonResponse {
            return self::corpo('corpo_invalido', self::primeiraMensagem($e), 422);
        });

        $exceptions->render(function (ErroDeNegocio $e): JsonResponse {
            if ($e->violacoes !== []) {
                return new JsonResponse([
                    'erro' => $e->codigo,
                    'mensagem' => $e->getMessage(),
                    'violacoes' => array_map(static fn ($v) => $v->paraArray(), $e->violacoes),
                ], $e->status);
            }

            return self::corpo($e->codigo, $e->getMessage(), $e->status);
        });

        // Regra malformada é erro de configuração do servidor (500), mas explícito: nada de assumir padrão.
        $exceptions->render(function (RegraInvalida $e): JsonResponse {
            return self::corpo('regra_invalida', $e->getMessage(), 500);
        });

        $exceptions->render(function (NotFoundHttpException $e): JsonResponse {
            // Todas as rotas da API respondem JSON.
            return self::corpo('nao_encontrado', 'recurso não encontrado', 404);
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e): JsonResponse {
            return self::corpo('metodo_nao_permitido', 'método não permitido', 405);
        });

        // Qualquer outra falha (HTTP ou 500) sai em {erro,mensagem}, mesmo sem Accept: application/json.
        $exceptions->render(function (\Throwable $e): JsonResponse {
            if ($e instanceof HttpExceptionInterface) {
                return self::corpo('erro_http', 'requisição não atendida', $e->getStatusCode());
            }

            return self::corpo('erro_interno', 'erro interno', 500);
        });
    }

    public static function corpo(string $erro, string $mensagem, int $status): JsonResponse
    {
        return new JsonResponse(['erro' => $erro, 'mensagem' => $mensagem], $status);
    }

    private static function primeiraMensagem(ValidationException $e): string
    {
        $erros = $e->errors();
        foreach ($erros as $mensagens) {
            if (is_array($mensagens) && isset($mensagens[0]) && is_string($mensagens[0])) {
                return $mensagens[0];
            }
        }

        return 'corpo inválido';
    }
}
