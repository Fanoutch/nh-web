<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

class AuditLogTable extends Component
{
    use WithPagination;

    public string $logName = '';
    public string $event = '';

    public function updatingLogName(): void { $this->resetPage(); }
    public function updatingEvent(): void { $this->resetPage(); }

    public function render()
    {
        $query = Activity::with('causer')
            ->latest();

        if ($this->logName !== '') {
            $query->where('log_name', $this->logName);
        }
        if ($this->event !== '') {
            $query->where('event', $this->event);
        }

        return view('livewire.audit-log-table', [
            'activities' => $query->paginate(30),
            'logNames' => Activity::select('log_name')->distinct()->pluck('log_name')->filter()->values(),
            'events' => Activity::select('event')->distinct()->pluck('event')->filter()->values(),
        ]);
    }
}
