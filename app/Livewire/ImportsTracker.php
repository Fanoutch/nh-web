<?php

namespace App\Livewire;

use App\Models\Import;
use Livewire\Component;

class ImportsTracker extends Component
{
    public string $filter = 'all';

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    public function render()
    {
        $query = Import::where('user_id', auth()->id())
            ->orderByDesc('id')
            ->limit(200);

        match ($this->filter) {
            'pending' => $query->whereIn('status', ['pending', 'processing']),
            'done'    => $query->whereIn('status', ['ok', 'already_processed', 'non_vol']),
            'errors'  => $query->where('status', 'error'),
            default   => $query,
        };

        return view('livewire.imports-tracker', [
            'imports' => $query->get(),
        ]);
    }
}
