<?php

namespace App\Livewire;

use App\Jobs\ProcessXmlJob;
use App\Models\Import;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class XmlUploader extends Component
{
    use WithFileUploads;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile[] */
    public array $stagedFiles = [];

    public bool $submitted = false;
    public int $dispatchedCount = 0;

    protected function rules(): array
    {
        return ['stagedFiles.*' => 'file|mimetypes:application/xml,text/xml,text/plain|max:51200'];
    }

    public function updatedStagedFiles(): void
    {
        $this->validate();
    }

    public function removeStaged(int $index): void
    {
        if (isset($this->stagedFiles[$index])) {
            unset($this->stagedFiles[$index]);
            $this->stagedFiles = array_values($this->stagedFiles);
        }
    }

    public function submit(): void
    {
        $this->validate();
        if (empty($this->stagedFiles)) return;

        $count = 0;
        foreach ($this->stagedFiles as $uploaded) {
            $originalName = $uploaded->getClientOriginalName();
            $path = $uploaded->storeAs(
                'staging',
                uniqid('', true) . '_' . $originalName,
                'local',
            );
            $absolute = Storage::disk('local')->path($path);

            $import = Import::create([
                'user_id' => auth()->id(),
                'filename' => $originalName,
                'status' => 'pending',
            ]);
            ProcessXmlJob::dispatch($import->id, $absolute);
            $count++;
        }

        $this->stagedFiles = [];
        $this->dispatchedCount = $count;
        $this->submitted = true;

        $this->redirectRoute('imports.index', navigate: false);
    }

    public function render()
    {
        return view('livewire.xml-uploader');
    }
}
