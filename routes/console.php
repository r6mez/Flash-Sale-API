<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('holds:release-expired')->everyMinute();