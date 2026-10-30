<?php

declare(strict_types=1);

namespace Tests\Integracao;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Idempotência global de requisição (§6.5, review P2): o índice único
 * recebimento_idempotencia é (fonte, chave_idempotencia) — global, NÃO por titular.
 * Duas publicações válidas com a mesma chave e titulares diferentes têm de serializar
 * na trava `idem|fonte|chave`, senão ambas leriam "sem recebimento anterior" e o 2º
 * INSERT estouraria o índice (500 em vez de devolver o resultado da 1ª vez).
 *
 * A chave é exatamente `'idem|' || fonte || '|' || chave`, a mesma nas três spikes.
 */
final class IdempotenciaConcorrenciaTest extends TestCase
{
    private function chave(string $fonte, string $chave): string
    {
        return 'idem|'.$fonte.'|'.$chave;
    }

    public function test_mesma_chave_serializa_mesmo_entre_titulares_diferentes(): void
    {
        Config::set('database.connections.pgsql_idem_a', Config::get('database.connections.pgsql'));
        Config::set('database.connections.pgsql_idem_b', Config::get('database.connections.pgsql'));
        $a = DB::connection('pgsql_idem_a');
        $b = DB::connection('pgsql_idem_b');

        try {
            $a->statement('SELECT pg_advisory_unlock_all()');
            $b->statement('SELECT pg_advisory_unlock_all()');

            $a->beginTransaction();
            try {
                // A segura a trava da chave (fonte=intranet, chave=K1) — representa o titular T-A.
                $a->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$this->chave('intranet', 'K1')]);

                // B, publicando outro titular T-B com a MESMA chave, não entra (serializa aqui).
                $mesma = (array) $b->selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS ok', [$this->chave('intranet', 'K1')]);
                $this->assertFalse((bool) $mesma['ok'], 'mesma (fonte,chave) deveria serializar entre titulares');

                // Chave diferente (ou fonte diferente) não disputa.
                $b->beginTransaction();
                $outra = (array) $b->selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS ok', [$this->chave('intranet', 'K2')]);
                $this->assertTrue((bool) $outra['ok'], 'chave diferente não deveria bloquear');
                $b->rollBack();
            } finally {
                $a->rollBack();
            }

            // Depois do fim da transação de A, B consegue a chave antes travada.
            $b->beginTransaction();
            $agora = (array) $b->selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS ok', [$this->chave('intranet', 'K1')]);
            $this->assertTrue((bool) $agora['ok'], 'após o fim da transação de A a trava deveria liberar');
            $b->rollBack();
        } finally {
            $a->disconnect();
            $b->disconnect();
            $this->app->make('db')->purge('pgsql_idem_a');
            $this->app->make('db')->purge('pgsql_idem_b');
        }
    }

    public function test_chave_do_lock_idem_e_a_string_concatenada_entre_spikes(): void
    {
        $conexao = DB::connection('pgsql');
        $viaConstante = (array) $conexao->selectOne('SELECT hashtextextended(?, 0) AS h', ['idem|intranet|K1']);
        $viaConcat = (array) $conexao->selectOne("SELECT hashtextextended('idem|' || ? || '|' || ?, 0) AS h", ['intranet', 'K1']);
        $hc = $viaConstante['h'];
        $hk = $viaConcat['h'];

        $this->assertTrue(is_scalar($hc) && is_scalar($hk));
        $this->assertSame((string) $hc, (string) $hk, 'a chave do lock idem tem de ser \'idem|\' || fonte || \'|\' || chave');
    }
}
