<?php

namespace Tests\Feature\Modules;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\Identity\Domain\UserAggregate;
use Modules\Orders\Application\OpenOrder;
use Modules\Orders\Domain\Exceptions\DuplicateOrderNumberException;
use Modules\Orders\Infrastructure\ReadModels\Order;
use Tests\TestCase;

/**
 * api#52: prova a corrida de verdade, não só o pre-check sequencial que
 * OrdersNumberingTest::test_rejects_opening_a_second_order_with_the_same_number já cobre.
 *
 * `:memory:` (o padrão de `phpunit.xml`) não serve aqui: cada conexão a um banco `:memory:` é um
 * banco isolado — não existe lock nenhum pra disputar entre duas conexões. `setUp()` troca a
 * conexão default por um arquivo sqlite de verdade só nesta classe, DEPOIS do app já ter subido
 * (não existe hook tipo `getEnvironmentSetUp()` no `Illuminate\Foundation\Testing\TestCase` desta
 * versão — isso é só do Orchestra Testbench, usado em pacote, não em aplicação; `createApplication()`
 * já sobe e migra tudo dentro de `parent::setUp()`, sem ponto de entrada antes disso). Por isso
 * troca o config DEPOIS, `DB::purge()` pra descartar a conexão `:memory:` já resolvida, e roda
 * `migrate` de novo — desta vez contra o arquivo de verdade. A conexão default (usada pela
 * aplicação) e "race" (aberta à parte, simulando outra requisição) apontam pro MESMO arquivo,
 * então concorrem pelo lock de escrita de verdade.
 *
 * PHP é single-threaded — não dá pra rodar as duas "requisições" ao mesmo tempo de fato. Em vez
 * disso, a conexão "race" segura uma transação aberta (sem commit) enquanto chama-se `OpenOrder`;
 * o INSERT dele esbarra no lock e falha com "database is locked" (SQLite) — a mesma classe de
 * erro que o "Lock wait timeout" do MySQL sob a mesma contenção (as duas são reconhecidas por
 * `Illuminate\Database\ConcurrencyErrorDetector`, o detector que o retry embutido de
 * `DB::transaction($callback, $attempts)` já usa). Um listener no evento `TransactionRolledBack`
 * comita a conexão "race" assim que a tentativa de `OpenOrder` desiste da primeira rodada,
 * simulando "a outra requisição terminou primeiro" no exato momento em que o retry entra em ação
 * — a segunda tentativa então esbarra no registro de verdade já commitado, e cai no caminho normal
 * de "número duplicado".
 */
class OrdersConcurrencyTest extends TestCase
{
    private string $sqlitePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sqlitePath = sys_get_temp_dir().'/orders_concurrency_'.Str::random(8).'.sqlite';
        touch($this->sqlitePath);

        config(['database.connections.sqlite.database' => $this->sqlitePath]);
        DB::purge('sqlite');

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->sqlitePath) && file_exists($this->sqlitePath)) {
            unlink($this->sqlitePath);
        }
    }

    private function aClientId(): string
    {
        $uuid = (string) Str::uuid();
        ClientAggregate::retrieve($uuid)
            ->register(
                personType: PersonType::Company,
                name: 'Hospital São Lucas',
                taxId: '31233218000110',
                tradeName: null,
                stateRegistration: null,
                requester: null,
                department: null,
                phone: null,
                email: null,
                address: null,
                city: null,
                state: null,
                postalCode: null,
            )
            ->persist();

        return $uuid;
    }

    private function aUserId(): string
    {
        $uuid = (string) Str::uuid();
        UserAggregate::retrieve($uuid)
            ->register('Ana Técnica', 'ana@medfusion.example', Hash::make('segredo'))
            ->persist();

        return $uuid;
    }

    /**
     * Abre a transação "concorrente" e insere a linha em disputa — sem commit. Devolve a conexão
     * já com a transação em aberto, pra quem chamou decidir quando ela resolve (commit/rollback).
     */
    private function raceForNumber(int $number, string $clientId, string $userId, string $winningId): Connection
    {
        config(['database.connections.race' => config('database.connections.'.config('database.default'))]);
        $race = DB::connection('race');

        $race->beginTransaction();
        $race->table('orders')->insert([
            'id' => $winningId,
            'number' => $number,
            'date' => '2026-09-25',
            'client_id' => $clientId,
            'user_id' => $userId,
            'picked_up' => false,
            'warranty' => false,
            'technical_training' => false,
            'on_site_quote' => false,
            'rental' => false,
            'total' => 0,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $race;
    }

    public function test_two_connections_racing_for_the_same_number_end_in_one_success_and_one_clean_conflict(): void
    {
        $clientId = $this->aClientId();
        $userId = $this->aUserId();
        $number = 424242;
        $winningId = (string) Str::uuid();

        $race = $this->raceForNumber($number, $clientId, $userId, $winningId);

        // A "outra requisição" (race) só comita quando a nossa desiste da primeira tentativa e dá
        // rollback — o mais perto que dá de simular "ela terminou primeiro" num processo só.
        $raceCommitted = false;
        Event::listen(TransactionRolledBack::class, function () use ($race, &$raceCommitted) {
            if (! $raceCommitted) {
                $raceCommitted = true;
                $race->commit();
            }
        });

        try {
            $this->expectException(DuplicateOrderNumberException::class);

            app(OpenOrder::class)(
                $number, '2026-09-25', $clientId, $userId,
                false, false, false, false, false, null, null, null, null, null, null, null,
            );
        } finally {
            if (! $raceCommitted) {
                $race->rollBack();
            }
        }
    }

    public function test_the_order_from_the_connection_that_committed_first_is_the_one_that_survives(): void
    {
        $clientId = $this->aClientId();
        $userId = $this->aUserId();
        $number = 424243;
        $winningId = (string) Str::uuid();

        $race = $this->raceForNumber($number, $clientId, $userId, $winningId);

        $raceCommitted = false;
        Event::listen(TransactionRolledBack::class, function () use ($race, &$raceCommitted) {
            if (! $raceCommitted) {
                $raceCommitted = true;
                $race->commit();
            }
        });

        try {
            app(OpenOrder::class)(
                $number, '2026-09-25', $clientId, $userId,
                false, false, false, false, false, null, null, null, null, null, null, null,
            );

            $this->fail('Esperava DuplicateOrderNumberException.');
        } catch (DuplicateOrderNumberException) {
            // esperado
        } finally {
            if (! $raceCommitted) {
                $race->rollBack();
            }
        }

        // A que "chegou primeiro" (a da transação que já estava aberta) é a que sobrevive — nada
        // da tentativa perdedora vaza pro banco.
        $this->assertSame(1, Order::where('number', $number)->count());
        $this->assertDatabaseHas('orders', ['id' => $winningId, 'number' => $number]);
    }
}
