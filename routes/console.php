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

// Agents : mise en veille des agents non lancés depuis 3 mois (même cron `schedule:run` que ci-dessus).
Schedule::command('agents:entretien')->dailyAt('03:15');
