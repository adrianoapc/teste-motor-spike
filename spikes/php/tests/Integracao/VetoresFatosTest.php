<?php

declare(strict_types=1);

namespace Tests\Integracao;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Os 49 vetores compartilhados (tests/fixtures/vetores-hash.json, cópia do PR #7) contra a API real:
 * aceitos => 200 e hash gravado = sha256 do vetor; rejeitados => 422 corpo_invalido em /fatos
 * (e em /concluir, para os de payload). O corpo usa um envelope válido + payload e valor_centavos brutos.
 */
final class VetoresFatosTest extends TestCase
{
    private const COMP = '202609';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    /** @return list<array{nome: string, esperado: string, payload?: string, valor_centavos?: int|null, valor_centavos_bruto?: string, campos_omitidos?: list<string>, campos_texto?: array<string, string>, canonico?: string, sha256?: string}> */
    private static function vetores(): array
    {
        $json = file_get_contents(__DIR__.'/../fixtures/vetores-hash.json');
        self::assertIsString($json);
        /** @var array{fatos: list<array{nome: string, esperado: string, payload?: string, valor_centavos?: int|null, valor_centavos_bruto?: string, campos_omitidos?: list<string>, campos_texto?: array<string, string>, canonico?: string, sha256?: string}>} $d */
        $d = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $d['fatos'];
    }

    /** @return iterable<string, array{int}> */
    public static function indices(): iterable
    {
        foreach (self::vetores() as $i => $f) {
            yield $f['nome'] => [$i];
        }
    }

    /** @param  array{nome: string, esperado: string, payload?: string, valor_centavos?: int|null, valor_centavos_bruto?: string, campos_omitidos?: list<string>, campos_texto?: array<string, string>} $f */
    private static function corpo(array $f, string $titular): string
    {
        if (isset($f['campos_texto'])) {
            // Envelope válido com os campos de texto sobrescritos (já decodificados, U+0000 real).
            $env = ['titular_id' => $titular, 'competencia' => self::COMP, 'tipo' => 'faturamento_mes', 'fonte' => 'teste', 'observado_em' => '2026-10-01T09:00:00Z'];
            foreach ($f['campos_texto'] as $campo => $texto) {
                $env[$campo] = $texto;
            }

            return json_encode($env + ['payload' => new \stdClass], JSON_THROW_ON_ERROR);
        }

        $c = '{"titular_id":"'.$titular.'","competencia":"'.self::COMP.'","tipo":"faturamento_mes","fonte":"teste","observado_em":"2026-10-01T09:00:00Z"';
        $omitidos = $f['campos_omitidos'] ?? [];
        if (isset($f['payload']) && ! in_array('payload', $omitidos, true)) {
            $c .= ',"payload":'.$f['payload'];
        }
        if (isset($f['valor_centavos_bruto'])) {
            $c .= ',"valor_centavos":'.$f['valor_centavos_bruto'];
        } elseif (! in_array('valor_centavos', $omitidos, true)) {
            $c .= ',"valor_centavos":'.json_encode($f['valor_centavos'] ?? null);
        }

        return $c.'}';
    }

    #[DataProvider('indices')]
    public function test_vetor_na_api(int $i): void
    {
        $f = self::vetores()[$i];
        $titular = 'VET'.$i;
        $corpo = self::corpo($f, $titular);
        $r = $this->call('POST', '/fatos', [], [], [], ['CONTENT_TYPE' => 'application/json'], $corpo);

        if ($f['esperado'] === 'aceito') {
            $r->assertOk();
            $linha = (array) DB::selectOne('SELECT hash FROM fatos.fato WHERE titular_id = ?', [$titular]);
            $this->assertSame($f['sha256'] ?? null, $linha['hash'], $f['nome']);

            return;
        }
        $r->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');
        $this->assertSame(0, DB::table('fatos.fato')->where('titular_id', $titular)->count());

        // Os de payload também valem para cada saída de /concluir (422 antes de qualquer consulta).
        if (isset($f['payload']) && ! isset($f['campos_texto'])) {
            $saida = '{"ator":"x","saidas":[{"tributo":"DAS","valor_centavos":'.($f['valor_centavos_bruto'] ?? '1').',"payload":'.$f['payload'].'}]}';
            $this->call('POST', '/casos/T/'.self::COMP.'/entregaveis/E03/concluir', [], [], [], ['CONTENT_TYPE' => 'application/json'], $saida)
                ->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');
        }
    }
}
