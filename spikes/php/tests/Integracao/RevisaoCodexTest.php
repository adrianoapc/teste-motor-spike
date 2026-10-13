<?php

declare(strict_types=1);

namespace Tests\Integracao;

use App\Aplicacao\Fechamento\Transicionador;
use App\Aplicacao\Portas\RepositorioConferencia;
use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Portas\RepositorioFato;
use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Fechamento\EstadoEntregavel;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regressões dos comentários de revisão do PR #4 (concorrência, evento, classe, corpo).
 * Cada teste roda numa transação revertida no fim.
 */
final class RevisaoCodexTest extends TestCase
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

    /** @return array{id: string, regra: string} */
    private function abrirCaso(string $titular): array
    {
        $this->postJson('/competencias/abrir', [
            'competencia' => self::COMP,
            'ator' => 'teste',
            'empresas' => [[
                'titular_id' => $titular, 'regime' => 'SN', 'tem_folha' => false, 'tem_prolabore' => false,
                'tem_taxa_municipal' => false, 'filial' => false, 'carteira' => 'C-01', 'municipio' => '3549805',
            ]],
        ])->assertOk();
        $linha = (array) DB::selectOne('SELECT id::text AS id, regra_entregaveis_versao_id::text AS regra FROM fechamento.caso_competencia WHERE titular_id = ?', [$titular]);
        $this->assertIsString($linha['id']);
        $this->assertIsString($linha['regra']);

        return ['id' => $linha['id'], 'regra' => $linha['regra']];
    }

    /** @param  array<string, mixed>|null  $payload */
    private function fato(string $titular, string $tipo, ?int $valor, ?array $payload = null): void
    {
        $corpo = ['titular_id' => $titular, 'competencia' => self::COMP, 'tipo' => $tipo, 'fonte' => 'teste', 'observado_em' => '2026-10-01T09:00:00-03:00'];
        if ($valor !== null) {
            $corpo['valor_centavos'] = $valor;
        }
        if ($payload !== null) {
            $corpo['payload'] = $payload;
        }
        $this->postJson('/fatos', $corpo)->assertOk();
    }

    public function test_salvar_estado_com_estado_esperado_velho_devolve_false_e_transicionador_lanca_409(): void
    {
        $caso = $this->abrirCaso('TCX1');
        $repo = $this->app->make(RepositorioEntregavel::class);
        $velho = $repo->porCasoTipo($caso['id'], 'E01');
        $this->assertNotNull($velho);

        $this->assertTrue($repo->salvarEstado($velho->id, $velho->estado, EstadoEntregavel::Invalidado));
        // Segunda gravação com o mesmo estado esperado (leitura velha) não pode sobrescrever.
        $this->assertFalse($repo->salvarEstado($velho->id, $velho->estado, EstadoEntregavel::Validado));
        $atual = $repo->porId($velho->id);
        $this->assertSame(EstadoEntregavel::Invalidado, $atual?->estado);

        try {
            $this->app->make(Transicionador::class)->transicionar($velho, EstadoEntregavel::Validado, 'teste', 'teste', $caso['regra']);
            $this->fail('esperava 409');
        } catch (ErroDeNegocio $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('estado_concorrente', $e->codigo);
        }
    }

    public function test_por_caso_tipo_para_atualizar_le_a_linha(): void
    {
        $caso = $this->abrirCaso('TCX2');
        $e = $this->app->make(RepositorioEntregavel::class)->porCasoTipoParaAtualizar($caso['id'], 'E01');
        $this->assertSame('E01', $e?->tipo);
    }

    public function test_travar_chave_e_reentrante_e_publicar_continua_idempotente(): void
    {
        $fatos = $this->app->make(RepositorioFato::class);
        $fatos->travarChave('TCX3', self::COMP, 'faturamento_mes', '');
        $fatos->travarChave('TCX3', self::COMP, 'faturamento_mes', '');

        $this->fato('TCX3', 'faturamento_mes', 100);
        $this->postJson('/fatos', ['titular_id' => 'TCX3', 'competencia' => self::COMP, 'tipo' => 'faturamento_mes', 'fonte' => 'teste', 'observado_em' => '2026-10-01T09:00:00-03:00', 'valor_centavos' => 100])
            ->assertOk()->assertJsonPath('efeito', 'sem_mudanca');
    }

    public function test_divergencia_grava_conferencia_id_no_evento_e_classe_da_conferencia(): void
    {
        $caso = $this->abrirCaso('TCX4');
        $this->fato('TCX4', 'faturamento_mes', 5000000);
        $this->fato('TCX4', 'notas_oneflow', 4995000);
        $this->fato('TCX4', 'aliquota_mes', null, ['aliquota_bp' => 600]);

        $e01 = $this->app->make(RepositorioEntregavel::class)->porCasoTipo($caso['id'], 'E01');
        $this->assertNotNull($e01);
        $this->assertSame(EstadoEntregavel::Divergente, $e01->estado);

        $div = $this->app->make(RepositorioConferencia::class)->divergentesBloqueantes($e01->id);
        $this->assertCount(1, $div);
        $this->assertSame('CF-01', $div[0]['cf']);

        $evento = (array) DB::selectOne("SELECT payload FROM eventos.evento WHERE caso_id = ? AND nome = 'conferencia.divergente' ORDER BY ocorrido_em DESC LIMIT 1", [$caso['id']]);
        $this->assertIsString($evento['payload']);
        $payload = json_decode($evento['payload'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['conferencia_id' => $div[0]['id']], $payload);

        $exc = (array) DB::selectOne("SELECT classe FROM trabalho.excecao WHERE entregavel_id = ? AND tipo = 'CF-01'", [$e01->id]);
        $this->assertSame($div[0]['classe'], $exc['classe']);
    }

    public function test_divergentes_bloqueantes_devolve_a_classe_gravada_e_nao_a_do_catalogo(): void
    {
        $caso = $this->abrirCaso('TCX5');
        $e01 = $this->app->make(RepositorioEntregavel::class)->porCasoTipo($caso['id'], 'E01');
        $this->assertNotNull($e01);
        $conf = $this->app->make(RepositorioConferencia::class);
        // CF-08 tem classe_padrao O no catálogo; gravamos S (dado de fórmula ausente).
        $conf->gravarSeNova('CF-08', $caso['id'], $e01->id, $caso['regra'], [], 'hash-tcx5', 'divergente', null, null, [], 'B', 'S');

        $cf08 = array_values(array_filter(
            $conf->divergentesBloqueantes($e01->id),
            static fn (array $d): bool => $d['cf'] === 'CF-08',
        ));
        $this->assertCount(1, $cf08);
        $this->assertSame('S', $cf08[0]['classe']);
    }

    public function test_override_com_json_malformado_devolve_422(): void
    {
        $this->call('POST', '/casos/T/'.self::COMP.'/entregaveis/E01/override', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"ator": ')
            ->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');
    }

    public function test_concluir_com_json_malformado_devolve_422(): void
    {
        $this->call('POST', '/casos/T/'.self::COMP.'/entregaveis/E01/concluir', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"ator": ')
            ->assertStatus(422);
    }

    /** @return iterable<string, array{mixed}> */
    public static function valoresNaoInteiros(): iterable
    {
        yield 'string' => ['100'];
        yield 'booleano' => [true];
        yield 'lista' => [[1]];
        yield 'objeto' => [['a' => 1]];
        yield 'float' => [1.5];
    }

    #[DataProvider('valoresNaoInteiros')]
    public function test_concluir_rejeita_valor_centavos_nao_inteiro(mixed $valor): void
    {
        $this->postJson('/casos/T/'.self::COMP.'/entregaveis/E01/concluir', [
            'ator' => 'x',
            'saidas' => [['tributo' => 'DAS', 'valor_centavos' => $valor, 'payload' => new \stdClass]],
        ])->assertStatus(422)->assertJsonPath('erro', 'valor_nao_inteiro');
    }

    public function test_concluir_aceita_valor_centavos_nulo_na_validacao_de_forma(): void
    {
        // Passa da validação de forma; caso inexistente => 404 (não 422).
        $this->postJson('/casos/TNAOEXISTE/'.self::COMP.'/entregaveis/E01/concluir', [
            'ator' => 'x',
            'saidas' => [['tributo' => 'DAS', 'valor_centavos' => null, 'payload' => new \stdClass]],
        ])->assertStatus(404);
    }
}
