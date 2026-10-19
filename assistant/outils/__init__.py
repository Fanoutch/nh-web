"""Outils de l'agent. Importer ce paquet enregistre tous les outils."""
from .registre import Contexte, Reponse, declaration_outils, erreur, executer, ok, outil  # noqa: F401
from . import excel  # noqa: F401  — enregistre les outils Excel
