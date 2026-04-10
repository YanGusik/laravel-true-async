<?php

namespace Spawn\Laravel\Database;

use Illuminate\Database\SqlServerConnection;

class AsyncSqlServerConnection extends SqlServerConnection
{
    use CoroutineTransactions;
}
