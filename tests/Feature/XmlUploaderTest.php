<?php

use App\Livewire\XmlUploader;
use App\Models\Import;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('stages files and dispatches jobs on submit', function () {
    Queue::fake();
    Storage::fake('local');

    $user = User::factory()->create();

    $xmlContent = '<?xml version="1.0"?><HC><XmlHeader/></HC>';
    $file = UploadedFile::fake()->createWithContent('sample.xml', $xmlContent);

    Livewire::actingAs($user)
        ->test(XmlUploader::class)
        ->set('stagedFiles', [$file])
        ->assertCount('stagedFiles', 1)
        ->call('submit')
        ->assertRedirect(route('imports.index'));

    expect(Import::where('user_id', $user->id)->count())->toBe(1);
    Queue::assertPushed(\App\Jobs\ProcessXmlJob::class);
});

it('removes a staged file', function () {
    $user = User::factory()->create();

    $file1 = UploadedFile::fake()->createWithContent('a.xml', '<a/>');
    $file2 = UploadedFile::fake()->createWithContent('b.xml', '<b/>');

    Livewire::actingAs($user)
        ->test(XmlUploader::class)
        ->set('stagedFiles', [$file1, $file2])
        ->assertCount('stagedFiles', 2)
        ->call('removeStaged', 0)
        ->assertCount('stagedFiles', 1);
});
