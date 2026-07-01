# Dalfred - Guide d'installation et de configuration

## Pré-requis

- Dolibarr 16.x ou supérieur
- PHP 8.1 ou supérieur
- Module **API REST** activé dans Dolibarr (Accueil > Configuration > Modules > API REST)
- Une clé API d'un fournisseur IA (Anthropic, OpenAI, Mistral, Google Gemini ou Ollama)

## Installation

1. Copier le dossier `dalfred/` dans le répertoire `htdocs/custom/` de votre installation Dolibarr
2. Se connecter à Dolibarr en tant qu'administrateur
3. Aller dans **Accueil > Configuration > Modules/Applications**
4. Rechercher "Dalfred" et activer le module
5. Le module créera automatiquement les tables nécessaires dans la base de données

## Configuration

Après activation, accéder à la configuration via **Accueil > Configuration > Modules > Dalfred > Configuration**.

### Onglet "Configuration générale"

#### Serveur MCP

| Paramètre | Description |
|-----------|-------------|
| **Activer le serveur MCP** | Active l'intégration avec l'API Dolibarr via le protocole MCP. Permet à l'assistant d'exécuter des actions (créer des factures, consulter des tiers, etc.). Sans cette option, l'assistant ne peut que répondre à des questions générales sans accéder aux données Dolibarr. |

#### Configuration du chat

| Paramètre | Valeur par défaut | Description |
|-----------|------------------|-------------|
| **Fenêtre de contexte** | 150 000 tokens | Taille maximale de l'historique de conversation envoyé à l'IA. Une valeur plus élevée permet des conversations plus longues mais augmente le coût par requête. |
| **Exiger clé API utilisateur** | Activé | Chaque utilisateur doit posséder sa propre clé API Dolibarr pour utiliser Dalfred. Cela garantit que les actions exécutées par l'assistant respectent les permissions de l'utilisateur. **Recommandé : laisser activé.** |

#### Prompt système personnalisé

Permet de personnaliser les instructions données à l'assistant IA. Laisser vide pour utiliser le prompt par défaut. Le contexte utilisateur (nom, rôle) et le contexte de page sont toujours ajoutés automatiquement.

Exemples d'utilisation :
- Ajouter des instructions spécifiques à votre entreprise
- Définir le ton de l'assistant (formel, informel)
- Restreindre les actions possibles

#### Configuration debug

| Paramètre | Valeur par défaut | Description |
|-----------|------------------|-------------|
| **Mode debug** | Désactivé | Active les logs détaillés pour le débogage. Les logs sont écrits dans le fichier `documents/dolibarr.log` avec le préfixe `[DALFRED]`. |
| **Logger les conversations** | Activé | Enregistre les conversations dans les logs d'activité du module (onglet "Logs d'activité"). Utile pour l'analyse et le suivi de l'utilisation. |

### Onglet "Configuration IA"

Permet de sélectionner le fournisseur IA et le modèle à utiliser.

| Fournisseur | Modèles disponibles | Clé API requise |
|-------------|-------------------|----------------|
| **Anthropic (Claude)** | Claude Sonnet 4, Claude Opus 4, Claude 3.5 Sonnet, Claude 3.5 Haiku | Oui (clé API Anthropic) |
| **OpenAI (GPT)** | GPT-4o, GPT-4o Mini, GPT-4 Turbo, o1-mini, o3-mini | Oui (clé API OpenAI) |
| **Mistral AI** | Mistral Large, Mistral Small, Mistral Nemo | Oui (clé API Mistral) |
| **Google Gemini** | Gemini 2.0 Flash, Gemini 1.5 Pro, Gemini 1.5 Flash | Oui (clé API Google) |
| **Ollama (Self-hosted)** | Tout modèle installé localement | Non (URL locale) |

### Onglet "Permissions Toolkits"

Configure les outils (toolkits) que l'assistant peut utiliser pour interagir avec Dolibarr.

## Configuration des clés API utilisateur

**Important** : Chaque utilisateur qui souhaite utiliser Dalfred doit avoir une clé API Dolibarr configurée. Cette clé permet à l'assistant d'exécuter des actions avec les permissions de l'utilisateur.

### Procédure de configuration

1. Aller dans **Accueil > Utilisateurs & Groupes > Liste des utilisateurs**
2. Cliquer sur l'utilisateur concerné
3. Dans l'onglet de l'utilisateur, cliquer sur l'icône d'édition (crayon)
4. Remplir le champ **Clé API** (ou en générer une via le bouton dédié)
5. Sauvegarder

### Points importants

- La clé API doit être unique par utilisateur
- Les permissions Dolibarr de l'utilisateur sont respectées : l'assistant ne peut pas effectuer des actions que l'utilisateur n'est pas autorisé à faire
- Si l'option "Exiger clé API utilisateur" est activée (recommandé), un utilisateur sans clé API ne pourra pas utiliser Dalfred
- Le module API REST de Dolibarr doit être activé pour que les clés API fonctionnent

### Permissions du module

Le module définit les permissions suivantes, à attribuer aux utilisateurs ou groupes :

| Permission | Description |
|-----------|-------------|
| **Utiliser Dalfred** | Permet d'accéder à l'assistant et d'envoyer des messages |
| **Gérer ses conversations** | Permet de créer, renommer et supprimer ses propres conversations |
| **Voir toutes les conversations** | Permet de voir les conversations de tous les utilisateurs (admin) |
| **Configurer Dalfred** | Permet d'accéder aux pages de configuration du module |

## Diagnostic et dépannage

### Vérifier les logs

```bash
# Tous les logs Dalfred
grep "\[DALFRED\]" documents/dolibarr.log

# Erreurs uniquement
grep "\[DALFRED\] ERROR" documents/dolibarr.log

# Suivi en temps réel
tail -f documents/dolibarr.log | grep "\[DALFRED\]"
```

### Problèmes courants

| Problème | Cause probable | Solution |
|---------|---------------|---------|
| "API key not configured" | Clé API du fournisseur IA manquante | Configurer la clé dans Configuration IA |
| "User API key required" | L'utilisateur n'a pas de clé API Dolibarr | Créer une clé API pour l'utilisateur (voir section ci-dessus) |
| L'assistant ne peut pas exécuter d'actions | MCP désactivé ou module API REST inactif | Vérifier que le MCP est activé et que le module API REST est activé |
| Réponses tronquées | Fenêtre de contexte trop petite | Augmenter la valeur dans Configuration générale |
