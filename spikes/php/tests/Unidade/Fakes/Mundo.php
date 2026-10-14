<?php

declare(strict_types=1);

namespace Tests\Unidade\Fakes;

use App\Aplicacao\Conferencias\Conferidor;
use App\Aplicacao\Conferencias\FatosDoCaso;
use App\Aplicacao\Conferencias\Formulas;
use App\Aplicacao\Conferencias\ParametrosDeConferencia;
use App\Aplicacao\Fatos\PublicarFato;
use App\Aplicacao\Fatos\PublicarFatoComando;
use App\Aplicacao\Fatos\ResultadoFato;
use App\Aplicacao\Fechamento\AbrirCompetencias;
use App\Aplicacao\Fechamento\AbrirCompetenciasComando;
use App\Aplicacao\Fechamento\Acompanhar;
use App\Aplicacao\Fechamento\AvaliarCaso;
use App\Aplicacao\Fechamento\ConcluirTarefa;
use App\Aplicacao\Fechamento\ConsultarCaso;
use App\Aplicacao\Fechamento\Excecoes;
use App\Aplicacao\Fechamento\Invalidador;
use App\Aplicacao\Fechamento\RegistrarOverride;
use App\Aplicacao\Fechamento\Transicionador;
use App\Dominio\Fechamento\Caso;
use App\Dominio\Fechamento\Empresa;
use App\Dominio\Fechamento\Entregavel;
use App\Dominio\Regras\ItemEntregavel;
use App\Dominio\Regras\RegraEntregaveis;
use App\Dominio\Regras\Tolerancia;
use RuntimeException;

/**
 * O motor inteiro (casos de uso reais) montado sobre portas em memória: sem banco, sem Laravel.
 * Os testes de unidade e de aplicação puros partem daqui.
 */
final class Mundo
{
    public const COMP = '202601';

    public CasosMemoria $casos;

    public EntregaveisMemoria $entregaveis;

    public DependenciasMemoria $dependencias;

    public FatosMemoria $fatos;

    public TarefasMemoria $tarefas;

    public EventosMemoria $eventos;

    public ConferenciasMemoria $conferencias;

    public ExcecoesMemoria $excecoes;

    public CatalogoMemoria $catalogo;

    public RegrasMemoria $regras;

    public UnidadeDeTrabalhoMemoria $uow;

    public RelogioFixo $relogio;

    public Transicionador $transicionador;

    public Excecoes $abertura;

    public Conferidor $conferidor;

    public AvaliarCaso $avaliar;

    public Invalidador $invalidador;

    public Acompanhar $acompanhar;

    public AbrirCompetencias $abrir;

    public PublicarFato $publicar;

    public ConcluirTarefa $concluir;

    public RegistrarOverride $override;

    public ConsultarCaso $consultar;

    /** @param  array<string, mixed>|null  $conteudoDaRegra  padrão: self::regraPadrao() */
    public function __construct(?array $conteudoDaRegra = null)
    {
        $this->casos = new CasosMemoria;
        $this->entregaveis = new EntregaveisMemoria;
        $this->dependencias = new DependenciasMemoria($this->entregaveis);
        $this->fatos = new FatosMemoria;
        $this->tarefas = new TarefasMemoria;
        $this->eventos = new EventosMemoria;
        $this->conferencias = new ConferenciasMemoria;
        $this->excecoes = new ExcecoesMemoria;
        $this->catalogo = new CatalogoMemoria;
        $this->regras = new RegrasMemoria(RegraEntregaveis::de('regra-v2', 2, $conteudoDaRegra ?? self::regraPadrao()));
        $this->uow = new UnidadeDeTrabalhoMemoria;
        $this->relogio = new RelogioFixo;

        $this->transicionador = new Transicionador($this->entregaveis, $this->catalogo, $this->eventos);
        $this->abertura = new Excecoes($this->excecoes, $this->eventos);
        $this->conferidor = new Conferidor($this->conferencias, $this->catalogo);
        $this->avaliar = new AvaliarCaso(
            $this->casos, $this->entregaveis, $this->dependencias, $this->fatos, $this->tarefas, $this->eventos,
            $this->conferencias, $this->abertura, $this->regras, $this->transicionador, $this->conferidor,
        );
        $this->invalidador = new Invalidador(
            $this->casos, $this->entregaveis, $this->dependencias, $this->tarefas, $this->excecoes, $this->abertura,
            $this->eventos, $this->transicionador,
        );
        $this->acompanhar = new Acompanhar($this->entregaveis, $this->excecoes, $this->abertura, $this->eventos, $this->fatos, $this->regras);
        $this->abrir = new AbrirCompetencias(
            $this->uow, $this->casos, $this->entregaveis, $this->dependencias, $this->eventos, $this->regras,
            $this->catalogo, $this->transicionador, $this->avaliar,
        );
        $this->publicar = new PublicarFato(
            $this->uow, $this->fatos, $this->casos, $this->entregaveis, $this->eventos, $this->avaliar,
            $this->invalidador, $this->acompanhar,
        );
        $this->concluir = new ConcluirTarefa(
            $this->uow, $this->casos, $this->entregaveis, $this->tarefas, $this->fatos, $this->eventos, $this->regras,
            $this->transicionador, $this->avaliar, $this->invalidador, $this->relogio,
        );
        $this->override = new RegistrarOverride(
            $this->uow, $this->casos, $this->entregaveis, $this->conferencias, $this->excecoes, $this->eventos,
            $this->transicionador, $this->avaliar,
        );
        $this->consultar = new ConsultarCaso($this->casos, $this->entregaveis, $this->tarefas, $this->excecoes);
    }

