<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Onglet BMN : purge quotidienne des dispos générées (nécessite `schedule:run` en cron ;
// la purge est aussi déclenchée après chaque génération réussie, en secours).
Schedule::command('bmn:purge')->dailyAt('03:00');
