# Dalfred — Assistant IA pour Dolibarr

---

## Arrêtez de naviguer dans les menus. Parlez à votre ERP.

Dolibarr est un ERP puissant. Mais entre les dizaines de menus, les centaines d'écrans et les filtres à configurer, vos équipes passent souvent plus de temps à **chercher l'information** qu'à **l'exploiter**.

Dalfred change la donne : posez vos questions, donnez vos instructions, et votre ERP s'exécute. **En langage naturel, directement depuis Dolibarr.**

---

## Ce que Dalfred fait pour vous

### 💬 Interrogez vos données instantanément

> *« Quel est le chiffre d'affaires du mois dernier ? »*
> *« Montre-moi les factures impayées de plus de 30 jours »*
> *« Combien de commandes pour le client Dupont cette année ? »*

Dalfred interroge votre base Dolibarr en temps réel et vous répond immédiatement — en texte, en tableau ou en graphique. Fini les exports manuels et les filtres à configurer.

### 📄 Créez vos documents en une phrase

> *« Crée une facture pour le client Martin, 5 heures de consulting à 120€ »*
> *« Ajoute une ligne de 200 pièces réf. ABC-123 à la commande en cours »*

Factures, devis, commandes, fiches tiers, produits — Dalfred les crée directement dans Dolibarr. Plus besoin de naviguer d'écran en écran pour saisir vos données.

### 📊 Générez des rapports à la demande

> *« Top 10 des clients par chiffre d'affaires cette année »*
> *« Évolution des ventes mois par mois depuis janvier »*
> *« Liste des produits dont le stock est inférieur à 5 »*

Obtenez vos KPI en quelques secondes. Exportez les résultats en CSV d'un clic pour les intégrer dans vos propres rapports.

### 🔖 Sauvegardez vos analyses avec les Smart Queries

Vous posez souvent les mêmes questions ? Dalfred peut sauvegarder vos requêtes favorites et les réexécuter en un clic — avec des paramètres dynamiques (période, client, commercial...).

Partagez-les avec vos collègues pour que toute l'équipe en profite.

### 🧠 Une mémoire qui travaille pour vous

Dalfred retient ce que vous lui confiez et le réutilise au bon moment :

- Procédures internes, préférences de facturation, notes sur vos clients
- Mémoire privée par utilisateur ou partagée avec l'équipe
- L'assistant consulte sa mémoire automatiquement avant de répondre

> *« Retiens que notre marge cible est de 35% sur le consulting »*
> *« Le client Martin veut toujours un paiement à 30 jours fin de mois »*

### 🌍 Multilingue et contextuel

Dalfred s'adapte à la langue de chaque utilisateur (français, anglais, bulgare) et sait sur quelle page vous vous trouvez. Il adapte ses réponses en conséquence et peut vous orienter directement vers la bonne page Dolibarr.

---

## Accessible partout dans Dolibarr

Dalfred est toujours à portée de main grâce à son **widget flottant** disponible sur toutes les pages, ou en **mode plein écran** pour les échanges plus poussés.

Le mode asynchrone vous permet de continuer à travailler dans Dolibarr pendant que l'assistant réfléchit — aucun blocage, même sur les requêtes complexes.

---

## Sécurité et confidentialité

- **Vos données restent les vôtres** — Dalfred communique directement entre votre serveur Dolibarr et le provider IA. Aucune donnée ne transite par nos serveurs.
- **Permissions Dolibarr respectées** — L'assistant ne peut accéder qu'aux données autorisées pour l'utilisateur connecté.
- **Contrôle granulaire** — Chaque utilisateur peut se voir attribuer des droits spécifiques : accès au chat, requêtes SQL, lecture seule ou lecture/écriture.
- **Clés API chiffrées** — Stockage sécurisé via le système de chiffrement natif de Dolibarr.
- **Traçabilité complète** — Toutes les interactions sont journalisées (utilisateur, date, durée, actions effectuées).

---

