# Dendrila Calendar 0.0.4 — validation du 26 novembre 2026

## État de livraison

Le lot a été publié sur `feat/native-bookings-profiles`, puis installé sur le laboratoire WordPress le 26 novembre 2026. La version installée conserve les connexions Google et Microsoft existantes. Deux événements de laboratoire ont été relus indépendamment chez chacun des fournisseurs : les quatre copies ont les titres et horaires attendus, identiques aux sources WordPress.

La version 0.0.4 conserve le mode des connexions Google/Microsoft existantes : la mise à jour ne transforme pas leurs exports en imports. Pour une nouvelle destination, le double sens est désormais proposé par défaut, avec une case visible et modifiable avant confirmation. Une réautorisation reprend le mode de la destination connue. Une connexion déjà enregistrée peut changer de mode sans refaire son autorisation OAuth.

Le bouton de confirmation devient « Connecter et synchroniser cet agenda ». Le test technique de création/suppression reste automatique. La configuration des identifiants OAuth appartient à l’administrateur du site ; une fois le fournisseur configuré, connecter un compte n’exige pas de saisir ces identifiants.

## Complément : disponibilités, réservations et produits WooCommerce

Le complément demandé après la première installation ajoute un moteur natif de disponibilités, un formulaire public et le parcours produit WooCommerce. Il conserve les idées documentées de Calendly et s’appuie sur l’examen du ZIP GPL officiel FluentBooking 2.6.0 pour le découpage horaires/prestation et la vérification finale des créneaux. Le [guide opérateur](RESERVATIONS-HORAIRES-WOOCOMMERCE.md) décrit le parcours complet.

Validation locale du complément :

| Vérification | Résultat |
| --- | --- |
| 19 suites PHP sans WordPress complet | Réussies |
| Syntaxe de 44 fichiers PHP, 5 scripts JS | Réussie |
| Moteur horaires : 59 contrôles | Réussis : horaires, exceptions, DST, préavis, horizon, quotas, marges, occupations, erreurs fournisseurs |
| Administration : 41 contrôles | Réussis : nonces, droits, saisies invalides, sauvegarde conservatrice et réutilisation dans le produit |
| Formulaire public : test DOM | Réussi : fuseau, erreurs, réponses anciennes, double clic, retry, paiement, annulation, ICS |
| Administration : HTML PHP + JS + sauvegarde PHP | Réussi, y compris sans JavaScript |
| 515 chaînes françaises | Toutes les clés PHP couvertes ; paramètres de formatage préservés |
| WordPress réel + WooCommerce + HPOS : 38 contrôles | Réussis : prix, commande, retenue, états, remboursements, paiement tardif et garde avant passerelle |
| Cache des commandes HPOS : 16 contrôles | 16/16 réussis dans le laboratoire local avec deux processus PHP, caches d’objets et de datastore préalablement remplis |
| Concurrence MariaDB réelle : 10 contrôles | Réussis avec deux processus PHP et deux connexions SQL |
| Produit → prestation → commande : 51 contrôles réels | Réussis, y compris les gardes panier classique et Store API |

L’intégration utilise PHP 8.4.27, WordPress 7.1.5, WooCommerce 11.3.1 et MariaDB 11.8.9 avec HPOS. Les courriels et requêtes HTTP externes sont bloqués dans cette installation isolée. Les tests de paiement exécutent les vrais objets et transitions WooCommerce, sans débit bancaire ni passerelle réelle.

Le test de concurrence a d’abord reproduit deux confirmations lorsque les deux processus avaient déjà chargé les disponibilités. La correction renouvelle les caches de requête et métadonnées après acquisition du verrou ; le même scénario ne permet désormais qu’un succès. Les essais couvrent aussi le dernier quota disponible, des profils indépendants et une clé de requête simultanément réutilisée entre profils.

