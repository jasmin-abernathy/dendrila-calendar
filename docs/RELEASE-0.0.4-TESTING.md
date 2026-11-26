# Dendrila Calendar 0.0.4 — validation du 26 novembre 2026

## État de livraison

Le lot est préparé sur la base `36ecf997630851a21c4c721cb4fbe4685b2f8a77` de `feat/native-bookings-profiles`. Aucune publication ou modification du WordPress de production n’a été effectuée pendant cette validation.

La version 0.0.4 conserve les connexions Google/Microsoft existantes en **export uniquement**. Le double sens ne commence qu’après activation explicite de la case correspondante par un administrateur. Une connexion déjà enregistrée peut changer de mode sans refaire son autorisation OAuth. Les connexions réelles du site doivent être réutilisées et testées après installation ; la présence de l’option ne prouve pas à elle seule que le jeton actuel est valide.

## Changements vérifiés

- Connexion directe depuis le choix Google/Outlook si l’application OAuth du site est configurée.
- Double sens pour les événements locaux ponctuels : titre, début, fin et journée entière ; créations et modifications dans les deux sens.
- Suppression distante confirmée vers la Corbeille WordPress ; suppression locale vers l’agenda ; gestion de la restauration avec nouvelle identité distante.
- Conflits sur modifications simultanées et refus HTTP 412 sans écrasement des copies.
- Pagination complète avant modification, vérification par identifiant des événements absents de la fenêtre, identité stable lors des reprises.
- Conservation des liens lors d’une déconnexion/reconnexion ; historique sans jetons ni secrets. Regroupement des doublons seulement lorsque leurs liens sont compatibles.
- Verrou atomique commun aux modes export et double sens ; arrêt si la connexion est retirée pendant la lecture.
- Invitations, réunions et récurrences exclues ; les réservations des autres extensions restent en lecture seule et export uniquement.
- Imports privés exclus des flux d’abonnement et des exports vers d’autres agendas.
- Correction des décalages UTC, des journées entières lors du changement d’heure et des champs Google `date`/`dateTime` lors d’un PATCH.
- Dépassement de la limite de 500 événements signalé ; erreurs de source remontées.
- Versions, paquet, documentation et 310 traductions françaises cohérents.
- Boutons de synchronisation repliables sur mobile, avec correction d’un débordement réel du bouton d’abonnement iCalendar à 393 px.

## Résultats

| Vérification | Résultat |
| --- | --- |
| 17 scripts de tests PHP | Réussis |
| Analyse syntaxique des 32 fichiers PHP | Réussie avec PHP 8.4.27 |
| Nouveau moteur : 70 contrôles comportementaux Google/Microsoft | Réussis, HTTP fournisseurs simulé |
| OAuth : consentement simulé, PKCE, découverte, test d’écriture, tokens chiffrés | Réussi |
| OAuth : réautorisation, déconnexion/reconnexion et regroupement des liens | Réussi |
| Sources, abonnements, CalDAV, disponibilités Easy Appointments, fondation native | Suites existantes réussies |
| 310 traductions françaises comparées aux chaînes PHP | Réussi |
| Syntaxe des trois scripts JavaScript | Réussie |
| Test DOM du calendrier : mois, jour, semaine et liste | Réussi |
| `git diff --check` | Réussi |
| Activation réelle sur WordPress 7.1.5 isolé, base SQLite | Réussie |
| Flux réel WordPress, avec HTTP fournisseur simulé | Import/export, modification locale/distante, vraie Corbeille, exclusion des imports des flux, verrou export réussis |
| Navigateur Chromium, écran 393 × 852, français | Formulaires OAuth, mots de passe vides et masqués, contrôle journée entière, aucun débordement horizontal ni erreur JavaScript |

L’installation isolée utilisait PHP 8.4.27 et WordPress 7.1.5. Les requêtes fournisseur de son test étaient interceptées : aucun compte réel, invitation ni événement utilisateur n’a été modifié. Le test WordPress a exécuté sept requêtes HTTP simulées. Le contrôle mobile utilisait un utilisateur factice sur une installation locale distincte du laboratoire Simple Edit.

## Portée et vérifications restantes

La fenêtre de découverte est de **J−7 à J+85**. Les événements déjà reliés sont vérifiés hors fenêtre. L’export est plafonné à 500 événements sources par passage ; les événements locaux sont également plafonnés à 500 par fenêtre, avec avertissement. Un agenda volumineux peut donc conserver de nouveaux événements non exportés. Les listes distantes sont paginées jusqu’à 100 pages de 100 événements ; une liste incomplète interrompt le passage avant les modifications bidirectionnelles.

La cadence est d’environ cinq minutes via WP-Cron, dépendant du trafic ou d’un cron serveur. Il ne s’agit pas d’une synchronisation instantanée. Les récurrences, invitations, pièces jointes, descriptions, participants et paiements ne sont pas synchronisés en double sens. Les réservations des extensions tierces ne sont jamais modifiées par ce mécanisme.

Un essai sur les agendas Google et Microsoft réellement connectés reste nécessaire après installation autorisée : validité/renouvellement des jetons, création d’un événement d’essai dans chaque sens, modification, suppression, conflit et reconnexion. Utiliser un agenda de test pour cette dernière étape. Aucune nécessité de recréer l’application ou de refaire le consentement ne peut être déduite des seuls tests simulés.

Un service OAuth exploité par l’éditeur concerne la distribution grand public sans configuration d’application par chaque site. Il n’est pas un préalable supplémentaire pour le site de test qui dispose déjà de ses propres connexions OAuth.

## Reproduction des contrôles du dépôt

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*.php; do php "$test" || exit 1; done
node --check assets/calendar.js
node --check assets/calendar-subscriptions.js
node --check assets/calendar-connect.js
node tests/test-calendar-init.js
git diff --check
```

## Références techniques

- [Google Calendar : modifications conditionnelles et ETag](https://developers.google.com/workspace/calendar/api/guides/version-resources).
- [Google Calendar : fusion des champs et effacement par null lors d’un PATCH](https://developers.google.com/workspace/calendar/api/guides/performance).
- [Microsoft Graph : objet événement, all-day et transactionId](https://learn.microsoft.com/en-us/graph/api/resources/event?view=graph-rest-1.0).
- [Microsoft Graph : lecture d’une vue calendrier](https://learn.microsoft.com/en-us/graph/api/calendar-list-calendarview?view=graph-rest-1.0).