    /** Abre o caso (AbrirCompetencias real) e o devolve. */
    public function abrirCaso(string $titular = 'T1', string $competencia = self::COMP, string $regime = 'SN'): Caso
    {
        $empresa = new Empresa($titular, $regime === 'LP' ? 'LP' : 'SN', true, false, false, false, 'C-01', '3549805');
        $this->abrir->abrir(new AbrirCompetenciasComando($competencia, 'teste', [$empresa]));

        foreach ($this->casos->casos as $caso) {
            if ($caso->titularId === $titular && $caso->competencia->valor === $competencia) {
                return $caso;
            }
        }

        throw new RuntimeException('caso não aberto');
    }

    /** Publica um fato (PublicarFato real) e devolve o resultado. */
    public function fato(string $tipo, ?int $valor, mixed $payload = null, string $tributo = '', string $titular = 'T1', string $competencia = self::COMP): ResultadoFato
    {
        return $this->publicar->publicar(new PublicarFatoComando(
            $titular, $competencia, $tipo, $tributo, $valor, $payload ?? new \stdClass, 'teste', null, '2026-01-10T09:00:00-03:00',
        ));
    }

    public function entregavel(Caso $caso, string $tipo): Entregavel
    {
        return $this->entregaveis->porCasoTipo($caso->id, $tipo) ?? throw new RuntimeException("sem {$tipo}");
    }

    public function idDe(Caso $caso, string $tipo): string
    {
        return $this->entregavel($caso, $tipo)->id;
    }

    public function item(string $tipo): ItemEntregavel
    {
        return $this->regras->entregaveisEmUso()->item($tipo) ?? throw new RuntimeException("regra sem {$tipo}");
    }

    public function fatosDoCaso(string $titular = 'T1', string $competencia = self::COMP): FatosDoCaso
    {
        return new FatosDoCaso($this->fatos->vigentesDoCaso($titular, $competencia));
    }

    /** Roda o Conferidor real sobre o entregável do caso, com tolerância 0/100 e prolabore 28%. */
    public function conferir(Caso $caso, string $tipo, string $tributoGuia = 'DAS'): bool
    {
        return $this->conferidor->conferir(
            $caso, $this->entregavel($caso, $tipo), $this->item($tipo), $this->fatosDoCaso($caso->titularId, $caso->competencia->valor),
            new ParametrosDeConferencia(new Formulas(2800), new Tolerancia('tol-1', 0, 100), $tributoGuia),
        );
    }

    public function estado(Caso $caso, string $tipo): string
    {
        return $this->entregavel($caso, $tipo)->estado->value;
    }

    /**
     * Mesma forma da regra v2 de db/migrations/V006 (SN/LP, cadeia E01..E12), enxuta: sem E04..E08.
     *
     * @return array{estado_minimo_padrao: string, entregaveis: list<array<string, mixed>>}
     */
    public static function regraPadrao(): array
    {
        return [
            'estado_minimo_padrao' => 'validado',
            'entregaveis' => [
                ['tipo' => 'E01', 'executor' => 'sistema', 'quando' => [], 'depende_de' => [],
                    'entradas' => [['tipo' => 'faturamento_mes'], ['tipo' => 'notas_oneflow']],
                    'saida' => null, 'conferencias' => [['cf' => 'CF-01']]],
                ['tipo' => 'E02', 'executor' => 'sistema', 'quando' => [['regime' => 'SN']], 'depende_de' => [],
                    'entradas' => [['tipo' => 'aliquota_mes']], 'saida' => null, 'conferencias' => []],
                ['tipo' => 'E03', 'executor' => 'humano', 'quando' => [], 'depende_de' => [['tipo' => 'E01'], ['tipo' => 'E02']],
                    'entradas' => [], 'saida' => ['tipo' => 'apurado'],
                    'conferencias' => [['cf' => 'CF-04', 'quando' => [['regime' => 'SN']]]]],
                ['tipo' => 'E10', 'executor' => 'sistema', 'quando' => [], 'depende_de' => [['tipo' => 'E03']],
                    'entradas' => [
                        ['tipo' => 'recibo_obrigacao', 'tributo' => 'PGDAS-D', 'quando' => [['regime' => 'SN']]],
                        ['tipo' => 'recibo_obrigacao', 'tributo' => 'DCTFWEB', 'quando' => [['regime' => 'LP']]],
                    ],
                    'saida' => null, 'conferencias' => [['cf' => 'CF-12']]],
                ['tipo' => 'E11', 'executor' => 'sistema', 'quando' => [], 'depende_de' => [['tipo' => 'E03']],
                    'entradas' => [
                        ['tipo' => 'guia', 'tributo' => 'DAS', 'quando' => [['regime' => 'SN']]],
                        ['tipo' => 'guia', 'tributo' => 'IRPJ', 'quando' => [['regime' => 'LP']]],
                    ],
                    'saida' => null, 'conferencias' => [['cf' => 'CF-05'], ['cf' => 'CF-06']],
                    'pos_validacao' => ['liberacao' => 'automatica', 'disponibilizado_quando' => ['tipo' => 'documento_disponibilizado']],
                    'acompanhamento' => [
                        'falha' => ['tipo' => 'entrega_falhou'],
                        'confirmacao' => ['tipo' => 'entrega_confirmada'],
                        'pendencia' => ['tipo' => 'recebimento_pendente'],
                    ]],
                ['tipo' => 'E12', 'executor' => 'sistema', 'quando' => [],
                    'depende_de' => [['tipo' => 'E01'], ['tipo' => 'E02'], ['tipo' => 'E03'], ['tipo' => 'E10'], ['tipo' => 'E11', 'estado_minimo' => 'disponibilizado']],
                    'entradas' => [], 'saida' => null, 'conferencias' => [], 'encerra_caso' => true],
            ],
        ];
    }
}
