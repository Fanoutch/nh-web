<?php

use App\Models\ExcelReport;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('attaches existing dispos to BMN when adding secteur_id', function () {
    $migration = require database_path('migrations/2026_09_15_100002_add_secteur_id_to_excel_reports_table.php');
    $migration->down();

    $user = User::factory()->create();
    $id = DB::table('excel_reports')->insertGetId([
        'user_id' => $user->id, 'filename' => 'ancienne.csv', 'status' => 'ok',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('excel_reports')->where('id', $id)->value('secteur_id'))->toBe(secteurBmn()->id);
});

it('requires a secteur on every dispo', function () {
    $user = User::factory()->create();

    ExcelReport::create(['user_id' => $user->id, 'filename' => 'x.csv', 'status' => 'pending']);
})->throws(QueryException::class);
