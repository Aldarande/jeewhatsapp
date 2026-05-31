# Exemples de scénarios — JeeWhatsApp

> Ce guide montre comment intégrer JeeWhatsApp dans vos automatisations Jeedom.

---

## Exemple 1 : Alerte intrusion

Envoyer un message WhatsApp automatiquement quand un capteur de mouvement se déclenche.

**Prérequis :** équipement JeeWhatsApp connecté, groupe canal configuré.

**Configuration du scénario dans Jeedom :**

```
Nom : Alerte mouvement salon
Mode : Provoqué
Déclencheur : [Maison][Capteur mouvement salon][Présence] == 1

Bloc Action :
  - Commande : [JeeWhatsApp][Mon WhatsApp][send_message]
    Message   : "Alerte : mouvement détecté dans le salon à #time#"
    Titre     : (laisser vide pour envoyer dans le groupe canal)
```

**Résultat dans WhatsApp :**
```
🏠 Alerte : mouvement détecté dans le salon à 14:32
```

---

## Exemple 2 : Rapport quotidien des températures

Envoyer chaque matin un récapitulatif de toutes les températures de la maison.

```
Nom : Rapport température matinal
Mode : Programmé
Programmation : 0 7 * * *  (tous les jours à 7h00)

Bloc Action :
  - Variable : msg = "Bonjour ! Températures du matin :\n"
  - Variable : msg = msg + "Salon : " + #[Maison][Thermostat salon][Température]# + "°C\n"
  - Variable : msg = msg + "Chambre : " + #[Maison][Capteur chambre][Température]# + "°C\n"
  - Variable : msg = msg + "Extérieur : " + #[Météo][Station][Température extérieure]# + "°C"

  - Commande : [JeeWhatsApp][Mon WhatsApp][send_message]
    Message   : #msg#
```

---

## Exemple 3 : Contrôle de la domotique par message WhatsApp

Permettre à des membres de la famille de contrôler Jeedom par message.

**Configuration de l'équipement :**

| Paramètre | Valeur |
|-----------|--------|
| Interactions activées | Oui |
| Mot-clé | `/jeedom` |
| Whitelist | `33612345678` (numéro autorisé, sans +) |

**Interactions Jeedom à créer :**
```
Question : allume le salon
Réponse  : Le salon est allumé.
Action   : [Maison][Lumière salon][On]

Question : éteins le salon
Réponse  : Le salon est éteint.
Action   : [Maison][Lumière salon][Off]

Question : quelle est la température du salon ?
Réponse  : La température du salon est de #[Maison][Thermostat][Température]# degrés.
```

**Utilisation depuis WhatsApp :**
```
Utilisateur → "/jeedom allume le salon"
Jeedom      → "🏠 Le salon est allumé."

Utilisateur → "/jeedom quelle est la température du salon ?"
Jeedom      → "🏠 La température du salon est de 21 degrés."
```

---

## Exemple 4 : Raccourcis slash

Les raccourcis permettent des commandes directes sans passer par InteractQuery (plus rapide).

