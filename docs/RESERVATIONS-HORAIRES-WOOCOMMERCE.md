# Dendrila Calendar — horaires, prestations et WooCommerce

Complément du 26 novembre 2026. Le nom et le dossier du plugin restent **Dendrila Calendar** et `dendrila-calendar`.

## 1. Créer le profil qui reçoit les rendez-vous

Dans **Dendrila Calendar → Profils**, créez un profil par personne, salle ou autre ressource réservable. Un profil peut proposer plusieurs prestations : leurs occupations sont partagées.

Publiez le profil, activez ses disponibilités, puis réglez :

- **Fuseau horaire** : par exemple `Europe/Paris`. Les horaires sont interprétés dans ce fuseau ; les rendez-vous sont enregistrés en UTC.
- **Horaires hebdomadaires** : ajoutez plusieurs plages si nécessaire, par exemple 09:00–12:00 et 14:00–18:00. Cochez « Fermé » pour un jour sans rendez-vous. Une fin saisie à 00:00 signifie minuit à la fin de la journée.
- **Exceptions par date** : une fermeture complète ou des horaires différents remplacent les horaires habituels de cette date. Les exceptions priment sur la semaine type.
- **Limites communes** : nombre maximal de rendez-vous par jour, semaine ou mois, toutes prestations du profil réunies. Zéro signifie sans plafond. La semaine commence le lundi, dans le fuseau du profil.
- **Agendas connectés** : le contrôle porte sur tous les agendas Google/Outlook connectés au site. S’il est activé et qu’un fournisseur ne peut pas être contrôlé, les créneaux ne sont pas annoncés comme libres.

Les plages qui se touchent sont fusionnées ; les plages qui se chevauchent sont refusées. Une erreur de saisie conserve les anciens réglages et affiche un message dans l’administration. Les profils nouvellement créés restent fermés jusqu’à leur configuration explicite.

## 2. Créer une prestation avec WooCommerce

Pour une prestation associée à un produit, utilisez **Produits → Ajouter** ou le bouton correspondant dans **Dendrila Calendar → Prestations**.

1. Renseignez le **nom**, la **description** et le **prix** dans les champs habituels du produit WooCommerce.
2. Choisissez un **produit simple**, puis ouvrez son onglet **Prise de rendez-vous**.
3. Activez l’utilisation de ce produit comme prestation.
4. Sélectionnez son **profil responsable**, sa **durée** et ses temps de **préparation avant** et de **récupération après**.
5. Réglez les règles de réservation ci-dessous, puis activez la réservation de la prestation et publiez le produit.

Le produit devient virtuel. Dendrila crée et maintient la prestation liée automatiquement : il ne faut pas ressaisir son nom, son texte ou son prix dans un second formulaire. Le lien d’édition de cette prestation ramène au produit WooCommerce.

Un produit en brouillon, non achetable, physique, variable ou en rupture ne permet pas de réserver via ce parcours. Les stocks et prix WooCommerce restent applicables. Le premier lot prend en charge les **produits simples virtuels**, y compris à prix nul.

Sur la fiche produit, le formulaire de choix du créneau remplace l’ajout au panier habituel. Les boutons du catalogue conduisent à la fiche. Le serveur refuse également un ajout direct au panier sans horaire, y compris via la Store API : on ne peut pas acheter une prestation et obtenir une réservation sans avoir choisi un créneau.

Pour une prestation gratuite sans produit, l’écran **Dendrila Calendar → Prestations** reste disponible. Elle exige elle aussi un profil publié, une durée et des règles activées. Un ancien prix en centimes supérieur à zéro sans produit WooCommerce valide garde la réservation fermée ; il ne provoque aucun encaissement.

## 3. Régler quand une prestation peut être réservée

| Réglage | Effet |
| --- | --- |
| Durée | Durée réelle du rendez-vous, de 5 minutes à 24 heures |
| Préparation / récupération | Temps occupé avant et après le rendez-vous |
| Préavis minimal | Temps minimal entre maintenant et le début du rendez-vous |
| Horizon en jours | Nombre de jours réservables à partir d’aujourd’hui ; zéro enlève cette limite glissante |
| Première / dernière date | Bornes facultatives, qui restreignent l’horizon |
| Intervalle entre les débuts | Espacement des horaires proposés, indépendant de la durée |
| Plafonds jour / semaine / mois | Limites de cette prestation, en complément des limites communes du profil |

Exemple : une prestation de 45 minutes avec un intervalle de 15 minutes peut proposer 09:00, 09:15, 09:30… sous réserve des autres règles.

Le rendez-vous doit tenir intégralement dans une plage ouverte. Ses marges peuvent dépasser les horaires de travail, mais elles doivent rester libres. Une marge après un rendez-vous et une marge avant le suivant s’additionnent. Les marges sont enregistrées sur la réservation : changer celles de la prestation ne modifie pas les réservations existantes.

Les changements d’heure sont pris en compte : les heures inexistantes au printemps ne sont pas proposées ; les deux occurrences d’une heure répétée en automne sont distinguées par leur décalage UTC. Le formulaire indique le fuseau du profil et l’équivalent dans celui du navigateur.

Les réservations confirmées, les réservations temporairement retenues pour paiement et les paiements manuels en attente consomment les quotas. Un rendez-vous annulé, remboursé intégralement ou une retenue expirée libère la place.

## 4. Afficher le formulaire

La fiche d’un produit associé affiche directement le formulaire. Pour une autre page WordPress, utilisez un bloc **Code court** :

```text
[dendrila_booking]
```

Le visiteur choisira parmi les prestations ouvertes. Pour une prestation précise, copiez le code fourni dans son écran :

```text
[dendrila_booking service="123"]
```

