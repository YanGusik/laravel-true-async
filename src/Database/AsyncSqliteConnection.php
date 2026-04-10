<?php

namespace Spawn\Laravel\Database;

use Illuminate\Database\SQLiteConnection;

class AsyncSqliteConnection extends SQLiteConnection
{
    use CoroutineTransactions;
}
