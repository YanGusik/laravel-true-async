<?php

namespace Spawn\Laravel\Database;

use Illuminate\Database\MySqlConnection;

class AsyncMySqlConnection extends MySqlConnection
{
    use CoroutineTransactions;
}
