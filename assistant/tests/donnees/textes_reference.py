"""Textes figés pour vérifier que les réglages par défaut ne changent rien au découpage."""

SECTIONS = (
    "Préambule court.\n\n"
    "1. OBJET\nCe document fixe les règles.\n\n"
    "1.1. Domaine\nIl s'applique à tous les ateliers.\n\n"
    "1.2. Références\nVoir les instructions en vigueur.\n\n"
    "2. RESPONSABILITÉS\n" + "Le chef d'équipe organise le travail. " * 60 + "\n\n"
    "2.1. Technicien\nLe technicien exécute les tâches.\n\n"
    "Section 3 - Contrôles\nLes contrôles sont tracés.\n"
)

PARAGRAPHES = "\n\n".join(
    f"Paragraphe {i}. " + "Une phrase de contenu ordinaire. " * (3 + i % 7) for i in range(25)
)

TEXTES = {"sections": SECTIONS, "paragraphes": PARAGRAPHES}
