<?php

declare(strict_types=1);

namespace Tests\Integracao;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Concorrência por titular (§13, v5): a trava consultiva por titular serializa as
 * transações do mesmo titular e não bloqueia titulares diferentes. A chave é
 * exatamente `'titular|' || titular_id`, a mesma nas três spikes.
 *
 * Prova com duas conexões: enquanto a conexão A segura o `pg_advisory_xact_lock`
 * do titular dentro de uma transação aberta, a conexão B não consegue o mesmo lock
 * (`pg_try_advisory_xact_lock` = false) mas consegue o de outro titular (= true).
 */
final class ConcorrenciaTitularTest extends TestCase
{
    private function chave(string $titularId): string
    {
        return 'titular|'.$titularId;
    }

    public function test_mesmo_titular_serializa_titulares_diferentes_nao_bloqueiam(): void
    {
        // Duas conexões dedicadas ao mesmo banco (MOTOR_DB), sessões independentes — nenhuma é a
        // conexão padrão compartilhada com os outros testes.
        Config::set('database.connections.pgsql_conc_a', Config::get('database.connections.pgsql'));
        Config::set('database.connections.pgsql_conc_b', Config::get('database.connections.pgsql'));
        $a = DB::connection('pgsql_conc_a');
        $b = DB::connection('pgsql_conc_b');

        try {
            $a->statement('SELECT pg_advisory_unlock_all()');
            $b->statement('SELECT pg_advisory_unlock_all()');

            $a->beginTransaction();
            try {
                // A toma o lock do titular T-LOCK dentro da sua transação.
                $a->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$this->chave('T-LOCK')]);

                // B não consegue o mesmo titular (A ainda está na transação).
                $mesmo = (array) $b->selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS ok', [$this->chave('T-LOCK')]);
                $this->assertFalse((bool) $mesmo['ok'], 'mesmo titular deveria bloquear');

                // B consegue um titular diferente (não há disputa).
                $b->beginTransaction();
                $outro = (array) $b->selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS ok', [$this->chave('T-OUTRO')]);
                $this->assertTrue((bool) $outro['ok'], 'titular diferente não deveria bloquear');
                $b->rollBack();
            } finally {
                $a->rollBack();
            }

            // Depois que A solta (fim da transação), B consegue o titular antes travado.
            $b->beginTransaction();
            $agora = (array) $b->selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS ok', [$this->chave('T-LOCK')]);
            $this->assertTrue((bool) $agora['ok'], 'após o commit/rollback de A o lock deveria liberar');
            $b->rollBack();
        } finally {
            // Limpa as duas sessões, aconteça o que acontecer, para não vazar lock nem conexão.
            $a->disconnect();
            $b->disconnect();
            $this->app->make('db')->purge('pgsql_conc_a');
            $this->app->make('db')->purge('pgsql_conc_b');
        }
    }

    public function test_a_chave_do_lock_e_a_string_do_contrato_entre_spikes(): void
    {
        // O hash da chave canônica tem de bater com a string literal 'titular|<id>'
        // (mesma função hashtextextended nas três linguagens).
        $conexao = DB::connection('pgsql');
        $viaConstante = (array) $conexao->selectOne('SELECT hashtextextended(?, 0) AS h', ['titular|T001']);
        $viaConcat = (array) $conexao->selectOne("SELECT hashtextextended('titular|' || ?, 0) AS h", ['T001']);
        $hc = $viaConstante['h'];
        $hk = $viaConcat['h'];

        $this->assertTrue(is_scalar($hc) && is_scalar($hk));
        $this->assertSame((string) $hc, (string) $hk, 'a chave do lock do spike tem de ser idêntica a \'titular|\' || titular_id');
    }
}
