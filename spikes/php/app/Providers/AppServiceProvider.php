<?php

declare(strict_types=1);

namespace App\Providers;

use App\Aplicacao\Portas\Relogio;
use App\Aplicacao\Portas\RepositorioCaso;
use App\Aplicacao\Portas\RepositorioCatalogo;
use App\Aplicacao\Portas\RepositorioConferencia;
use App\Aplicacao\Portas\RepositorioContratoTipoFato;
use App\Aplicacao\Portas\RepositorioDependencia;
use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Portas\RepositorioEvento;
use App\Aplicacao\Portas\RepositorioExcecao;
use App\Aplicacao\Portas\RepositorioFato;
use App\Aplicacao\Portas\RepositorioPrazo;
use App\Aplicacao\Portas\RepositorioRecebimento;
use App\Aplicacao\Portas\RepositorioRegras;
use App\Aplicacao\Portas\RepositorioTarefa;
use App\Aplicacao\Portas\UnidadeDeTrabalho;
use App\Infraestrutura\Persistencia\RelogioDb;
use App\Infraestrutura\Persistencia\RepositorioCasoDb;
use App\Infraestrutura\Persistencia\RepositorioCatalogoDb;
use App\Infraestrutura\Persistencia\RepositorioConferenciaDb;
use App\Infraestrutura\Persistencia\RepositorioContratoTipoFatoDb;
use App\Infraestrutura\Persistencia\RepositorioDependenciaDb;
use App\Infraestrutura\Persistencia\RepositorioEntregavelDb;
use App\Infraestrutura\Persistencia\RepositorioEventoDb;
use App\Infraestrutura\Persistencia\RepositorioExcecaoDb;
use App\Infraestrutura\Persistencia\RepositorioFatoDb;
use App\Infraestrutura\Persistencia\RepositorioPrazoDb;
use App\Infraestrutura\Persistencia\RepositorioRecebimentoDb;
use App\Infraestrutura\Persistencia\RepositorioRegrasDb;
use App\Infraestrutura\Persistencia\RepositorioTarefaDb;
use App\Infraestrutura\Persistencia\UnidadeDeTrabalhoDb;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A conexão 'pgsql' é a única fonte; MOTOR_DB a configura.
        $this->app->bind(ConnectionInterface::class, static fn (): ConnectionInterface => DB::connection('pgsql'));

        $this->app->bind(UnidadeDeTrabalho::class, UnidadeDeTrabalhoDb::class);
        $this->app->bind(Relogio::class, RelogioDb::class);
        // `scoped`: uma instância por requisição (memória de leitura de regra, catálogo e dependências); nunca
        // sobrevive à requisição (tech.md: nenhum estado entre requisições).
        $this->app->scoped(RepositorioRegras::class, RepositorioRegrasDb::class);
        $this->app->scoped(RepositorioCatalogo::class, RepositorioCatalogoDb::class);
        $this->app->bind(RepositorioCaso::class, RepositorioCasoDb::class);
        $this->app->bind(RepositorioEntregavel::class, RepositorioEntregavelDb::class);
        $this->app->bind(RepositorioPrazo::class, RepositorioPrazoDb::class);
        $this->app->scoped(RepositorioDependencia::class, RepositorioDependenciaDb::class);
        $this->app->bind(RepositorioFato::class, RepositorioFatoDb::class);
        $this->app->bind(RepositorioContratoTipoFato::class, RepositorioContratoTipoFatoDb::class);
        $this->app->bind(RepositorioRecebimento::class, RepositorioRecebimentoDb::class);
        $this->app->bind(RepositorioConferencia::class, RepositorioConferenciaDb::class);
        $this->app->bind(RepositorioExcecao::class, RepositorioExcecaoDb::class);
        $this->app->bind(RepositorioTarefa::class, RepositorioTarefaDb::class);
        $this->app->bind(RepositorioEvento::class, RepositorioEventoDb::class);
    }

    public function boot(): void
    {
        //
    }
}
