<?php

namespace Spawn\Laravel\Database;

use Illuminate\Database\MariaDbConnection;

class AsyncMariaDbConnection extends MariaDbConnection
{
    use CoroutineTransactions;
}
