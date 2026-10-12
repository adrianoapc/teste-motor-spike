<?php

declare(strict_types=1);

namespace App\Api;

use App\Aplicacao\Suporte\ErroDeNegocio;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
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
            return self::corpo($e->codigo, $e->getMessage(), $e->status);
        });

        $exceptions->render(function (NotFoundHttpException $e): JsonResponse {
            // Todas as rotas da API respondem JSON.
            return self::corpo('nao_encontrado', 'recurso não encontrado', 404);
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