**Configuration (champ `Raccourcis` de l'équipement) :**

```
/temp = La température est de #1234# degrés
/alarme = #5678#
/lumière = #9012#
/bonjour = Bonjour #user# ! La maison vous attend.
```

> Remplacez `#1234#` par l'ID réel de la commande info (visible dans la gestion des commandes).

**Utilisation depuis WhatsApp :**
```
/temp          → "🏠 La température est de 21.5 degrés"
/alarme        → exécute la commande d'action #5678#, répond "✅ Armer alarme"
/bonjour       → "🏠 Bonjour ! La maison vous attend."
```

**Raccourci avec argument :**
```
/lumière = #1234#
```
```
/lumière 80    → exécute la commande slider #1234# avec la valeur 80
```

---

## Exemple 5 : Réponse vocale (TTS + STT)

Permettre des échanges vocaux complets avec Jeedom via WhatsApp.

**Prérequis :** Piper (TTS) et Vosk (STT) installés.

**Configuration de l'équipement :**

| Paramètre | Valeur |
|-----------|--------|
| TTS activé | Oui |
| STT activé | Oui |
| Interactions activées | Oui |

**Flux complet :**
```
1. L'utilisateur envoie une note vocale : "Quelle est la température ?"
2. Vosk transcrit : "quelle est la temperature"
3. InteractQuery cherche une correspondance dans les interactions Jeedom
4. Réponse trouvée : "La température est de 21 degrés"
5. Piper synthétise la réponse en note vocale
6. JeeWhatsApp envoie la note vocale dans le groupe
```

---

## Exemple 6 : Envoi d'une image (snapshot caméra)

Envoyer automatiquement une capture de caméra quand le portail s'ouvre.

**Prérequis :** plugin caméra Jeedom configuré.

```
Déclencheur : [Portail][Capteur ouverture][Ouvert] == 1

Bloc Action :
  - Bloc Code PHP :
    // Capturer le snapshot
    $cam = eqLogic::byLogicalId('ma_camera', 'camera');
    $snapshot = $cam->getSnapshot();
    file_put_contents('/tmp/portail_snap.jpg', $snapshot);

  - Commande : [JeeWhatsApp][Mon WhatsApp][send_media]
    Message   : "/tmp/portail_snap.jpg"
    Titre     : (caption optionnelle)
```

---

## Exemple 7 : Sondage dans le groupe

Créer un sondage WhatsApp pour décider de l'heure du dîner.

```
Bloc Action :
  - Commande : [JeeWhatsApp][Mon WhatsApp][send_poll]
    Message   : "Dîner|18h00|19h00|20h00|21h00"
```

**Format du message :** `Question|Option1|Option2|...` (2 à 12 options)

---

## Exemple 8 : Envoi d'une localisation

Partager la position du serveur Jeedom (ou d'un enfant via scénario).

```
Bloc Action :
  - Commande : [JeeWhatsApp][Mon WhatsApp][send_location]
    Message   : "48.8566|2.3522|Paris, Tour Eiffel"
```

**Format du message :** `latitude|longitude|nom_optionnel`

---

## Exemple 9 : Rapport hebdomadaire avec graphique

Envoyer chaque lundi matin un récapitulatif de la semaine.

```
Nom : Rapport hebdomadaire
Programmation : 0 8 * * 1  (chaque lundi à 8h)

Bloc Action :
  - Variable : total_reçus = #[JeeWhatsApp][Mon WhatsApp][messages_today]#

  - Commande : [JeeWhatsApp][Mon WhatsApp][send_message]
    Message   : "Rapport semaine :\n- Messages reçus cette semaine : #total_reçus#\n- Dernier expéditeur : #[JeeWhatsApp][Mon WhatsApp][last_sender_name]#"
```

---

## Exemple 10 : Gestion des membres du groupe

Ajouter automatiquement un invité au groupe lors d'un événement.

```
Déclencheur : [Événements][Soirée][Statut] == "confirmé"

Bloc Action :
  - Commande : [JeeWhatsApp][Mon WhatsApp][group_action]
    Message   : "add|33698765432"
```

**Opérations disponibles :**

| Commande | Valeur | Effet |
|----------|--------|-------|
| `add` | numéro | Ajouter un participant |
| `remove` | numéro | Retirer un participant |
| `promote` | numéro | Promouvoir en admin |
| `demote` | numéro | Rétrograder admin |
| `subject` | texte | Changer le nom du groupe |
| `description` | texte | Changer la description |

---

## Conseils

- **Testez toujours** en mode test (onglet Test de l'équipement) avant de déployer un scénario
- **La whitelist** est votre première ligne de défense : ne laissez pas les interactions ouvertes à tous
- **Le mot-clé** évite les déclenchements intempestifs : `/jeedom` ou `!jeedom` sont de bons choix
- **Les raccourcis** `/cmd` sont plus rapides et fiables que les interactions NLP pour des actions précises
- **Le TTS** consomme des ressources CPU — utilisez-le pour les réponses courtes, pas les longs textes
