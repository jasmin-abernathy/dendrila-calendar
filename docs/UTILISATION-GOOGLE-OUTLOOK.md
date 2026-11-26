# Connecter Google ou Outlook à Dendrila Calendar

Ce parcours s’utilise dans l’administration WordPress, avec un compte administrateur du site. Une fois Google et Microsoft configurés par le responsable du site, connecter son agenda ne demande aucune clé technique.

## Nouvelle connexion

1. Ouvrir **Dendrila Calendar → Synchronisation**, puis choisir **Google Calendar** ou **Microsoft Outlook**.
2. Cliquer sur **Se connecter avec…**, choisir son compte chez le fournisseur et autoriser l’accès.
3. De retour dans WordPress, choisir l’agenda. **Synchroniser les événements locaux dans les deux sens** est proposé pour une nouvelle destination ; le mode précédent est repris pour un agenda déjà connu.
4. Vérifier les sources choisies et cliquer sur **Connecter et synchroniser cet agenda**. La vérification technique de la connexion est automatique.

Les titres, dates, horaires et journées entières des événements Dendrila ponctuels se synchronisent entre WordPress et l’agenda. La carte de connexion affiche son mode, sa dernière synchronisation et les éventuelles erreurs. Le bouton **Synchroniser maintenant** permet d’appliquer les changements immédiatement ; la tâche automatique est prévue environ toutes les cinq minutes, selon le déclenchement de WordPress Cron.

## Activer le double sens sur une connexion existante

Sur la carte de l’agenda, cocher **Synchroniser les événements locaux dans les deux sens**, puis cliquer sur **Enregistrer le sens de synchronisation**. Cette action lance une synchronisation et ne demande pas une nouvelle connexion au compte.

Le double sens traite tous les événements ponctuels compatibles de l’agenda dans sa fenêtre de découverte, de sept jours passés à 85 jours futurs. Une suppression se propage à l’autre copie ; une suppression distante place la copie WordPress inchangée dans la Corbeille. Les changements simultanés sont signalés au lieu d’écraser une version.

## Ce qui reste distinct

- Les récurrences, invitations, participants et descriptions ne font pas partie du double sens de cette version.
- Les rendez-vous issus d’Easy Appointments, The Events Calendar et des autres extensions restent exportés uniquement. Dendrila ne modifie pas leurs réservations ou paiements.
- Les événements importés dans WordPress restent privés et liés à leur connexion d’origine ; ils ne sont pas exportés vers les autres agendas connectés.
- Sur un nouveau site sans configuration OAuth, le responsable du site doit préparer l’intégration une fois. Le panneau **Configurer les identifiants de l’application (administration)** sert à cette étape ; les clés ne sont pas demandées à chaque connexion de compte. Le détail figure dans [OAUTH-SETUP.md](OAUTH-SETUP.md).

## Essai manuel court

Utiliser un agenda dédié et un événement fictif sans participants ni rappel, daté d’hier. Pour cette connexion d’essai, sélectionner seulement la source **Événements Dendrila** et vérifier qu’aucun autre événement natif non importé n’est à exporter.

1. Créer l’événement dans Google ou Outlook, puis connecter cet agenda en double sens. Vérifier son apparition dans **Gérer les événements locaux** de WordPress.
2. Modifier le titre et les horaires de **ce même événement importé** dans WordPress, enregistrer, puis utiliser **Synchroniser maintenant**. Vérifier le changement chez le fournisseur.
3. Modifier ensuite le titre ou les horaires chez le fournisseur, synchroniser, puis vérifier la mise à jour du même événement WordPress.
4. Supprimer l’événement fictif chez le fournisseur, synchroniser et vérifier qu’il est placé dans la Corbeille WordPress.

Effectuer chaque vérification avant la modification suivante. Un seul événement importé suffit pour ce contrôle ; il n’est pas nécessaire de créer un nouvel événement WordPress susceptible d’être exporté vers d’autres connexions.


## Comprendre un échec du double sens

La carte de synchronisation affiche un code de diagnostic après une interruption :
- `HTTP_401` : le fournisseur refuse le jeton d'accès ; renouveler l'autorisation.
- `HTTP_403` : accès à cet agenda interdit ou autorisations insuffisantes.
- `HTTP_429` : limite de requêtes atteinte ; relancer plus tard.
- `HTTP_400` : la requête de lecture a été refusée.
- `HTTP_500`, `HTTP_502`, `HTTP_503`, `HTTP_504` : erreur temporaire du fournisseur.
- `PAGINATION_LIMIT` ou `PAGINATION_FORMAT` : lecture des pages incomplète ; aucune suppression n'est déduite d'un inventaire partiel.
- `RESPONSE_FORMAT` : la réponse du fournisseur ne contient pas la liste d'événements attendue.
- `CONNECTION_CHANGED` : la connexion a été modifiée pendant la synchronisation.
- `NETWORK`, `HTTP_OTHER` ou `INTERNAL` : erreur nécessitant une inspection technique plus approfondie.

Le code est stocké sans URL d'API, titre d'événement, identifiant d'agenda ni jeton OAuth. Un export qui fonctionne alors que le double sens échoue oriente le diagnostic vers la lecture distante ou l'importation ; cela n'implique pas nécessairement une erreur d'authentification.

Pour les événements horaires Google, Dendrila transmet l'horodatage avec son décalage local et le fuseau du site WordPress (`Europe/Paris` si configuré). Les heures sont toujours enregistrées en UTC en interne. L'affichage dans Google Calendar dépend aussi du fuseau choisi dans l'interface Google ; le modifier n'altère pas l'heure réelle de l'événement.
