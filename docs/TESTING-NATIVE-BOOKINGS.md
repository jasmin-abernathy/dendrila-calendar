# Tester les réservations natives

Exécuter les commandes depuis la racine du dépôt. Les contrôles sans WordPress font partie du workflow [Static checks](../.github/workflows/checks.yml). Les essais avec WordPress et WooCommerce utilisent un laboratoire préparé séparément ; le workflow ne l’installe pas.

## Contrôles rapides, sans base de données

Prérequis : PHP CLI avec DOM/XML, Node.js 18 ou plus. Le workflow configure PHP 8.4 et l’extension `dom` avec [setup-php](https://github.com/shivammathur/setup-php). Aucun paquet npm ni navigateur n’est nécessaire.

```sh
php tests/test-native-bookings.php
php tests/test-native-schedule.php
php tests/test-schedule-admin.php
php tests/test-translation.php
node --check assets/calendar-booking.js
node --check assets/calendar-schedule-admin.js
node tests/test-booking-form.js
node tests/test-schedule-admin.cjs
```

Si PHP n’est pas dans le `PATH`, utiliser son chemin pour les commandes PHP et le passer aussi au test DOM :

```sh
PHP_BINARY=/chemin/vers/php node tests/test-schedule-admin.cjs
```

Le moteur est testé avec des données et réponses fournisseurs simulées : plages horaires, exceptions, préavis, dates limites, changements d’heure, quotas, retenues et temps de préparation/récupération. Le test d’administration vérifie les autorisations, les nonces et la conservation des réglages en cas d’erreur. Le test DOM exécute le véritable JavaScript sur le HTML produit par PHP, puis soumet les champs au code PHP de sauvegarde. Il couvre aussi le formulaire sans JavaScript ; il ne remplace pas une revue visuelle dans WordPress.

## Intégration WordPress, WooCommerce HPOS et MariaDB

Le point d’entrée commun est [booking-lab-bootstrap.php](../tests/booking-lab-bootstrap.php). Il charge un WordPress existant à partir de `DENDRILA_BOOKING_WP_ROOT`, puis les classes du checkout testé. Préparer les conditions suivantes :

- Une installation WordPress locale jetable et sa base MariaDB dédiée. Le nom de base doit se terminer par `_lab`.
- `WP_ENVIRONMENT_TYPE` défini à `local` et `WP_HTTP_BLOCK_EXTERNAL` défini à `true`, sans exception autorisant des services externes. Le bootstrap refuse de continuer si ces trois conditions ne sont pas satisfaites.
- WooCommerce actif, ses tables HPOS créées et le stockage HPOS activé. Le test WooCommerce vérifie explicitement cette activation et la présence de ses commandes dans la table HPOS.
- Un utilisateur administrateur d’identifiant `1`. Conserver Dendrila Calendar inactif dans ce laboratoire : le bootstrap charge directement les classes du checkout pour éviter de mélanger deux copies du plugin.
- PHP CLI avec `mysqli`, et `proc_open` disponible pour lancer les processus concurrents. Le répertoire temporaire PHP doit être accessible en écriture. Tous les processus doivent atteindre la même base MariaDB.

Les constantes attendues dans la configuration de ce laboratoire sont, par exemple :

```php
define( 'DB_NAME', 'dendrila_booking_lab' );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
```

Une fois ce laboratoire prêt :

```sh
export DENDRILA_BOOKING_WP_ROOT='/chemin/absolu/vers/wordpress'
export DENDRILA_BOOKING_PHP='/chemin/vers/php'
"$DENDRILA_BOOKING_PHP" tests/test-woo-bookings.php
"$DENDRILA_BOOKING_PHP" tests/test-woo-order-cache.php
"$DENDRILA_BOOKING_PHP" tests/test-booking-concurrency.php
"$DENDRILA_BOOKING_PHP" tests/integration-woo-service.php
```

`DENDRILA_BOOKING_PHP` indique le même exécutable PHP aux processus enfants du test de concurrence. Sans cette variable, le test utilise `PHP_BINARY`. Si l’exécutable passe par un lanceur qui configure ses bibliothèques ou extensions, fournir le chemin de ce lanceur.

Les scripts créent de véritables profils, prestations, réservations, produits et commandes dans cette base, puis les nettoient dans un bloc `finally`. Le bootstrap neutralise `wp_mail` et les profils de test désactivent les appels aux agendas externes. Le test de paiement utilise les méthodes locales WooCommerce et des remboursements sans appel de passerelle ; aucune transaction bancaire ni aucun email réel n’est attendu.

| Script | Ce qu’il vérifie réellement |
| --- | --- |
| [test-woo-bookings.php](../tests/test-woo-bookings.php) | Commande HPOS, prix WooCommerce, retenue avant paiement, réexécution sans doublon, confirmation et annulation, remboursement partiel/complet, paiement tardif, produit indisponible et confidentialité du reçu |
| [test-woo-order-cache.php](../tests/test-woo-order-cache.php) | Cohérence des lectures de commandes HPOS entre deux processus PHP, avec caches d’objets et de datastore préalablement remplis ; 16/16 contrôles réussis dans le laboratoire local |
| [integration-woo-service.php](../tests/integration-woo-service.php) | Produit Woo vers prestation, champs et nonces, publication, tarif unique, formulaire produit classique/blocs, refus panier classique et Store API sans créneau |
| [test-booking-concurrency.php](../tests/test-booking-concurrency.php) | Deux processus PHP et connexions MariaDB simultanés : conflit sur un même profil, quota partagé, profils indépendants, idempotence, délai du verrou, libération après exception et relecture malgré un cache préalablement rempli |

Dans un environnement qui isole le réseau à chaque commande, démarrer MariaDB et les scripts PHP depuis le même lanceur parent. Une base démarrée lors d’une commande précédente peut sinon être inaccessible, même sur `127.0.0.1`. Ces essais ne démarrent pas de serveur web et ne prouvent pas le rendu du formulaire dans un navigateur, un paiement chez une passerelle ou un aller-retour réel Google/Outlook ; ces vérifications se consignent séparément.