## Cas d'usage concrets

**👤 Le dirigeant**
*« Quel est mon CA ce trimestre comparé à l'an dernier ? »*
→ Obtenez vos KPI en 5 secondes au lieu de naviguer dans 3 menus.

**📋 La comptable**
*« Crée une facture d'acompte de 30% pour le devis PR2024-0045 »*
→ Dalfred retrouve le devis, calcule le montant et crée la facture.

**📦 Le commercial**
*« Quels clients n'ont pas commandé depuis 6 mois ? »*
→ Analyse instantanée pour relancer les clients dormants.

**🔧 Le technicien**
*« Quel est le stock de la pièce REF-4521 ? »*
→ Réponse immédiate sans quitter l'écran en cours.

---

## Providers IA compatibles

Dalfred vous laisse le choix de votre fournisseur d'intelligence artificielle :

| Provider | Type |
|---|---|
| **Anthropic** (Claude) | Cloud |
| **OpenAI** (GPT) | Cloud |
| **Google** (Gemini) | Cloud |
| **Mistral AI** | Cloud (serveurs EU) |
| **Ollama** | Auto-hébergé — vos données ne quittent jamais votre infrastructure |

Chaque utilisateur configure sa propre clé API, pour un contrôle total des coûts et de la confidentialité.

---

## Compatibilité

| | |
|---|---|
| **Dolibarr** | Version 18.0 et supérieures |
| **PHP** | 8.1+ |
| **Base de données** | MySQL / MariaDB |
| **Hébergement** | Tout serveur compatible Dolibarr |

L'installation est rapide : activez le module, configurez votre clé API, et le widget apparaît sur toutes les pages.

---

## Tarification

| | |
|---|---|
| **Prix** | **400 € HT** — achat unique |
| **Mises à jour** | 1 an inclus |
| **Abonnement** | Aucun |
| **Coût IA** | Selon votre provider (clé API utilisateur) |
| **Support** | Par email — inclus la première année |

> Pour un usage courant, le coût d'utilisation de l'IA représente quelques euros par mois.

---

## Fonctionnalités à venir

Dalfred évolue en permanence. Voici ce qui arrive dans les prochaines versions :

### 🎙️ Commande vocale (Speech-to-Text)
Parlez à Dalfred directement depuis votre navigateur. Dictez vos instructions au lieu de les taper — idéal en mobilité ou pour les utilisateurs terrain.

### 📎 Prise en charge de documents (PDF, images, texte)
Envoyez un document à Dalfred et laissez-le analyser le contenu : extraction de données depuis un bon de commande fournisseur, lecture d'une facture scannée, import d'informations depuis un PDF.

### ⏰ Tâches planifiées
Programmez des actions automatiques à heures fixes : génération de rapports hebdomadaires, alertes sur les factures en retard, relances automatiques — Dalfred travaille même quand vous n'êtes pas devant l'écran.

### ⚡ Actions déclenchées (triggers)
Associez des actions intelligentes aux événements Dolibarr : validation d'une facture, création d'une commande, changement de statut… Dalfred peut réagir automatiquement et exécuter des actions en arrière-plan.

### 🚀 Et bien plus encore
L'intelligence artificielle ouvre un champ immense de possibilités. Chaque retour utilisateur alimente notre feuille de route — les idées de demain viendront aussi de vous.

---

## À propos

**Dalfred** est développé par **E-dem SRL**, société belge spécialisée dans le développement de modules Dolibarr et l'intégration d'ERP.

- 🌐 **Site web** : [www.e-dem.com](https://www.e-dem.com)
- 📧 **Contact** : morgan@e-dem.com
- 📞 **Téléphone** : +32 474 62 22 18
- 🏪 **DoliStore** : [Fiche produit Dalfred](https://www.dolistore.com/product.php?id=2743&title=dalfred-assistant-ia-pour-dolibarr&l=fr)

---

*Version actuelle : 2.8.8 — Mars 2026*
