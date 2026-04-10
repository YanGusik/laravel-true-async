<?php

namespace Spawn\Laravel\Database;

use Illuminate\Database\PostgresConnection;

class AsyncPgsqlConnection extends PostgresConnection
{
    use CoroutineTransactions;
}