Le visiteur choisit la date et l’heure, renseigne son nom et son e-mail et accepte l’information de confidentialité. Il n’a pas besoin de compte WordPress. Les horaires sont revérifiés au moment de confirmer ; une liste affichée quelques secondes auparavant ne garantit pas qu’un créneau est encore libre.

Une prestation gratuite est confirmée sur la page avec une référence. Le visiteur peut télécharger un fichier calendrier contenant la prestation et l’horaire, sans coordonnées personnelles. Le téléchargement est un ajout ponctuel, distinct de la synchronisation des agendas du site. L’annulation depuis la confirmation utilise un jeton privé transmis par POST.

## 5. Paiement et états WooCommerce

Pour une prestation liée à un produit, Dendrila retient le créneau pendant **20 minutes au maximum**, puis crée une commande WooCommerce et fournit son lien officiel de paiement. La retenue se termine plus tôt si le rendez-vous commence avant ces 20 minutes.

Le prix et les taxes sont calculés par WooCommerce. Une nouvelle tentative réutilise la même commande et son prix enregistré ; elle n’ajoute pas une deuxième commande. Un produit à montant nul crée également une commande WooCommerce réelle.

| État de la commande | État de la réservation |
| --- | --- |
| Paiement en attente | Créneau retenu temporairement, rendez-vous non confirmé |
| Paiement réussi, commande en traitement ou terminée | Rendez-vous confirmé |
| En attente d’une vérification manuelle (`on-hold`) | Créneau conservé jusqu’à la décision sur la commande |
| Échec ou annulation | Créneau libéré |
| Remboursement partiel | Rendez-vous conservé |
| Remboursement total | Créneau libéré |
| Paiement tardif après libération | Nouvelle vérification ; si conflit ou annulation, aucune fausse confirmation et note de traitement sur la commande |

L’abandon d’un paiement en attente expire automatiquement. Même si WP-Cron intervient avec retard, le moteur ne compte plus une retenue expirée comme une réservation active. Le formulaire de paiement WooCommerce refuse un créneau déjà expiré avant d’appeler une passerelle.

Le paiement utilise la page officielle **payer cette commande** de WooCommerce. Il ne dépend pas du bloc Panier/Commande pour créer la réservation. Les passerelles de paiement doivent être configurées dans WooCommerce ; les tests locaux des commandes ne remplacent pas un essai de la passerelle choisie en mode test.

Un administrateur peut annuler une réservation dans **Dendrila Calendar → Réservations** et ouvrir sa commande associée. Une annulation ne rembourse pas automatiquement une commande payée : le remboursement reste une action explicite dans WooCommerce. Un client ayant déjà payé reçoit l’instruction de contacter l’organisateur.

## 6. Calendrier et synchronisation

Les réservations apparaissent dans le calendrier unifié sous la source **Réservations Dendrila**. Les connexions Google/Outlook déjà choisies pour les événements Dendrila reçoivent aussi les réservations confirmées. Pour CalDAV ou un abonnement privé, sélectionnez explicitement cette source.

Les exports contiennent le nom de la prestation et l’horaire, pas le nom ou l’adresse e-mail du client. Les connexions gardent leur mode enregistré lors d’une mise à jour. Un nouvel agenda propose le double sens par défaut ; un agenda connu reprend son réglage précédent.

Le **double sens concerne les événements locaux ponctuels**. Une modification dans Google/Outlook ne déplace pas silencieusement un rendez-vous client et ne modifie pas son paiement WooCommerce. Les rendez-vous provenant d’autres extensions restent sous la responsabilité de ces extensions. Voir [Connexion Google et Outlook](UTILISATION-GOOGLE-OUTLOOK.md).

## 7. Portée vérifiée

Le moteur, l’administration, le formulaire et les transitions de paiement disposent de tests automatisés. Les scénarios de commande et de concurrence sont également exécutés dans un véritable WordPress avec WooCommerce, HPOS et MariaDB, sans requête à un compte de calendrier ni passerelle bancaire réelle.

Le verrouillage natif exige MySQL/MariaDB avec verrous SQL par connexion. Si le serveur refuse un verrou ou si la connexion SQL change pendant une opération, Dendrila refuse temporairement la réservation plutôt que de confirmer sans protection. Une requête ne traite qu’un jour de disponibilités ; les inventaires trop volumineux ou incomplets produisent une indisponibilité explicite.

La vérification Google/Outlook aller-retour et celle d’une passerelle WooCommerce réelle restent des essais manuels. Elles sont distinctes des exports déjà relus chez les fournisseurs et des changements d’état de commande testés localement.

## Références examinées

- [FluentBooking : disponibilités partagées entre les événements d’un même hôte](https://fluentbooking.com/docs/availability-management/).
- [FluentBooking : code court et endpoints publics](https://developers.fluentbooking.com/platform/shortcodes).
- [FluentBooking 2.6.0 : ZIP officiel WordPress.org, GPLv2 ou ultérieure](https://downloads.wordpress.org/plugin/fluent-booking.2.6.0.zip). Le moteur de créneaux et la séquence de verrouillage/validation ont été examinés ; le code Dendrila est une implémentation adaptée à son architecture.
- [Calendly : réglages détaillés des disponibilités](https://calendly.com/help/how-to-fine-tune-your-availability-settings).
- [Calendly : marges avant/après et limites des horaires](https://calendly.com/help/how-to-use-buffers).
- [Calendly : plafonds de rendez-vous](https://calendly.com/help/how-to-set-meeting-limits).
- [WooCommerce : paiement d’une commande](https://woocommerce.com/document/managing-orders/paying-for-orders/).
- [WooCommerce : APIs CRUD et compatibilité HPOS](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/).
