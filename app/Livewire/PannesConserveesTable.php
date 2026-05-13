<?php

namespace App\Livewire;

use App\Models\Flight;
use App\Models\MissingPanne;
use App\Models\TechnicalEvent;
use Livewire\Component;

class PannesConserveesTable extends Component
{
    public Flight $flight;
    public string $search = '';
    public ?int $selectedEventId = null;

    public bool $showMissingModal = false;
    public string $newFailureCode = '';
    public string $newDescription = '';

    public function mount(Flight $flight): void
    {
        $this->flight = $flight;
    }

    public function setValidation(int $eventId, string $status): void
    {
        $te = TechnicalEvent::where('flight_id', $this->flight->id)->findOrFail($eventId);
        $te->update([
            'validation_status' => $status,
            'validated_by' => auth()->id(),
            'validated_at' => now(),
        ]);
    }

    public function openDetail(int $eventId): void
    {
        $this->selectedEventId = $eventId;
    }

    public function closeDetail(): void
    {
        $this->selectedEventId = null;
    }

    public function openMissingModal(): void
    {
        $this->showMissingModal = true;
        $this->reset(['newFailureCode', 'newDescription']);
    }

    public function submitMissingPanne(): void
    {
        $this->validate(['newFailureCode' => 'required|string|max:255']);
        MissingPanne::create([
            'flight_id' => $this->flight->id,
            'failure_code' => $this->newFailureCode,
            'description' => $this->newDescription ?: null,
            'reported_by' => auth()->id(),
            'reported_at' => now(),
        ]);
        $this->reset(['showMissingModal', 'newFailureCode', 'newDescription']);
    }

    public function deleteMissing(int $id): void
    {
        $m = MissingPanne::where('flight_id', $this->flight->id)->findOrFail($id);
        if ($m->reported_by === auth()->id()) $m->delete();
    }

    public function render()
    {
        $query = $this->flight->technicalEvents()
            ->where('status', 'conservee')
            ->with(['validator', 'pnValidator'])
            ->orderBy('raise_datetime');

        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('technical_event_id', 'ilike', "%{$search}%")
                  ->orWhereRaw("details::text ilike ?", ["%{$search}%"]);
            });
        }

        return view('livewire.pannes-conservees-table', [
            'pannes' => $query->get(),
            'selected' => $this->selectedEventId
                ? TechnicalEvent::find($this->selectedEventId)
                : null,
            'missingPannes' => $this->flight->missingPannes()
                ->with('reporter')->latest()->get(),
        ]);
    }
}
