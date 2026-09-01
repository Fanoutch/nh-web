<?php

namespace App\Livewire;

use App\Models\Flight;
use App\Models\TechnicalEvent;
use Livewire\Component;

class PannesOccurrentesTable extends Component
{
    public Flight $flight;

    /** Commentaire PN saisi par événement (id => texte), envoyé avec Confirmer/Rejeter. */
    public array $comments = [];

    public function mount(Flight $flight): void
    {
        $this->flight = $flight;
        $this->comments = $flight->technicalEvents()
            ->whereNotNull('pn_comment')
            ->pluck('pn_comment', 'id')
            ->all();
    }

    public function setPnValidation(int $eventId, string $status): void
    {
        abort_unless(in_array($status, ['pending', 'confirmed', 'rejected'], true), 422);

        $te = TechnicalEvent::where('flight_id', $this->flight->id)->find($eventId);
        abort_if($te === null, 404);
        $te->update([
            'pn_validation_status' => $status,
            'pn_validated_by' => auth()->id(),
            'pn_validated_at' => now(),
            'pn_comment' => trim($this->comments[$eventId] ?? '') ?: null,
        ]);
    }

    public function render()
    {
        $pannes = $this->flight->technicalEvents()
            ->where('nombre_occurrences', '>', 1)
            ->with(['pnValidator', 'validator'])
            ->orderByDesc('nombre_occurrences')
            ->get();

        return view('livewire.pannes-occurrentes-table', compact('pannes'));
    }
}
