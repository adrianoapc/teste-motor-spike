<?php

declare(strict_types=1);

namespace Tests\Integracao;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Vetores compartilhados (tests/fixtures/vetores-hash.json) contra a API real, no recorte
 * que continua valendo na v5 (§17): a **forma do corpo** (§6 passo 1: inteiros int64, sem
 * chave duplicada, sem substituto isolado, profundidade ≤ 64, U+0000) é validada ANTES do
 * contrato de tipo de fato (§6.2 passos 2–4). Logo os vetores **rejeitados** por forma dão
 * `422 corpo_invalido` e nada é gravado — independentemente do tipo/fonte, porque o
 * controlador recusa o corpo antes de resolver o contrato.
 *
 * A etapa canônica e o hash v2 (end-to-end, sob o contrato ativo) são cobertos por
 * HashV2Test (unitário, todos os vetores-hash-v2.json) e pelos cenários C19/C23. A antiga
 * asserção de "hash gravado = sha256 do vetor v4" saiu: na v5 o hash é v2 (projetado por
 * campos_hash), então o sha256 da v4 não é mais o que o banco guarda. Ver DUVIDAS.md (M2).
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

    /** @return list<array{nome: string, esperado: string, payload?: string, valor_centavos?: int|null, valor_centavos_bruto?: string, campos_omitidos?: list<string>, campos_texto?: array<string, string>}> */
    private static function vetores(): array
    {
        $json = file_get_contents(__DIR__.'/../fixtures/vetores-hash.json');
        self::assertIsString($json);
        /** @var array{fatos: list<array{nome: string, esperado: string, payload?: string, valor_centavos?: int|null, valor_centavos_bruto?: string, campos_omitidos?: list<string>, campos_texto?: array<string, string>}>} $d */
        $d = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $d['fatos'];
    }

    /**
     * Só os vetores rejeitados: são os que a validação de FORMA do corpo barra, antes do contrato.
     *
     * @return iterable<string, array{int}>
     */
    public static function rejeitados(): iterable
    {
        foreach (self::vetores() as $i => $f) {
            if ($f['esperado'] === 'rejeitado') {
                yield $f['nome'] => [$i];
            }
        }
    }

    /** @param  array{nome: string, esperado: string, payload?: string, valor_centavos?: int|null, valor_centavos_bruto?: string, campos_omitidos?: list<string>, campos_texto?: array<string, string>} $f */
    private static function corpo(array $f, string $titular): string
    {
        if (isset($f['campos_texto'])) {
            $env = ['titular_id' => $titular, 'competencia' => self::COMP, 'tipo' => 'faturamento_mes', 'fonte' => 'intranet', 'observado_em' => '2026-10-01T09:00:00Z'];
            foreach ($f['campos_texto'] as $campo => $texto) {
                $env[$campo] = $texto;
            }

            return json_encode($env + ['payload' => new \stdClass], JSON_THROW_ON_ERROR);
        }

        $c = '{"titular_id":"'.$titular.'","competencia":"'.self::COMP.'","tipo":"faturamento_mes","fonte":"intranet","observado_em":"2026-10-01T09:00:00Z"';
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

    #[DataProvider('rejeitados')]
    public function test_vetor_rejeitado_por_forma_do_corpo(int $i): void
    {
        $f = self::vetores()[$i];
        $titular = 'VET'.$i;
        $corpo = self::corpo($f, $titular);
        $r = $this->call('POST', '/fatos', [], [], [], ['CONTENT_TYPE' => 'application/json'], $corpo);

        // Forma do corpo é o passo 1 do §6.2: recusa antes de resolver o contrato do tipo.
        $r->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');
        $this->assertSame(0, DB::table('fatos.fato')->where('titular_id', $titular)->count());
        $this->assertSame(0, DB::table('fatos.recebimento')->where('titular_id', $titular)->count());

        // Os de payload também valem para cada saída de /concluir (422 antes de qualquer consulta).
        if (isset($f['payload']) && ! isset($f['campos_texto'])) {
            $saida = '{"ator":"x","saidas":[{"tributo":"DAS","valor_centavos":'.($f['valor_centavos_bruto'] ?? '1').',"payload":'.$f['payload'].'}]}';
            $this->call('POST', '/casos/T/'.self::COMP.'/entregaveis/E03/concluir', [], [], [], ['CONTENT_TYPE' => 'application/json'], $saida)
                ->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');
        }
    }
}