Le test [test-woo-order-cache.php](../tests/test-woo-order-cache.php) complète cette validation locale par 16 contrôles sur les commandes HPOS entre deux processus PHP. Il exerce les caches d’objets et de datastore afin de vérifier la cohérence des lectures de commandes entre les processus. Son invocation et les conditions du laboratoire sont décrites dans [TESTING-NATIVE-BOOKINGS.md](TESTING-NATIVE-BOOKINGS.md).

Le paquet final a ensuite été installé sur `wordpress.lepotager.org`. WordPress a confirmé sa mise à jour. La revue réelle de l’interface a vérifié l’onglet de réservation WooCommerce, la sauvegarde des horaires du profil, l’ajout de plages et d’exceptions, la création automatique de la prestation à partir du produit et le formulaire rendu dans le thème Canal57. Une durée initiale incorrecte de zéro minute sur un nouveau produit a été corrigée à 30 minutes, puis la correction a été réinstallée et vérifiée.

Un produit temporaire masqué du catalogue à 15 €, rattaché à un profil Europe/Paris ouvert le vendredi de 09:00 à 17:00, a affiché les 31 débuts possibles jusqu’à 16:30 pour une prestation de 30 minutes. Le choix de 09:00 a affiché le récapitulatif, les coordonnées, le consentement et le bouton « Réserver et passer au paiement ». Aucune réservation, commande, invitation ou transaction n’a été créée dans ce contrôle distant. Le produit, son profil et sa prestation ont été placés dans la corbeille après vérification.

Ce contrôle visuel est un contrôle de bureau, distinct du contrôle mobile historique ci-dessous. Aucune erreur JavaScript provenant de Dendrila n’a été relevée sur ce formulaire. Le plugin WooCommerce PayPal Payments du laboratoire a cependant émis « Failed to generate client token » : sa passerelle doit être vérifiée dans sa configuration de test avant de déclarer un paiement PayPal réel validé. Les scénarios de commandes locaux ne constituent pas cette validation fournisseur.

Le ZIP installé contient 31 fichiers et mesure 173 125 octets. Son SHA-256 est `0ec682d2e0ab03c173dd0906bb2ecaad254d094ccbece9d94435bf5258e288ad`. Il exclut les tests, les données du laboratoire et les secrets. L’état de publication est enregistré dans le rapport global et dans le commit livré.

## Lot initial : changements vérifiés

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

## Lot initial : résultats

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

L’export réel est confirmé par la lecture des quatre objets fournisseurs décrite ci-dessus. L’aller-retour bidirectionnel complet reste une validation manuelle, conformément au choix de l’utilisateur. Le [guide d’utilisation](UTILISATION-GOOGLE-OUTLOOK.md) décrit un essai court sur un agenda dédié. Il ne faut pas présenter la lecture des exports existants comme une preuve de l’import ou de la modification dans l’autre sens.

Un service OAuth exploité par l’éditeur concerne la distribution grand public sans configuration d’application par chaque site. Il n’est pas un préalable supplémentaire pour le site de test qui dispose déjà de ses propres connexions OAuth.

## Reproduction des contrôles du dépôt

Voir [TESTING-NATIVE-BOOKINGS.md](TESTING-NATIVE-BOOKINGS.md). Les suites d’intégration refusent une base qui n’est pas explicitement locale ou qui autorise les appels externes ; elles ne doivent pas être lancées contre le site public.

## Références techniques

- [Google Calendar : modifications conditionnelles et ETag](https://developers.google.com/workspace/calendar/api/guides/version-resources).
- [Google Calendar : fusion des champs et effacement par null lors d’un PATCH](https://developers.google.com/workspace/calendar/api/guides/performance).
- [Microsoft Graph : objet événement, all-day et transactionId](https://learn.microsoft.com/en-us/graph/api/resources/event?view=graph-rest-1.0).
- [Microsoft Graph : lecture d’une vue calendrier](https://learn.microsoft.com/en-us/graph/api/calendar-list-calendarview?view=graph-rest-1.0).
