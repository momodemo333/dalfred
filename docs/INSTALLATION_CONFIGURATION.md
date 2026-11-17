# Dalfred - Guide d'installation et de configuration

Ce guide résume l'installation et la mise en route de Dalfred (version 2.35.1). Le détail de chaque réglage est décrit dans la documentation en ligne : https://www.e-dem.com (Docs > Dalfred).

## Pré-requis

- Dolibarr 16 ou supérieur
- PHP 8.1 ou supérieur, avec les extensions `curl`, `json`, `mbstring` et `openssl`
- Module **API REST** activé dans Dolibarr (Accueil > Configuration > Modules > API REST)
- Une clé API d'un fournisseur IA (Anthropic, OpenAI, Mistral, Google Gemini) ou un serveur Ollama auto-hébergé

Toutes les bibliothèques nécessaires sont livrées dans l'archive ZIP : **aucune installation Composer n'est nécessaire**. Elles sont isolées (espaces de noms préfixés `DalfredVendor\` et `DalfredMcpVendor\`) : un autre module ou le cœur de Dolibarr qui charge sa propre copie d'une même bibliothèque (psr/log, guzzle…) n'entre pas en conflit avec Dalfred. L'onglet **Diagnostic** permet de le vérifier.

## Installation

1. Décompresser l'archive ZIP (DoliStore ou https://www.e-dem.com) dans le répertoire `htdocs/custom/` de votre installation Dolibarr ; le dossier final est `htdocs/custom/dalfred/`
2. Se connecter à Dolibarr en tant qu'administrateur
3. Aller dans **Accueil > Configuration > Modules/Applications**
4. Rechercher "Dalfred" et activer le module
5. Le module crée automatiquement les tables nécessaires dans la base de données

Une fois activé, le widget flottant apparaît dans la barre supérieure et un menu **Dalfred** est disponible (Chat, Conversations, Mes fichiers, Mémoire, Smart Queries).

## Mise à jour

Décompresser la nouvelle version par-dessus l'ancienne. Les migrations de la base sont appliquées automatiquement au chargement de la page suivante. L'onglet **Maintenance** affiche la version des fichiers et celle de la base ; le bouton **Vérifier et mettre à jour la base de données** lance les migrations à la demande.

## Configuration

La configuration s'ouvre depuis **Accueil > Configuration > Modules/Applications**, en cliquant sur l'icône de configuration de Dalfred. Elle comporte 13 onglets :

| Onglet | Contenu |
|--------|---------|
| **Configuration générale** | Fenêtre de contexte (150 000 tokens par défaut), Instructions supplémentaires ajoutées au prompt de base (non modifiable ; bouton « Voir le prompt complet »), pièces jointes, mode debug, journalisation des conversations |
| **Personnalisation** | Apparence de l'assistant (white-label) |
| **Configuration IA** | Fournisseur, modèle, clé API (ou URL Ollama), tokens maximum, timeout HTTP des fournisseurs cloud, nombre max d'appels par outil et par tour, seuil de troncature des résultats d'outils ; bouton « Tester la connexion » |
| **Permissions** | Par utilisateur : droit d'accès, clé API, accès MySQL, état « Prêt » ; interrupteurs du MySQL Toolkit (schéma, lecture, écriture), Smart Queries, génération de fichiers |
| **Logs d'activité** | Journal des événements (utilisateur, type, outil, durée, détails), purge |
| **Mémoire** | Base de connaissances |
| **Usage tokens** | Consommation de tokens |
| **Maintenance** | Version des fichiers et de la base, vérification et mise à jour de la base, état des tables |
| **Diagnostic** | Pile technique, isolation des dépendances, conflits de bibliothèques, dernier échec du moteur MCP, fichiers obsolètes, rapport pour le support |
| **Accès MCP externe** | Accès d'un client IA externe (OAuth) au moteur MCP de Dalfred, accès accordés et révocation |
| **Accès SQL MCP** | Requêtes SQL en lecture seule pour les clients MCP externes |
| **Activité MCP** | Journal des appels MCP externes, limite d'appels, alerte |
| **À propos** | Version et informations |

Le choix du fournisseur et du modèle se fait dans **Configuration IA** : la liste propose les modèles actuels de chaque fournisseur, et l'entrée « Autre (modèle personnalisé) » permet de saisir un autre identifiant.

## Clés API utilisateur

Chaque utilisateur qui utilise Dalfred doit avoir une clé API Dolibarr : l'assistant exécute les actions avec les permissions de cet utilisateur, via l'API REST. L'onglet **Permissions** indique, pour chaque utilisateur, s'il est « Prêt ».

1. Aller dans **Accueil > Utilisateurs & Groupes > Liste des utilisateurs**
2. Ouvrir la fiche de l'utilisateur et passer en modification
3. Remplir le champ **Clé API** (ou en générer une via le bouton dédié)
4. Sauvegarder

L'assistant ne peut pas effectuer d'actions que l'utilisateur n'est pas autorisé à faire.

### Permissions du module

| Permission | Description |
|-----------|-------------|
| **Utiliser l'assistant Dalfred** | Accéder à l'assistant et envoyer des messages |
| **Gérer ses propres conversations** | Créer, renommer et supprimer ses conversations |
| **Voir toutes les conversations** | Voir les conversations de tous les utilisateurs |
| **Configurer le module Dalfred** | Accéder aux pages de configuration |
| **Utiliser les Smart Queries** | Exécuter les requêtes SQL sauvegardées |
| **Exécuter des requêtes SQL en lecture seule via MCP** | Accès SQL pour les clients MCP externes (voir l'onglet Accès SQL MCP) |

## Dépannage

- **Commencer par l'onglet Diagnostic** : il signale un conflit de bibliothèques, un échec du moteur MCP ou des fichiers obsolètes, et fournit un rapport à joindre à une demande de support (sans aucun secret).
- **Onglet Maintenance** : si la version des fichiers et celle de la base diffèrent, cliquer sur **Vérifier et mettre à jour la base de données**.
- **Après une mise à jour par décompression, rien ne change** : par précaution, redémarrer PHP (PHP-FPM, ou Apache si PHP tourne en module), ou le demander à l'hébergeur. Le cache d'opcodes de PHP (opcache) peut continuer à servir l'ancien code.
- **« API key not configured »** : la clé du fournisseur IA manque ; la renseigner dans Configuration IA, puis « Tester la connexion ».
- **L'assistant ne peut pas agir sur les données** : vérifier que le module API REST est actif et que l'utilisateur a une clé API (onglet Permissions).

### Logs

Avec le **Mode debug** activé, les logs détaillés sont écrits dans `documents/dolibarr.log` avec le préfixe `[DALFRED]` :

```bash
grep "\[DALFRED\]" documents/dolibarr.log
tail -f documents/dolibarr.log | grep "\[DALFRED\]"
```

## Support

support@e-dem.com — https://www.e-dem.com
