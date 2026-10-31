<?php

declare(strict_types=1);

namespace Tests\Integracao;

use App\Aplicacao\Conferencias\Conferidor;
use App\Aplicacao\Conferencias\FatosDoCaso;
use App\Aplicacao\Conferencias\Formulas;
use App\Aplicacao\Conferencias\ParametrosDeConferencia;
use App\Aplicacao\Fechamento\Transicionador;
use App\Aplicacao\Portas\DivergenciaBloqueante;
use App\Aplicacao\Portas\RepositorioCaso;
use App\Aplicacao\Portas\RepositorioConferencia;
use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Portas\RepositorioFato;
use App\Aplicacao\Portas\RepositorioRegras;
use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Conferencias\HashConferencia;
use App\Dominio\Fatos\Fato;
use App\Dominio\Fechamento\Competencia;
use App\Dominio\Fechamento\EstadoEntregavel;
use App\Dominio\Regras\ConferenciaRegra;
use App\Dominio\Regras\EntradaRegra;
use App\Dominio\Regras\ItemEntregavel;
use App\Dominio\Regras\RegraInvalida;
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
    private function fato(string $titular, string $tipo, ?int $valor, ?array $payload = null, string $observadoEm = '2026-10-01T09:00:00-03:00'): void
    {
        $corpo = ['titular_id' => $titular, 'competencia' => self::COMP, 'tipo' => $tipo, 'fonte' => self::fontePara($tipo), 'observado_em' => $observadoEm];
        if ($valor !== null) {
            $corpo['valor_centavos'] = $valor;
        }
        if ($payload !== null) {
            $corpo['payload'] = $payload;
        }
        $this->postJson('/fatos', $corpo)->assertOk();
    }

    /** Fonte permitida pelo contrato ativo (V009) do tipo (§6.2 passo 3). */
    private static function fontePara(string $tipo): string
    {
        return match ($tipo) {
            'notas_oneflow' => 'oneflow',
            'aliquota_mes' => 'calculo',
            'apurado' => 'tarefa',
            'guia' => 'porta_documental',
            'folha' => 'dp',
            'divisao_socios' => 'calculo',
            'prolabore' => 'tarefa',
            'recibo_obrigacao' => 'integra',
            'documento_disponibilizado', 'entrega_confirmada', 'entrega_falhou' => 'entrega',
            'recebimento_pendente' => 'agendador',
            default => 'intranet', // faturamento_mes, taxa_municipal
        };
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
        $this->postJson('/fatos', ['titular_id' => 'TCX3', 'competencia' => self::COMP, 'tipo' => 'faturamento_mes', 'fonte' => 'intranet', 'observado_em' => '2026-10-01T09:00:00-03:00', 'valor_centavos' => 100])
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
        $this->assertSame('CF-01', $div[0]->cf);

        $evento = (array) DB::selectOne("SELECT payload FROM eventos.evento WHERE caso_id = ? AND nome = 'conferencia.divergente' ORDER BY ocorrido_em DESC LIMIT 1", [$caso['id']]);
        $this->assertIsString($evento['payload']);
        $payload = json_decode($evento['payload'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['conferencia_id' => $div[0]->id], $payload);

        $exc = (array) DB::selectOne("SELECT classe FROM trabalho.excecao WHERE entregavel_id = ? AND tipo = 'CF-01'", [$e01->id]);
        $this->assertSame($div[0]->classe, $exc['classe']);
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
            static fn (DivergenciaBloqueante $d): bool => $d->cf === 'CF-08',
        ));
        $this->assertCount(1, $cf08);
        $this->assertSame('S', $cf08[0]->classe);
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
        ])->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');
    }

    public function test_concluir_aceita_valor_centavos_nulo_na_validacao_de_forma(): void
    {
        // Passa da validação de forma; caso inexistente => 404 (não 422).
        $this->postJson('/casos/TNAOEXISTE/'.self::COMP.'/entregaveis/E01/concluir', [
            'ator' => 'x',
            'saidas' => [['tributo' => 'DAS', 'valor_centavos' => null, 'payload' => new \stdClass]],
        ])->assertStatus(404);
    }

    public function test_fato_rejeita_valor_centavos_string_numerica_com_422(): void
    {
        $this->postJson('/fatos', [
            'titular_id' => 'TCX6', 'competencia' => self::COMP, 'tipo' => 'faturamento_mes', 'fonte' => 'intranet',
            'observado_em' => '2026-10-01T09:00:00-03:00', 'valor_centavos' => '100',
        ])->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');
        $this->assertSame(0, DB::table('fatos.fato')->where('titular_id', 'TCX6')->count());
    }

    /** @return iterable<string, array{string}> */
    public static function datasInvalidas(): iterable
    {
        yield 'texto' => ['not-a-date'];
        yield 'so data' => ['2026-10-01'];
        yield 'mes 13' => ['2026-13-01T09:00:00-03:00'];
        yield 'dia inexistente' => ['2026-02-31T09:00:00-03:00'];
        yield 'hora 25' => ['2026-10-01T25:00:00-03:00'];
        yield 'sem fuso' => ['2026-10-01T09:00:00'];
    }

    #[DataProvider('datasInvalidas')]
    public function test_fato_rejeita_observado_em_invalido_com_422(string $data): void
    {
        $this->postJson('/fatos', [
            'titular_id' => 'TCX7', 'competencia' => self::COMP, 'tipo' => 'faturamento_mes', 'fonte' => 'intranet',
            'observado_em' => $data, 'valor_centavos' => 100,
        ])->assertStatus(422);
        $this->assertSame(0, DB::table('fatos.fato')->where('titular_id', 'TCX7')->count());
    }

    public function test_fato_aceita_observado_em_rfc3339_com_fracao_e_z(): void
    {
        foreach (['2026-10-01T09:00:00Z', '2026-10-01T09:00:00.123Z', '2026-10-01T09:00:00.123456-03:00'] as $data) {
            // faturamento_mes tem qualificador usa:false (sem tributo); as três publicações da
            // mesma chave e mesmo valor dão novo, depois sem_mudanca — todas 200.
            $this->postJson('/fatos', [
                'titular_id' => 'TCX8', 'competencia' => self::COMP, 'tipo' => 'faturamento_mes',
                'fonte' => 'intranet', 'observado_em' => $data, 'valor_centavos' => 100,
            ])->assertOk();
        }
    }

    public function test_abrir_rejeita_booleano_nao_nativo_com_422(): void
    {
        $this->postJson('/competencias/abrir', [
            'competencia' => self::COMP, 'ator' => 'teste',
            'empresas' => [[
                'titular_id' => 'TCX9', 'regime' => 'SN', 'tem_folha' => '1', 'tem_prolabore' => false,
                'tem_taxa_municipal' => false, 'filial' => false, 'carteira' => 'C-01', 'municipio' => '3549805',
            ]],
        ])->assertStatus(422);
    }

    // NOTA (M2/v5): o teste "CF-01 classe S com faturamento de valor nulo" foi removido.
    // A classe S da CF-01 exigia um faturamento_mes PRESENTE com valor_centavos nulo; na v5 o
    // contrato de faturamento_mes torna valor_centavos obrigatório, então esse fato é recusado na
    // entrada (payload_invalido) e nunca chega a gravar. Sem o fato presente, o E01 não atinge
    // `processado` e a CF-01 não roda — logo o caminho de classe S por "operando nulo" deixou de
    // existir para a CF-01. A classe S por fato ausente continua coberta pela CF-12 (teste abaixo).
    // Ver spikes/php/DUVIDAS.md (M2).

    public function test_nova_versao_do_fato_usado_muda_o_entrada_hash_da_conferencia(): void
    {
        $caso = $this->abrirCaso('TCX11');
        // v5: faturamento_mes exige valor_centavos. Divergência B (faturamento ≠ notas) gera a CF-01;
        // uma nova versão do faturamento muda o entrada_hash => nova conferência (não reaproveita).
        $this->fato('TCX11', 'faturamento_mes', 5000000);
        $this->fato('TCX11', 'notas_oneflow', 4000000);
        $this->fato('TCX11', 'aliquota_mes', null, ['aliquota_bp' => 600]);
        $e01 = $this->app->make(RepositorioEntregavel::class)->porCasoTipo($caso['id'], 'E01');
        $this->assertNotNull($e01);

        $antes = DB::table('conferencias.conferencia')->where('entregavel_id', $e01->id)->where('cf', 'CF-01')->count();
        // Nova versão do faturamento (observado_em MAIS TARDE, senão seria conflito_fonte na v5):
        // o hash de entrada muda => nova conferência.
        $this->fato('TCX11', 'faturamento_mes', 4500000, null, '2026-10-01T10:00:00-03:00');
        $depois = DB::table('conferencias.conferencia')->where('entregavel_id', $e01->id)->where('cf', 'CF-01')->count();
        $this->assertGreaterThan($antes, $depois);

        $hashes = DB::table('conferencias.conferencia')->where('entregavel_id', $e01->id)->where('cf', 'CF-01')->pluck('entrada_hash')->map(static fn (mixed $h): string => is_string($h) ? $h : '')->all();
        $this->assertSame(count($hashes), count(array_unique($hashes)));
    }

    /**
     * §6.4 (v5.1): fração com 7+ dígitos. O motor TRUNCA para 6 dígitos (.1234567 -> .123456)
     * antes de comparar e de gravar, e grava já truncado; o Postgres recebe sempre 6 dígitos.
     * Uma republicação com o MESMO instante, a MESMA fonte e valor diferente tem de dar
     * conflito_fonte, não obsoleto por diferença de precisão.
     */
    public function test_republicacao_no_mesmo_instante_com_fracao_de_7_digitos_e_conflito_fonte(): void
    {
        $em = '2026-10-01T09:00:00.1234567-03:00';
        $this->postJson('/fatos', [
            'titular_id' => 'TPREC', 'competencia' => self::COMP, 'tipo' => 'faturamento_mes',
            'fonte' => 'intranet', 'observado_em' => $em, 'valor_centavos' => 100,
        ])->assertOk()->assertJsonPath('efeito', 'novo');

        // Mesmo instante (idêntico), mesma fonte, valor diferente => hash muda => conflito_fonte (§6.4).
        $this->postJson('/fatos', [
            'titular_id' => 'TPREC', 'competencia' => self::COMP, 'tipo' => 'faturamento_mes',
            'fonte' => 'intranet', 'observado_em' => $em, 'valor_centavos' => 200,
        ])->assertOk()->assertJsonPath('efeito', 'conflito_fonte');

        // Só a versão 1 permanece vigente (conflito_fonte não grava novo fato).
        $this->assertSame(1, DB::table('fatos.fato')->where('titular_id', 'TPREC')->where('tipo', 'faturamento_mes')->count());
    }

    /**
     * §6.4 (v5.1): o valor gravado é TRUNCADO, não arredondado. '.9999996' arredondaria para o
     * segundo seguinte (.000000 do segundo +1); truncado fica '.999999'. Como o motor grava já
     * truncado, o timestamptz guarda '.999999' e uma republicação de mesmo instante, mesma fonte
     * e valor diferente dá conflito_fonte (prova que não houve carry por arredondamento).
     */
    public function test_valor_gravado_e_truncado_nao_arredondado(): void
    {
        $em = '2026-10-01T09:00:00.9999996-03:00';
        $this->postJson('/fatos', [
            'titular_id' => 'TTRUNC', 'competencia' => self::COMP, 'tipo' => 'faturamento_mes',
            'fonte' => 'intranet', 'observado_em' => $em, 'valor_centavos' => 100,
        ])->assertOk()->assertJsonPath('efeito', 'novo');

        // O banco guardou .999999 (truncado). Republicar no mesmo instante com valor diferente
        // => hash muda, mesmo instante, mesma fonte => conflito_fonte.
        $this->postJson('/fatos', [
            'titular_id' => 'TTRUNC', 'competencia' => self::COMP, 'tipo' => 'faturamento_mes',
            'fonte' => 'intranet', 'observado_em' => $em, 'valor_centavos' => 200,
        ])->assertOk()->assertJsonPath('efeito', 'conflito_fonte');

        // observado_em gravado tem microssegundo .999999 (truncado), nunca .000000 do segundo seguinte.
        $gravado = DB::table('fatos.fato')->where('titular_id', 'TTRUNC')->where('tipo', 'faturamento_mes')->value('observado_em');
        $this->assertIsString($gravado);
        $this->assertStringContainsString('.999999', $gravado);
    }

    public function test_abrir_competencias_trava_a_chave_e_reporta_existente_na_segunda_chamada(): void
    {
        $this->abrirCaso('TCX12');
        $caso = $this->app->make(RepositorioCaso::class);
        $comp = new Competencia(self::COMP);
        $caso->travarTitular('TCX12');
        $caso->travarTitular('TCX12'); // reentrante

        $r = $this->postJson('/competencias/abrir', [
            'competencia' => self::COMP, 'ator' => 'teste',
            'empresas' => [[
                'titular_id' => 'TCX12', 'regime' => 'SN', 'tem_folha' => false, 'tem_prolabore' => false,
                'tem_taxa_municipal' => false, 'filial' => false, 'carteira' => 'C-01', 'municipio' => '3549805',
            ]],
        ])->assertOk();
        $r->assertJsonCount(0, 'criados')->assertJsonCount(1, 'existentes');
    }

    /** @return iterable<string, array{string}> */
    public static function payloadsNaoObjeto(): iterable
    {
        yield 'nulo' => ['null'];
        yield 'lista vazia' => ['[]'];
        yield 'lista' => ['[1]'];
        yield 'escalar' => ['5'];
        yield 'texto' => ['"x"'];
    }

    #[DataProvider('payloadsNaoObjeto')]
    public function test_fato_rejeita_payload_que_nao_e_objeto_com_422(string $payloadJson): void
    {
        $corpo = '{"titular_id":"TCX13","competencia":"'.self::COMP.'","tipo":"faturamento_mes","fonte":"intranet","observado_em":"2026-10-01T09:00:00-03:00","payload":'.$payloadJson.'}';
        $this->call('POST', '/fatos', [], [], [], ['CONTENT_TYPE' => 'application/json'], $corpo)
            ->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');
        $this->assertSame(0, DB::table('fatos.fato')->where('titular_id', 'TCX13')->count());
    }

    #[DataProvider('payloadsNaoObjeto')]
    public function test_concluir_rejeita_payload_de_saida_que_nao_e_objeto_com_422(string $payloadJson): void
    {
        $corpo = '{"ator":"x","saidas":[{"tributo":"DAS","valor_centavos":1,"payload":'.$payloadJson.'}]}';
        $this->call('POST', '/casos/T/'.self::COMP.'/entregaveis/E01/concluir', [], [], [], ['CONTENT_TYPE' => 'application/json'], $corpo)
            ->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');
    }

    public function test_fato_sem_payload_assume_objeto_vazio(): void
    {
        $this->fato('TCX14', 'faturamento_mes', 100);
        $linha = (array) DB::selectOne("SELECT payload FROM fatos.fato WHERE titular_id = 'TCX14'");
        $this->assertSame('{}', $linha['payload']);
    }

    /** @return iterable<string, array{string}> */
    public static function tributosNaoTexto(): iterable
    {
        yield 'numero' => ['123'];
        yield 'falso' => ['false'];
        yield 'nulo' => ['null'];
        yield 'lista' => ['[]'];
        yield 'objeto' => ['{}'];
    }

    #[DataProvider('tributosNaoTexto')]
    public function test_concluir_rejeita_tributo_de_saida_que_nao_e_texto_com_422(string $tributoJson): void
    {
        $corpo = '{"ator":"x","saidas":[{"tributo":'.$tributoJson.',"valor_centavos":1,"payload":{}}]}';
        $this->call('POST', '/casos/T/'.self::COMP.'/entregaveis/E01/concluir', [], [], [], ['CONTENT_TYPE' => 'application/json'], $corpo)
            ->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');
    }

    /**
     * Prova real de exclusão mútua entre duas conexões independentes: uma segunda conexão PDO
     * (fora da transação do teste) não consegue o lock do titular enquanto a primeira o segura.
     */
    public function test_lock_do_titular_exclui_outra_conexao(): void
    {
        $this->app->make(RepositorioCaso::class)->travarTitular('TLOCK');
        $chave = 'titular|TLOCK';

        $c = DB::connection();
        $pdo2 = new \PDO(
            'pgsql:host='.self::txt($c->getConfig('host')).';port='.self::txt($c->getConfig('port')).';dbname='.self::txt($c->getConfig('database')),
            self::txt($c->getConfig('username')),
            self::txt($c->getConfig('password')),
        );
        $st = $pdo2->prepare('SELECT pg_try_advisory_lock(hashtextextended(?, 0))');
        $st->execute([$chave]);
        $this->assertFalse((bool) $st->fetchColumn(), 'segunda conexão não deveria obter o lock do titular');
        $st = $pdo2->prepare('SELECT pg_try_advisory_lock(hashtextextended(?, 0))');
        $st->execute(['titular|OUTRO']);
        $this->assertTrue((bool) $st->fetchColumn(), 'outro titular não é bloqueado');
    }

    private static function txt(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    public function test_fato_rejeita_propriedade_nao_declarada_com_422(): void
    {
        $this->postJson('/fatos', [
            'titular_id' => 'TCX20', 'competencia' => self::COMP, 'tipo' => 'faturamento_mes', 'fonte' => 'intranet',
            'observado_em' => '2026-10-01T09:00:00Z', 'valor_centavo' => 100,
        ])->assertStatus(422);
        $this->assertSame(0, DB::table('fatos.fato')->where('titular_id', 'TCX20')->count());
    }

    public function test_concluir_rejeita_propriedade_nao_declarada_no_corpo_e_nas_saidas(): void
    {
        $url = '/casos/TNAOEXISTE/'.self::COMP.'/entregaveis/E01/concluir';
        $this->postJson($url, ['ator' => 'x', 'extra' => 1, 'saidas' => [['tributo' => 'DAS', 'payload' => new \stdClass]]])->assertStatus(422);
        $this->postJson($url, ['ator' => 'x', 'saidas' => [['tributo' => 'DAS', 'valor_centavo' => 1, 'payload' => new \stdClass]]])->assertStatus(422);
    }

    public function test_override_rejeita_propriedade_nao_declarada(): void
    {
        $this->postJson('/casos/TNAOEXISTE/'.self::COMP.'/entregaveis/E01/override', ['ator' => 'x', 'cf' => 'CF-01', 'motivo' => 'motivo valido', 'extra' => true])
            ->assertStatus(422);
    }

    public function test_abrir_rejeita_propriedade_nao_declarada_no_corpo_e_na_empresa(): void
    {
        $empresa = ['titular_id' => 'TCX21', 'regime' => 'SN', 'tem_folha' => false, 'tem_prolabore' => false, 'tem_taxa_municipal' => false, 'filial' => false, 'carteira' => 'C-01', 'municipio' => '3549805'];
        $this->postJson('/competencias/abrir', ['competencia' => self::COMP, 'ator' => 'x', 'empresas' => [$empresa], 'extra' => 1])->assertStatus(422);
        $this->postJson('/competencias/abrir', ['competencia' => self::COMP, 'ator' => 'x', 'empresas' => [$empresa + ['cnpj' => '1']]])->assertStatus(422);
        $this->assertSame(0, DB::table('fechamento.caso_competencia')->where('titular_id', 'TCX21')->count());
    }

    public function test_abrir_rejeita_mais_de_10000_empresas_com_422(): void
    {
        $empresas = array_fill(0, 10001, ['titular_id' => 'X', 'regime' => 'SN', 'tem_folha' => false, 'tem_prolabore' => false, 'tem_taxa_municipal' => false, 'filial' => false, 'carteira' => 'C-01', 'municipio' => '3549805']);
        $this->postJson('/competencias/abrir', ['competencia' => self::COMP, 'ator' => 'x', 'empresas' => $empresas])->assertStatus(422);
    }

    public function test_parametros_de_rota_invalidos_dao_422_antes_de_404(): void
    {
        $this->getJson('/casos/'.str_repeat('a', 65).'/'.self::COMP)->assertStatus(422);
        $this->getJson('/casos/T/202613')->assertStatus(422);
        $this->getJson('/casos/T/'.self::COMP)->assertStatus(404);
        $corpo = ['ator' => 'x', 'saidas' => [['tributo' => 'DAS', 'payload' => new \stdClass]]];
        $this->postJson('/casos/T/'.self::COMP.'/entregaveis/E09/concluir', $corpo)->assertStatus(422);
        $this->postJson('/casos/T/20261/entregaveis/E01/concluir', $corpo)->assertStatus(422);
        $this->postJson('/casos/T/'.self::COMP.'/entregaveis/E09/override', ['ator' => 'x', 'cf' => 'CF-01', 'motivo' => 'motivo valido'])->assertStatus(422);
    }

    public function test_logs_de_container_vao_para_stdout(): void
    {
        $this->assertSame('stdout', config('logging.default'));
        $this->assertSame('php://stdout', config('logging.channels.stdout.handler_with.stream'));
        $caddy = file_get_contents(base_path('Caddyfile'));
        $this->assertIsString($caddy);
        $this->assertStringContainsString('output stdout', $caddy);
        $this->assertStringNotContainsString('stderr', $caddy);
    }

    public function test_abrir_rejeita_empresas_como_objeto_json(): void
    {
        $corpo = '{"competencia":"'.self::COMP.'","ator":"x","empresas":{"a":{"titular_id":"TCX30","regime":"SN","tem_folha":false,"tem_prolabore":false,"tem_taxa_municipal":false,"filial":false,"carteira":"C-01","municipio":"3549805"}}}';
        $this->call('POST', '/competencias/abrir', [], [], [], ['CONTENT_TYPE' => 'application/json'], $corpo)->assertStatus(422);
        $this->assertSame(0, DB::table('fechamento.caso_competencia')->where('titular_id', 'TCX30')->count());
    }

    public function test_competencia_com_quebra_de_linha_final_da_422(): void
    {
        $this->postJson('/fatos', ['titular_id' => 'TCX31', 'competencia' => self::COMP."\n", 'tipo' => 'faturamento_mes', 'fonte' => 'intranet', 'observado_em' => '2026-10-01T09:00:00Z'])->assertStatus(422);
        $this->postJson('/fatos', ['titular_id' => 'TCX31', 'competencia' => self::COMP, 'tipo' => 'faturamento_mes', 'fonte' => 'intranet', 'observado_em' => "2026-10-01T09:00:00Z\n"])->assertStatus(422);
        $this->postJson('/competencias/abrir', ['competencia' => self::COMP."\n", 'ator' => 'x', 'empresas' => []])->assertStatus(422);
        $this->getJson('/casos/T/'.self::COMP.'%0A')->assertStatus(422);
        $this->expectException(\InvalidArgumentException::class);
        new Competencia(self::COMP."\n");
    }

    public function test_conferencia_usa_relogio_real_e_vigente_e_a_ultima_inserida(): void
    {
        $caso = $this->abrirCaso('TCX32');
        $e01 = $this->app->make(RepositorioEntregavel::class)->porCasoTipo($caso['id'], 'E01');
        $this->assertNotNull($e01);
        $conf = $this->app->make(RepositorioConferencia::class);
        // Mesma transação (now() constante): a segunda gravação tem de ter em maior que a primeira.
        $conf->gravarSeNova('CF-01', $caso['id'], $e01->id, $caso['regra'], [], 'h-a', 'divergente', 1, 2, ['centavos' => 1], 'B', 'O');
        $conf->gravarSeNova('CF-01', $caso['id'], $e01->id, $caso['regra'], [], 'h-b', 'ok', 1, 1, ['centavos' => 0], 'B', 'O');
        $vigente = $conf->vigente($e01->id, 'CF-01');
        $this->assertSame('ok', $vigente?->resultado);
        $ems = DB::table('conferencias.conferencia')->where('entregavel_id', $e01->id)->where('cf', 'CF-01')->whereIn('entrada_hash', ['h-a', 'h-b'])->orderBy('em')->pluck('entrada_hash')->all();
        $this->assertSame(['h-a', 'h-b'], $ems);
    }

    public function test_diferenca_acima_de_int64_e_divergencia_numerica_com_classe_do_catalogo(): void
    {
        $caso = $this->abrirCaso('TCX33');
        $this->fato('TCX33', 'faturamento_mes', PHP_INT_MAX);
        $this->fato('TCX33', 'notas_oneflow', -1);
        $this->fato('TCX33', 'aliquota_mes', null, ['aliquota_bp' => 600]);
        $e01 = $this->app->make(RepositorioEntregavel::class)->porCasoTipo($caso['id'], 'E01');
        $this->assertNotNull($e01);
        $linha = (array) DB::selectOne("SELECT resultado, classe, diferenca::text AS dif FROM conferencias.conferencia WHERE entregavel_id = ? AND cf = 'CF-01'", [$e01->id]);
        $this->assertSame('divergente', $linha['resultado']);
        $cat = (array) DB::selectOne("SELECT classe_padrao FROM conferencias.conferencia_tipo WHERE chave = 'CF-01'");
        $this->assertSame($cat['classe_padrao'], $linha['classe']);
        $this->assertSame('{"centavos": 9223372036854775808}', $linha['dif']);
        $this->assertSame(EstadoEntregavel::Divergente, $e01->estado);
    }

    public function test_fato_com_payload_de_inteiros_int64_e_aceito_e_hash_confere(): void
    {
        $corpo = '{"titular_id":"TCX42","competencia":"'.self::COMP.'","tipo":"faturamento_mes","fonte":"intranet","observado_em":"2026-10-01T09:00:00Z","valor_centavos":9223372036854775807,"payload":{"n":9223372036854775807}}';
        $this->call('POST', '/fatos', [], [], [], ['CONTENT_TYPE' => 'application/json'], $corpo)->assertOk();
        $linha = (array) DB::selectOne("SELECT hash FROM fatos.fato WHERE titular_id = 'TCX42'");
        // v5 (§6.3): faturamento_mes tem campos_hash vazio, então o payload projeta para {} e o
        // hash depende só do valor_centavos: sha256('{"payload":{},"valor_centavos":9223372036854775807}').
        $this->assertSame('f003a126a3bf32adc23f4fcf69814c7336b86c900d7cfe0ca1d6e8b10f234db3', $linha['hash']);
    }

    public function test_valor_centavos_alem_de_int64_da_422(): void
    {
        $corpo = '{"titular_id":"TCX41","competencia":"'.self::COMP.'","tipo":"faturamento_mes","fonte":"intranet","observado_em":"2026-10-01T09:00:00Z","valor_centavos":9223372036854775808}';
        $this->call('POST', '/fatos', [], [], [], ['CONTENT_TYPE' => 'application/json'], $corpo)->assertStatus(422);
    }

    public function test_tributo_vazio_e_aceito_e_textos_valem_como_enviados(): void
    {
        $base = ['competencia' => self::COMP, 'tipo' => 'faturamento_mes', 'fonte' => 'intranet', 'observado_em' => '2026-10-01T09:00:00Z', 'valor_centavos' => 1];
        $this->postJson('/fatos', $base + ['titular_id' => 'TS1 ', 'tributo' => ''])->assertOk();
        $this->postJson('/fatos', $base + ['titular_id' => '   '])->assertOk();
        $this->assertSame(1, DB::table('fatos.fato')->where('titular_id', 'TS1 ')->where('tributo', '')->count());
        $this->assertSame(1, DB::table('fatos.fato')->where('titular_id', '   ')->count());

        // /competencias/abrir usa o MESMO texto (sem trim): 'TS2 ' e '  ' são titulares válidos e distintos.
        $empresa = ['regime' => 'SN', 'tem_folha' => false, 'tem_prolabore' => false, 'tem_taxa_municipal' => false, 'filial' => false, 'carteira' => 'C-01', 'municipio' => '3549805'];
        $this->postJson('/competencias/abrir', ['competencia' => self::COMP, 'ator' => 'x', 'empresas' => [['titular_id' => 'TS2 '] + $empresa, ['titular_id' => '  '] + $empresa]])->assertOk()->assertJsonCount(2, 'criados');
        $this->assertSame(1, DB::table('fechamento.caso_competencia')->where('titular_id', 'TS2 ')->count());
        $this->assertSame(1, DB::table('fechamento.caso_competencia')->where('titular_id', '  ')->count());
    }

    /** @return iterable<string, array{string, int}> */
    public static function fusos(): iterable
    {
        yield '+15:59 aceito' => ['2026-10-01T09:00:00+15:59', 200];
        yield '-15:59 aceito' => ['2026-10-01T09:00:00-15:59', 200];
        yield '+16:00 recusado' => ['2026-10-01T09:00:00+16:00', 422];
        yield '+23:00 recusado' => ['2026-10-01T09:00:00+23:00', 422];
        yield '+10:60 recusado' => ['2026-10-01T09:00:00+10:60', 422];
    }

    #[DataProvider('fusos')]
    public function test_observado_em_com_fuso_fora_do_intervalo_do_postgres_da_422_e_nao_500(string $data, int $status): void
    {
        $this->postJson('/fatos', ['titular_id' => 'TFUSO', 'competencia' => self::COMP, 'tipo' => 'faturamento_mes', 'fonte' => 'intranet', 'observado_em' => $data, 'valor_centavos' => 100])->assertStatus($status);
    }

    public function test_erros_500_e_405_saem_em_json_sem_accept(): void
    {
        $r = $this->call('PUT', '/fatos');
        $this->assertSame(405, $r->getStatusCode());
        $this->assertStringContainsString('"erro":"metodo_nao_permitido"', (string) $r->getContent());

        $this->app->make('router')->get('/__falha', static function (): never {
            throw new \RuntimeException('boom');
        });
        $r = $this->call('GET', '/__falha');
        $this->assertSame(500, $r->getStatusCode());
        $this->assertSame('{"erro":"erro_interno","mensagem":"erro interno"}', (string) $r->getContent());
    }

    public function test_app_key_do_dockerfile_tem_32_bytes(): void
    {
        $docker = file_get_contents(base_path('Dockerfile'));
        $this->assertIsString($docker);
        $this->assertSame(1, preg_match('/APP_KEY=base64:([A-Za-z0-9+\/=]+)/', $docker, $m));
        $this->assertSame(32, strlen((string) base64_decode($m[1] ?? '', true)));
    }

    public function test_concluir_saidas_vazio_ou_item_invalido_da_422_antes_de_404(): void
    {
        $url = '/casos/TNAOEXISTE/'.self::COMP.'/entregaveis/E03/concluir';
        $this->postJson($url, ['ator' => 'x', 'saidas' => []])->assertStatus(422);
        $corpo = '{"ator":"x","saidas":[{"tributo":"DAS","valor_centavos":1,"payload":{}},{"tributo":"X","valor_centavos":1.5,"payload":{}}]}';
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], $corpo)->assertStatus(422);
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"ator":"x","saidas":[{"payload":{"a":1,"a":2}}]}')->assertStatus(422);
    }

    public function test_u0000_em_campos_de_texto_e_parametros_de_caminho_da_422_corpo_invalido(): void
    {
        $nul = "\u{0}";
        $json = static fn (array $a): string => json_encode($a, JSON_THROW_ON_ERROR);
        $post = fn (string $url, string $corpo) => $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], $corpo);
        $empresa = ['titular_id' => 'TNUL', 'regime' => 'SN', 'tem_folha' => false, 'tem_prolabore' => false, 'tem_taxa_municipal' => false, 'filial' => false, 'carteira' => 'C-01', 'municipio' => '3549805'];

        // /concluir: ator e tributo da saída.
        $url = '/casos/T/'.self::COMP.'/entregaveis/E03/concluir';
        $post($url, $json(['ator' => "a{$nul}b", 'saidas' => [['tributo' => 'DAS', 'payload' => new \stdClass]]]))->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');
        $post($url, $json(['ator' => 'x', 'saidas' => [['tributo' => "D{$nul}", 'payload' => new \stdClass]]]))->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');

        // /competencias/abrir: ator, titular_id, carteira, municipio.
        $abrir = fn (array $corpo) => $post('/competencias/abrir', $json($corpo));
        $abrir(['competencia' => self::COMP, 'ator' => "x{$nul}", 'empresas' => [$empresa]])->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');
        foreach (['titular_id', 'carteira', 'municipio'] as $campo) {
            $abrir(['competencia' => self::COMP, 'ator' => 'x', 'empresas' => [[$campo => "v{$nul}"] + $empresa]])->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');
        }
        $this->assertSame(0, DB::table('fechamento.caso_competencia')->where('titular_id', 'like', 'v%')->count());

        // /override: ator e motivo.
        $ov = '/casos/T/'.self::COMP.'/entregaveis/E01/override';
        $post($ov, $json(['ator' => "a{$nul}", 'cf' => 'CF-01', 'motivo' => 'motivo valido']))->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');
        $post($ov, $json(['ator' => 'x', 'cf' => 'CF-01', 'motivo' => "motivo{$nul}valido"]))->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');

        // Parâmetros de caminho (%00).
        $this->getJson('/casos/T%00/'.self::COMP)->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');
        $post('/casos/T%00/'.self::COMP.'/entregaveis/E01/concluir', $json(['ator' => 'x', 'saidas' => [['payload' => new \stdClass]]]))->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');
        $post('/casos/T%00/'.self::COMP.'/entregaveis/E01/override', $json(['ator' => 'x', 'cf' => 'CF-01', 'motivo' => 'motivo valido']))->assertStatus(422)->assertJsonPath('erro', 'corpo_invalido');

        // U+0001..U+001F continuam permitidos.
        $this->postJson('/fatos', ['titular_id' => "T\u{1}X", 'competencia' => self::COMP, 'tipo' => 'faturamento_mes', 'fonte' => 'intranet', 'observado_em' => '2026-10-01T09:00:00Z', 'valor_centavos' => 100])->assertOk();
    }

    public function test_cf12_ok_grava_esperado_e_obtido_nulos_e_diferenca_zero(): void
    {
        $caso = $this->abrirCaso('TCX50');
        $e01 = $this->app->make(RepositorioEntregavel::class)->porCasoTipo($caso['id'], 'E01');
        $this->assertNotNull($e01);
        $this->postJson('/fatos', [
            'titular_id' => 'TCX50', 'competencia' => self::COMP, 'tipo' => 'recibo_obrigacao', 'tributo' => 'PGDAS-D',
            'fonte' => 'integra', 'observado_em' => '2026-10-01T09:00:00-03:00', 'payload' => ['numero_recibo' => 'R-1'],
        ])->assertOk();
        $fato = (array) DB::selectOne("SELECT id::text AS id, versao FROM fatos.fato WHERE titular_id = 'TCX50' AND tipo = 'recibo_obrigacao'");
        $this->assertIsString($fato['id']);

        $formulas = new Formulas(5000);
        $fatosCaso = new FatosDoCaso([
            new Fato($fato['id'], 'TCX50', self::COMP, 'recibo_obrigacao', 'PGDAS-D', 1, null, new \stdClass, 'h'),
        ]);
        $r = $formulas->cf12($fatosCaso, 'PGDAS-D');
        // Formula: sem valores em centavos, diferença 0, fatos_usados com o recibo (entrada_hash segue dependendo só deles).
        $this->assertNull($r->esperadoCentavos);
        $this->assertNull($r->obtidoCentavos);
        $this->assertTrue($r->comparacaoTexto && $r->textoIgual);
        $this->assertSame(['centavos' => 0], $r->diferencaExtra);
        $this->assertSame([['fato_id' => $fato['id'], 'versao' => 1]], $r->fatosUsados);

        // Gravação pelo Conferidor.
        $conferidor = $this->app->make(Conferidor::class);
        $caso0 = $this->app->make(RepositorioCaso::class)->porId($caso['id']);
        $this->assertNotNull($caso0);
        $regras = $this->app->make(RepositorioRegras::class);
        $item = new ItemEntregavel('E10', 'sistema', [], [], [new EntradaRegra('recibo_obrigacao', 'PGDAS-D', 0, [])], null, [new ConferenciaRegra('CF-12', [])], false, null, null);
        $conferidor->conferir($caso0, $e01, $item, $fatosCaso, new ParametrosDeConferencia($formulas, $regras->toleranciaEmUso(), 'DAS'));

        $linha = (array) DB::selectOne("SELECT resultado, classe, esperado_centavos, obtido_centavos, diferenca::text AS dif FROM conferencias.conferencia WHERE entregavel_id = ? AND cf = 'CF-12'", [$e01->id]);
        $this->assertSame('ok', $linha['resultado']);
        $this->assertNull($linha['esperado_centavos']);
        $this->assertNull($linha['obtido_centavos']);
        $this->assertSame('{"centavos": 0}', $linha['dif']);
    }

    public function test_cf12_sem_recibo_e_divergente_classe_s(): void
    {
        $caso = $this->abrirCaso('TCX51');
        $e01 = $this->app->make(RepositorioEntregavel::class)->porCasoTipo($caso['id'], 'E01');
        $this->assertNotNull($e01);
        $formulas = new Formulas(5000);
        $vazio = new FatosDoCaso([]);
        $conferidor = $this->app->make(Conferidor::class);
        $caso0 = $this->app->make(RepositorioCaso::class)->porId($caso['id']);
        $this->assertNotNull($caso0);
        $regras = $this->app->make(RepositorioRegras::class);
        $item = new ItemEntregavel('E10', 'sistema', [], [], [new EntradaRegra('recibo_obrigacao', 'PGDAS-D', 0, [])], null, [new ConferenciaRegra('CF-12', [])], false, null, null);
        $conferidor->conferir($caso0, $e01, $item, $vazio, new ParametrosDeConferencia($formulas, $regras->toleranciaEmUso(), 'DAS'));

        $linha = (array) DB::selectOne("SELECT resultado, classe, esperado_centavos, obtido_centavos, diferenca::text AS dif FROM conferencias.conferencia WHERE entregavel_id = ? AND cf = 'CF-12'", [$e01->id]);
        $this->assertSame('divergente', $linha['resultado']);
        $this->assertSame('S', $linha['classe']);
        $this->assertNull($linha['esperado_centavos']);
        $this->assertNull($linha['obtido_centavos']);
        $this->assertStringContainsString('recibo_obrigacao[PGDAS-D]', self::txt($linha['dif']));
    }

    public function test_conferencia_grava_semantica_calculo_corrente_e_legada_nao_e_reaproveitada(): void
    {
        $caso = $this->abrirCaso('TCX60');
        $e01 = $this->app->make(RepositorioEntregavel::class)->porCasoTipo($caso['id'], 'E01');
        $this->assertNotNull($e01);
        $this->postJson('/fatos', [
            'titular_id' => 'TCX60', 'competencia' => self::COMP, 'tipo' => 'recibo_obrigacao', 'tributo' => 'PGDAS-D',
            'fonte' => 'integra', 'observado_em' => '2026-10-01T09:00:00-03:00', 'payload' => ['numero_recibo' => 'R-1'],
        ])->assertOk();
        $fato = (array) DB::selectOne("SELECT id::text AS id FROM fatos.fato WHERE titular_id = 'TCX60' AND tipo = 'recibo_obrigacao'");
        $this->assertIsString($fato['id']);
        $usados = [['fato_id' => $fato['id'], 'versao' => 1]];
        $fatosCaso = new FatosDoCaso([
            new Fato($fato['id'], 'TCX60', self::COMP, 'recibo_obrigacao', 'PGDAS-D', 1, null, new \stdClass, 'h'),
        ]);
        $formulas = new Formulas(5000);
        $conferidor = $this->app->make(Conferidor::class);
        $caso0 = $this->app->make(RepositorioCaso::class)->porId($caso['id']);
        $this->assertNotNull($caso0);
        $tolerancia = $this->app->make(RepositorioRegras::class)->toleranciaEmUso();
        $item = new ItemEntregavel('E10', 'sistema', [], [], [new EntradaRegra('recibo_obrigacao', 'PGDAS-D', 0, [])], null, [new ConferenciaRegra('CF-12', [])], false, null, null);

        // Conferência LEGADA (semantica 1) inserida à mão, com o MESMO recibo, a mesma versão e a mesma regra.
        $hashLegado = HashConferencia::entradaHash('CF-12', $usados, $tolerancia->versaoId, 1);
        DB::insert(
            "INSERT INTO conferencias.conferencia (cf, caso_id, entregavel_id, regra_versao_id, fatos_usados, entrada_hash, resultado, esperado_centavos, obtido_centavos, diferenca, severidade, classe, semantica_calculo)
             VALUES ('CF-12', ?, ?, ?, ?::jsonb, ?, 'ok', 0, 0, '{\"centavos\": 0}'::jsonb, 'B', 'O', 1)",
            [$caso['id'], $e01->id, $tolerancia->versaoId, json_encode($usados, JSON_THROW_ON_ERROR), $hashLegado],
        );

        $parametros = new ParametrosDeConferencia($formulas, $tolerancia, 'DAS');
        $conferidor->conferir($caso0, $e01, $item, $fatosCaso, $parametros);
        $conferidor->conferir($caso0, $e01, $item, $fatosCaso, $parametros); // idempotente com semantica 2

        $linhas = array_map(
            static fn (mixed $l): array => (array) $l,
            DB::select("SELECT semantica_calculo, entrada_hash, esperado_centavos FROM conferencias.conferencia WHERE entregavel_id = ? AND cf = 'CF-12' ORDER BY semantica_calculo", [$e01->id]),
        );
        $this->assertCount(2, $linhas, 'a legada não foi reaproveitada e a nova não duplicou');
        $this->assertSame('1', self::txt($linhas[0]['semantica_calculo']));
        $this->assertSame((string) HashConferencia::SEMANTICA_CALCULO, self::txt($linhas[1]['semantica_calculo']));
        $this->assertNotSame($linhas[0]['entrada_hash'], $linhas[1]['entrada_hash']);
        $this->assertNull($linhas[1]['esperado_centavos']);
    }

    public function test_tolerancia_malformada_responde_500_regra_invalida_e_nao_grava_conferencia(): void
    {
        $caso = $this->abrirCaso('TCX70');
        DB::update("UPDATE regras.regra_versao SET conteudo = '{\"observacao\":\"sem padrao\"}'::jsonb WHERE regra_chave = 'fiscal.tolerancia' AND status IN ('provisoria','ativa')");
        $this->app->forgetScopedInstances(); // próxima requisição: a leitura de regra é refeita

        // A reavaliação lê a tolerância em uso: com a regra malformada o motor para, em vez de assumir 0/0.
        $this->postJson('/fatos', ['titular_id' => 'TCX70', 'competencia' => self::COMP, 'tipo' => 'faturamento_mes', 'fonte' => 'intranet', 'observado_em' => '2026-10-01T09:00:00-03:00', 'valor_centavos' => 100])
            ->assertStatus(500)
            ->assertJsonPath('erro', 'regra_invalida')
            ->assertJsonPath('mensagem', fn (string $m): bool => str_contains($m, 'fiscal.tolerancia') && str_contains($m, "'padrao' ausente"));

        $this->assertSame(0, DB::table('conferencias.conferencia')->where('caso_id', $caso['id'])->count());
    }

    public function test_parametros_ausentes_nao_viram_prolabore_zero(): void
    {
        $this->abrirCaso('TCX71');
        DB::update("UPDATE regras.regra_versao SET conteudo = '{}'::jsonb WHERE regra_chave = 'fiscal.parametros' AND status IN ('provisoria','ativa')");
        $this->app->forgetScopedInstances();

        $this->expectException(RegraInvalida::class);
        $this->app->make(RepositorioRegras::class)->parametrosEmUso();
    }
}
