import './bootstrap';

// Alpine n'est PAS démarré ici : Livewire 3 embarque sa propre instance d'Alpine (avec ses
// plugins) et la démarre lui-même. En démarrer une seconde ici faisait que Livewire détectait
// « multiple instances of Alpine running » et renonçait à initialiser ses composants : plus aucun
// wire:click ni wire:submit ne fonctionnait (chat, explorateur de documents, tableaux).
// Les pages sans Livewire (connexion) n'utilisent pas Alpine.
