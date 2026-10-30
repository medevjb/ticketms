<?php

namespace Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

abstract class MySqlDatabaseTestCase extends TestCase
{
    private static ?string $testDatabase = null;

    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        if (getenv('RUN_MYSQL_SCHEMA_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_MYSQL_SCHEMA_TESTS=1 to use a disposable MySQL schema.');
        }

        parent::setUp();

        $connection = config('database.connections.mysql');
        $connection['url'] = null;
        $connection['database'] = null;
        config(['database.connections.mysql_schema_admin' => $connection]);

        if (self::$testDatabase === null) {
            $name = 'ticketms_schema_test_'.bin2hex(random_bytes(8));
            $admin = DB::connection('mysql_schema_admin');
            $admin->statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
            self::$testDatabase = $name;
            $pdo = $admin->getPdo();

            register_shutdown_function(static function () use ($pdo, $name): void {
                if (preg_match('/\Aticketms_schema_test_[a-f0-9]{16}\z/', $name) === 1) {
                    $pdo->exec("DROP DATABASE `{$name}`");
                }
            });

            $this->configureTestConnection($connection);
            $exitCode = Artisan::call('migrate', ['--database' => 'mysql_schema_test', '--force' => true]);
            $this->assertSame(0, $exitCode, Artisan::output());
            self::$schemaReady = true;
        } else {
            $this->assertTrue(self::$schemaReady, 'Disposable schema migration did not finish successfully.');
            $this->configureTestConnection($connection);
        }

        DB::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            while (DB::connection()->transactionLevel() > 0) {
                DB::connection()->rollBack();
            }
        }

        parent::tearDown();
    }

    /** @param array<string, mixed> $connection */
    private function configureTestConnection(array $connection): void
    {
        $connection['database'] = self::$testDatabase;
        config(['database.connections.mysql_schema_test' => $connection]);
        DB::setDefaultConnection('mysql_schema_test');
    }
}
