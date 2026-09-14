# Scheduling (bases, non finalisé)

Le script renvoie un code de sortie exploitable par le scheduler :

| Code | Signification |
|------|---------------|
| 0 | Excel généré, CSV archivé |
| 2 | Aucun CSV dans `data/incoming` (run ignoré) |
| 1 | Erreur (colonne manquante, formule menacée, template absent, ...) |

Chaque run écrit une ligne `RUN OK` / `RUN SKIPPED` / `RUN FAILED` dans `logs/daily_report.log`.

## Linux / macOS : cron

```
crontab -e
# Tous les jours à 07:30
30 7 * * * /chemin/vers/BMN/scheduling/run_daily.sh >> /chemin/vers/BMN/logs/cron.log 2>&1
```

Pour être alerté en cas d'échec ou de CSV absent, ajouter par exemple :
```
30 7 * * * /chemin/vers/BMN/scheduling/run_daily.sh || echo "BMN daily report: code $?" | mail -s "BMN daily report" vous@exemple.com
```

## Windows : Planificateur de tâches

1. Ouvrir le Planificateur de tâches > Créer une tâche.
2. Déclencheur : quotidien, 07:30.
3. Action : Démarrer un programme
   - Programme : `C:\chemin\vers\BMN\scheduling\run_daily.bat`
   - Commencer dans : `C:\chemin\vers\BMN`
4. Paramètres : cocher « Exécuter même si l'utilisateur n'est pas connecté ».

Le code de sortie est visible dans l'historique de la tâche (« Dernier résultat »).

## Alternative : PowerShell (création de la tâche en ligne de commande)

```powershell
$action = New-ScheduledTaskAction -Execute "C:\chemin\vers\BMN\scheduling\run_daily.bat" -WorkingDirectory "C:\chemin\vers\BMN"
$trigger = New-ScheduledTaskTrigger -Daily -At 07:30
Register-ScheduledTask -TaskName "BMN daily report" -Action $action -Trigger $trigger
```

## À décider plus tard
- Heure d'exécution et fenêtre de retry si le CSV arrive en retard.
- Canal d'alerte (mail, Teams, ...) sur codes 1 et 2.
- Récupération automatique du CSV depuis la boîte mail (v1).
