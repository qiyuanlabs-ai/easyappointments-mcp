<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('mcp:prune')->daily();
Schedule::command('passport:purge')->daily();
